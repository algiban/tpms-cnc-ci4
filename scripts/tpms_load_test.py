#!/usr/bin/env python3
"""TPMS Phase 8 load/stress test harness.

Uses only Python standard library. It can run read-heavy polling tests or a
stateful active-production workload against registered TPMS devices.

IMPORTANT: profiles containing cycles WRITE production data. Use a dedicated
staging/test database and pass --allow-writes explicitly.
"""
from __future__ import annotations

import argparse
import csv
import http.client
import json
import math
import os
import random
import statistics
import sys
import threading
import time
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit


@dataclass
class Sample:
    stage: int
    device: str
    endpoint: str
    status: int
    elapsed_ms: float
    ok: bool
    error: str = ""


@dataclass
class DeviceState:
    production_id: int | None = None
    detail_id: int | None = None
    counter_epoch: str | None = None
    counter_total: int = 0
    execution_state: str = "idle"
    open_cycle_id: int | None = None
    should_stop: bool = False


@dataclass
class Device:
    mac: str
    token: str
    source: dict[str, Any]
    state: DeviceState = field(default_factory=DeviceState)


class HttpClient:
    def __init__(self, base_url: str, timeout: float):
        u = urlsplit(base_url.rstrip("/"))
        if u.scheme not in ("http", "https") or not u.hostname:
            raise ValueError("--base-url harus berupa http://host atau https://host")
        self.scheme = u.scheme
        self.host = u.hostname
        self.port = u.port or (443 if u.scheme == "https" else 80)
        self.prefix = u.path.rstrip("/")
        self.timeout = timeout
        self.conn: http.client.HTTPConnection | None = None

    def _connect(self) -> http.client.HTTPConnection:
        if self.conn is None:
            cls = http.client.HTTPSConnection if self.scheme == "https" else http.client.HTTPConnection
            self.conn = cls(self.host, self.port, timeout=self.timeout)
        return self.conn

    def close(self) -> None:
        if self.conn is not None:
            try:
                self.conn.close()
            except Exception:
                pass
            self.conn = None

    def post(self, path: str, token: str, payload: dict[str, Any]) -> tuple[int, dict[str, Any], float, str]:
        body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-TPMS-Key": token,
            "Connection": "keep-alive",
            "User-Agent": "TPMS-Phase8-LoadTest/1.0",
        }
        started = time.perf_counter()
        error = ""
        try:
            conn = self._connect()
            conn.request("POST", self.prefix + path, body=body, headers=headers)
            resp = conn.getresponse()
            raw = resp.read()
            elapsed = (time.perf_counter() - started) * 1000.0
            try:
                parsed = json.loads(raw.decode("utf-8")) if raw else {}
            except Exception:
                parsed = {"raw": raw[:500].decode("utf-8", "replace")}
            return resp.status, parsed, elapsed, error
        except Exception as exc:
            elapsed = (time.perf_counter() - started) * 1000.0
            error = f"{type(exc).__name__}: {exc}"
            self.close()
            return 0, {}, elapsed, error


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    rank = max(0, min(len(ordered) - 1, math.ceil(p * len(ordered)) - 1))
    return float(ordered[rank])


def summarize(samples: list[Sample], duration_s: float) -> dict[str, Any]:
    result: dict[str, Any] = {
        "requests": len(samples),
        "duration_s": round(duration_s, 3),
        "requests_per_s": round(len(samples) / duration_s, 3) if duration_s > 0 else 0.0,
        "http_4xx": sum(1 for s in samples if 400 <= s.status < 500),
        "http_5xx": sum(1 for s in samples if s.status >= 500),
        "transport_errors": sum(1 for s in samples if s.status == 0),
        "ok": sum(1 for s in samples if s.ok),
        "endpoints": {},
    }
    groups: dict[str, list[Sample]] = {}
    for sample in samples:
        groups.setdefault(sample.endpoint, []).append(sample)
    for endpoint, rows in sorted(groups.items()):
        vals = [r.elapsed_ms for r in rows]
        result["endpoints"][endpoint] = {
            "n": len(rows),
            "avg_ms": round(statistics.fmean(vals), 3) if vals else 0.0,
            "p50_ms": round(percentile(vals, 0.50), 3),
            "p95_ms": round(percentile(vals, 0.95), 3),
            "p99_ms": round(percentile(vals, 0.99), 3),
            "max_ms": round(max(vals), 3) if vals else 0.0,
            "4xx": sum(1 for r in rows if 400 <= r.status < 500),
            "5xx": sum(1 for r in rows if r.status >= 500),
            "transport_errors": sum(1 for r in rows if r.status == 0),
        }
    return result


def read_manifest(path: str) -> list[Device]:
    with open(path, "r", encoding="utf-8") as fh:
        raw = json.load(fh)
    rows = raw.get("devices", raw) if isinstance(raw, dict) else raw
    if not isinstance(rows, list):
        raise ValueError("Manifest harus array device atau object dengan key 'devices'.")
    devices: list[Device] = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        mac = str(row.get("mac_address", "")).upper().strip()
        token = str(row.get("token", "")).strip()
        if not mac or not token:
            continue
        d = Device(mac=mac, token=token, source=row)
        if row.get("production_id") is not None:
            d.state.production_id = int(row["production_id"])
        if row.get("production_shift_detail_id") is not None:
            d.state.detail_id = int(row["production_shift_detail_id"])
        if row.get("counter_epoch"):
            d.state.counter_epoch = str(row["counter_epoch"])
        d.state.counter_total = int(row.get("counter_total") or 0)
        devices.append(d)
    if not devices:
        raise ValueError("Manifest tidak memiliki device valid (mac_address + token).")
    return devices


def update_state(device: Device, data: dict[str, Any]) -> None:
    if not isinstance(data, dict):
        return
    device.state.production_id = int(data["production_id"]) if data.get("production_id") is not None else None
    device.state.detail_id = int(data["production_shift_detail_id"]) if data.get("production_shift_detail_id") is not None else None
    device.state.counter_epoch = str(data["counter_epoch"]) if data.get("counter_epoch") else None
    counter = data.get("counter_expected_total", data.get("shift_actual_qty", data.get("actual_qty", 0)))
    try:
        device.state.counter_total = int(counter or 0)
    except Exception:
        pass
    device.state.execution_state = str(data.get("execution_state", data.get("state", "idle")))
    device.state.open_cycle_id = int(data["open_cycle_id"]) if data.get("open_cycle_id") is not None else None
    device.state.should_stop = bool(data.get("should_stop", False))


def event_id(prefix: str, mac: str, action: str) -> str:
    suffix = mac.replace(":", "")[-8:]
    return f"p8:{prefix}:{suffix}:{action}:{time.time_ns()}:{uuid.uuid4().hex[:6]}"


def request_and_record(
    client: HttpClient,
    stage: int,
    device: Device,
    path: str,
    payload: dict[str, Any],
    endpoint_name: str,
    samples: list[Sample],
) -> dict[str, Any] | None:
    status, body, elapsed, transport_error = client.post(path, device.token, payload)
    api_ok = status == 200 and isinstance(body, dict) and body.get("ok") is True
    error = transport_error
    if not error and not api_ok:
        if isinstance(body, dict):
            error = str(body.get("message", ""))[:300]
    samples.append(Sample(stage, device.mac, endpoint_name, status, elapsed, api_ok, error))
    if api_ok:
        data = body.get("data")
        return data if isinstance(data, dict) else body
    return None


def device_worker(
    stage: int,
    device: Device,
    base_url: str,
    timeout: float,
    stop_at: float,
    profile: str,
    state_interval: float,
    heartbeat_interval: float,
    cycle_period: float,
    machine_time: float,
    jitter: float,
    allow_writes: bool,
    seed: int,
    cancel_event: threading.Event,
) -> list[Sample]:
    rnd = random.Random(seed)
    samples: list[Sample] = []
    client = HttpClient(base_url, timeout)
    try:
        # Bootstrap authoritative state from server.
        data = request_and_record(
            client, stage, device, "/api/tpms/production/state",
            {"mac_address": device.mac}, "state", samples,
        )
        if data is not None:
            update_state(device, data)

        now = time.monotonic()
        offset = rnd.uniform(0.0, jitter) if jitter > 0 else 0.0
        next_state = now + offset
        next_heartbeat = now + offset + min(heartbeat_interval, 0.5)
        next_cycle = now + offset + min(cycle_period, 0.5)
        pending_cycle_stop_at: float | None = None
        pending_cycle_id: int | None = None
        prefix = f"s{stage}"

        while time.monotonic() < stop_at and not cancel_event.is_set():
            now = time.monotonic()
            did_work = False

            if state_interval > 0 and now >= next_state:
                data = request_and_record(
                    client, stage, device, "/api/tpms/production/state",
                    {"mac_address": device.mac}, "state", samples,
                )
                if data is not None:
                    update_state(device, data)
                next_state = now + state_interval
                did_work = True

            if heartbeat_interval > 0 and now >= next_heartbeat:
                hb_status = "idle"
                if device.state.execution_state == "running":
                    hb_status = "run"
                elif device.state.execution_state == "paused":
                    hb_status = "paused"
                elif device.state.execution_state in ("service_required", "alarm"):
                    hb_status = "alarm"
                request_and_record(
                    client, stage, device, "/api/tpms/heartbeat",
                    {"mac_address": device.mac, "status": hb_status}, "heartbeat", samples,
                )
                next_heartbeat = now + heartbeat_interval
                did_work = True

            wants_cycles = profile in ("active", "mixed", "burst")
            can_cycle = (
                allow_writes
                and wants_cycles
                and device.state.production_id is not None
                and device.state.detail_id is not None
                and bool(device.state.counter_epoch)
                and device.state.execution_state == "running"
                and not device.state.should_stop
            )

            if pending_cycle_stop_at is not None and now >= pending_cycle_stop_at:
                if can_cycle:
                    payload = {
                        "mac_address": device.mac,
                        "event_id": event_id(prefix, device.mac, "stop"),
                        "production_id": device.state.production_id,
                        "production_shift_detail_id": device.state.detail_id,
                        "counter_epoch": device.state.counter_epoch,
                        "trigger_ms": int(time.monotonic() * 1000),
                    }
                    if pending_cycle_id is not None:
                        payload["cycle_id"] = pending_cycle_id
                    data = request_and_record(
                        client, stage, device, "/api/tpms/production/cycle-stop",
                        payload, "cycle-stop", samples,
                    )
                    if data is not None:
                        update_state(device, data)
                pending_cycle_stop_at = None
                pending_cycle_id = None
                next_cycle = now + max(0.01, cycle_period - machine_time)
                did_work = True

            elif pending_cycle_stop_at is None and can_cycle and now >= next_cycle:
                if device.state.open_cycle_id is None:
                    payload = {
                        "mac_address": device.mac,
                        "event_id": event_id(prefix, device.mac, "start"),
                        "production_id": device.state.production_id,
                        "production_shift_detail_id": device.state.detail_id,
                        "counter_epoch": device.state.counter_epoch,
                        "trigger_ms": int(time.monotonic() * 1000),
                    }
                    data = request_and_record(
                        client, stage, device, "/api/tpms/production/cycle-start",
                        payload, "cycle-start", samples,
                    )
                    if data is not None:
                        update_state(device, data)
                        cycle = data.get("cycle") if isinstance(data, dict) else None
                        if isinstance(cycle, dict) and cycle.get("id") is not None:
                            pending_cycle_id = int(cycle["id"])
                            device.state.open_cycle_id = pending_cycle_id
                            pending_cycle_stop_at = now + machine_time
                        else:
                            next_cycle = now + cycle_period
                else:
                    # Do not mutate an unknown pre-existing open cycle. State polling
                    # keeps reporting it so the test clearly exposes dirty fixtures.
                    next_cycle = now + cycle_period
                did_work = True

            if not did_work:
                candidates = [stop_at]
                if state_interval > 0:
                    candidates.append(next_state)
                if heartbeat_interval > 0:
                    candidates.append(next_heartbeat)
                if pending_cycle_stop_at is not None:
                    candidates.append(pending_cycle_stop_at)
                elif profile in ("active", "mixed", "burst"):
                    candidates.append(next_cycle)
                sleep_for = max(0.002, min(0.05, min(candidates) - time.monotonic()))
                time.sleep(sleep_for)
    finally:
        client.close()
    return samples


def print_stage(stage: int, summary: dict[str, Any]) -> None:
    print(f"\n=== Stage {stage} devices ===")
    print(
        f"requests={summary['requests']} rps={summary['requests_per_s']} "
        f"4xx={summary['http_4xx']} 5xx={summary['http_5xx']} transport={summary['transport_errors']}"
    )
    header = f"{'endpoint':14} {'n':>7} {'avg':>9} {'p50':>9} {'p95':>9} {'p99':>9} {'max':>9} {'4xx':>5} {'5xx':>5}"
    print(header)
    print("-" * len(header))
    for endpoint, row in summary["endpoints"].items():
        print(
            f"{endpoint:14} {row['n']:7d} {row['avg_ms']:9.2f} {row['p50_ms']:9.2f} "
            f"{row['p95_ms']:9.2f} {row['p99_ms']:9.2f} {row['max_ms']:9.2f} "
            f"{row['4xx']:5d} {row['5xx']:5d}"
        )


def write_csv(path: str, samples: list[Sample]) -> None:
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    with p.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh)
        writer.writerow(["stage", "device", "endpoint", "status", "elapsed_ms", "ok", "error"])
        for s in samples:
            writer.writerow([s.stage, s.device, s.endpoint, s.status, f"{s.elapsed_ms:.3f}", int(s.ok), s.error])


def main() -> int:
    parser = argparse.ArgumentParser(description="TPMS Phase 8 realistic load/stress test")
    parser.add_argument("--base-url", required=True, help="Contoh http://127.0.0.1 atau https://tpms.example.com")
    parser.add_argument("--manifest", required=True, help="JSON dari php spark tpms:stress-manifest")
    parser.add_argument("--stages", default="10,25,50,100,150,200", help="Jumlah device per stage")
    parser.add_argument("--duration", type=float, default=60.0, help="Durasi setiap stage dalam detik")
    parser.add_argument("--profile", choices=["polling", "active", "mixed", "burst"], default="polling")
    parser.add_argument("--state-interval", type=float, default=2.0)
    parser.add_argument("--heartbeat-interval", type=float, default=15.0)
    parser.add_argument("--cycle-period", type=float, default=57.6, help="Jarak cycle-start ke cycle-start berikutnya per device")
    parser.add_argument("--machine-time", type=float, default=0.35, help="Delay cycle-start -> cycle-stop dalam detik")
    parser.add_argument("--jitter", type=float, default=1.0, help="Stagger awal device; 0 mensimulasikan burst sinkron")
    parser.add_argument("--timeout", type=float, default=10.0)
    parser.add_argument("--cooldown", type=float, default=5.0, help="Jeda antartahap")
    parser.add_argument("--allow-writes", action="store_true", help="WAJIB untuk profile active/mixed/burst")
    parser.add_argument("--report", default="writable/stress/phase8-report.json")
    parser.add_argument("--raw-csv", default="", help="Opsional simpan semua request sample ke CSV")
    parser.add_argument("--seed", type=int, default=260803)
    args = parser.parse_args()

    if args.duration <= 0 or args.timeout <= 0:
        parser.error("--duration dan --timeout harus > 0")
    if args.state_interval < 0 or args.heartbeat_interval < 0 or args.cycle_period <= 0 or args.machine_time <= 0:
        parser.error("interval harus valid")
    if args.profile in ("active", "mixed", "burst") and not args.allow_writes:
        parser.error("profile active/mixed/burst MENULIS data produksi; tambahkan --allow-writes pada DB test/staging")

    devices = read_manifest(args.manifest)
    stages: list[int] = []
    for raw in args.stages.split(","):
        raw = raw.strip()
        if raw:
            n = int(raw)
            if n <= 0:
                parser.error("nilai --stages harus > 0")
            stages.append(n)
    if not stages:
        parser.error("--stages kosong")
    if max(stages) > len(devices):
        parser.error(f"manifest hanya punya {len(devices)} device, tetapi stage meminta {max(stages)}")

    if args.profile in ("active", "mixed", "burst"):
        active_hint = sum(1 for d in devices if d.source.get("production_id") is not None)
        if active_hint < max(stages):
            print(
                f"WARNING: manifest hanya menandai {active_hint} active sessions untuk stage maksimum {max(stages)}. "
                "Script akan bootstrap /state; device idle tetap hanya polling/heartbeat.",
                file=sys.stderr,
            )

    print("TPMS Phase 8 Load Test")
    print(f"base_url={args.base_url} profile={args.profile} stages={stages} duration={args.duration}s")
    print(f"state={args.state_interval}s heartbeat={args.heartbeat_interval}s cycle={args.cycle_period}s machine={args.machine_time}s jitter={args.jitter}s")
    if args.profile in ("active", "mixed", "burst"):
        print("WRITE MODE ENABLED: cycle-start/cycle-stop akan menambah production/tool lifetime pada DB target.")

    all_samples: list[Sample] = []
    stage_reports: list[dict[str, Any]] = []

    for index, stage in enumerate(stages):
        subset = devices[:stage]
        stage_start = time.monotonic()
        stop_at = stage_start + args.duration
        threads: list[threading.Thread] = []
        results: list[list[Sample] | None] = [None] * stage
        cancel_event = threading.Event()

        def runner(i: int, dev: Device) -> None:
            results[i] = device_worker(
                stage=stage,
                device=dev,
                base_url=args.base_url,
                timeout=args.timeout,
                stop_at=stop_at,
                profile=args.profile,
                state_interval=args.state_interval,
                heartbeat_interval=args.heartbeat_interval,
                cycle_period=(2.0 if args.profile == "burst" and args.cycle_period == 57.6 else args.cycle_period),
                machine_time=args.machine_time,
                jitter=(0.0 if args.profile == "burst" else args.jitter),
                allow_writes=args.allow_writes,
                seed=args.seed + stage * 1000 + i,
                cancel_event=cancel_event,
            )

        for i, dev in enumerate(subset):
            t = threading.Thread(target=runner, args=(i, dev), name=f"tpms-{i+1}", daemon=True)
            threads.append(t)
            t.start()
        # Workers are intentionally alive for the full stage duration.  The
        # previous deadline used only request timeout + 15s, so any stage longer
        # than that (for example --duration 30 with --timeout 10) was falsely
        # reported as a stuck worker while it was still inside its valid run.
        # Allow the stage to reach stop_at, then give an in-flight HTTP request
        # up to --timeout plus a small shutdown grace period to return.
        join_deadline = stop_at + args.timeout + 5.0
        for t in threads:
            remaining = max(0.0, join_deadline - time.monotonic())
            t.join(remaining)
        stuck = [t.name for t in threads if t.is_alive()]
        if stuck:
            cancel_event.set()
            for t in threads:
                if t.is_alive():
                    t.join(args.timeout + 1.0)
            print(f"ERROR: {len(stuck)} worker belum selesai setelah timeout: {stuck[:10]}", file=sys.stderr)
            return 3

        stage_samples: list[Sample] = []
        for rows in results:
            if rows:
                stage_samples.extend(rows)
        elapsed = max(0.001, time.monotonic() - stage_start)
        all_samples.extend(stage_samples)
        summary = summarize(stage_samples, elapsed)
        summary["devices"] = stage
        summary["profile"] = args.profile
        stage_reports.append(summary)
        print_stage(stage, summary)

        if index != len(stages) - 1 and args.cooldown > 0:
            time.sleep(args.cooldown)

    report = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "base_url": args.base_url,
        "profile": args.profile,
        "settings": {
            "stages": stages,
            "duration_s": args.duration,
            "state_interval_s": args.state_interval,
            "heartbeat_interval_s": args.heartbeat_interval,
            "cycle_period_s": args.cycle_period,
            "machine_time_s": args.machine_time,
            "jitter_s": args.jitter,
            "timeout_s": args.timeout,
        },
        "stages": stage_reports,
    }
    report_path = Path(args.report)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    if args.raw_csv:
        write_csv(args.raw_csv, all_samples)

    total_5xx = sum(s.status >= 500 for s in all_samples)
    total_transport = sum(s.status == 0 for s in all_samples)
    print(f"\nReport: {report_path}")
    if args.raw_csv:
        print(f"Raw CSV: {args.raw_csv}")
    print(f"Total samples={len(all_samples)} 5xx={total_5xx} transport_errors={total_transport}")

    return 2 if total_5xx > 0 or total_transport > 0 else 0


if __name__ == "__main__":
    raise SystemExit(main())
