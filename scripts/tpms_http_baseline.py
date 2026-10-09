#!/usr/bin/env python3
"""Small dependency-free HTTP baseline runner for safe/read-heavy TPMS endpoints.

Use this mainly for heartbeat/state/context/monitoring. Production writers such as
cycle-start/cycle-stop should be benchmarked with the TPMS simulator so event_id
and production sequencing stay valid.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import json
import math
import statistics
import time
import urllib.error
import urllib.request


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    values = sorted(values)
    idx = max(0, min(len(values) - 1, math.ceil(p * len(values)) - 1))
    return values[idx]


def one_request(url: str, token: str, payload: bytes, timeout: float) -> dict:
    req = urllib.request.Request(
        url,
        data=payload,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-TPMS-Key": token,
            "User-Agent": "tpms-phase0-baseline/1.0",
        },
    )
    started = time.perf_counter()
    status = 0
    headers = {}
    error = None
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            status = response.status
            headers = dict(response.headers.items())
            response.read()
    except urllib.error.HTTPError as exc:
        status = exc.code
        headers = dict(exc.headers.items()) if exc.headers else {}
        exc.read()
        error = f"HTTP {exc.code}"
    except Exception as exc:  # noqa: BLE001 - CLI diagnostic tool
        error = str(exc)
    elapsed_ms = (time.perf_counter() - started) * 1000
    return {
        "status": status,
        "elapsed_ms": elapsed_ms,
        "server_ms": _float_header(headers, "X-TPMS-Perf-Ms"),
        "queries": _float_header(headers, "X-TPMS-Perf-Queries"),
        "sql_ms": _float_header(headers, "X-TPMS-Perf-Sql-Ms"),
        "error": error,
    }


def _float_header(headers: dict, name: str):
    value = next((v for k, v in headers.items() if k.lower() == name.lower()), None)
    if value is None:
        return None
    try:
        return float(value)
    except ValueError:
        return None


def summary(values: list[float]) -> str:
    if not values:
        return "N/A"
    return (
        f"avg={statistics.fmean(values):.2f} "
        f"p50={percentile(values, .50):.2f} "
        f"p95={percentile(values, .95):.2f} "
        f"p99={percentile(values, .99):.2f} "
        f"max={max(values):.2f}"
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--token", required=True)
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--json", help="Inline JSON object")
    source.add_argument("--json-file", help="Path to JSON body")
    parser.add_argument("--requests", type=int, default=200)
    parser.add_argument("--concurrency", type=int, default=10)
    parser.add_argument("--timeout", type=float, default=10.0)
    args = parser.parse_args()

    if args.requests < 1 or args.concurrency < 1:
        parser.error("--requests and --concurrency must be >= 1")

    if args.json_file:
        with open(args.json_file, "r", encoding="utf-8") as fh:
            body = json.load(fh)
    else:
        body = json.loads(args.json)

    if not isinstance(body, dict):
        parser.error("JSON payload must be an object")

    payload = json.dumps(body, separators=(",", ":")).encode("utf-8")
    started = time.perf_counter()

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futures = [
            pool.submit(one_request, args.url, args.token, payload, args.timeout)
            for _ in range(args.requests)
        ]
        results = [future.result() for future in concurrent.futures.as_completed(futures)]

    wall = time.perf_counter() - started
    client_ms = [r["elapsed_ms"] for r in results]
    server_ms = [r["server_ms"] for r in results if r["server_ms"] is not None]
    queries = [r["queries"] for r in results if r["queries"] is not None]
    sql_ms = [r["sql_ms"] for r in results if r["sql_ms"] is not None]
    errors = [r for r in results if r["error"] or not (200 <= r["status"] < 400)]
    status_counts: dict[int, int] = {}
    for row in results:
        status_counts[row["status"]] = status_counts.get(row["status"], 0) + 1

    print("TPMS Phase 0 HTTP Baseline")
    print(f"URL         : {args.url}")
    print(f"Requests    : {args.requests}")
    print(f"Concurrency : {args.concurrency}")
    print(f"Wall time   : {wall:.2f}s")
    print(f"Throughput  : {args.requests / wall:.2f} req/s" if wall else "Throughput  : N/A")
    print(f"Status      : {dict(sorted(status_counts.items()))}")
    print(f"Errors      : {len(errors)} ({len(errors) / len(results) * 100:.2f}%)")
    print(f"Client ms   : {summary(client_ms)}")
    print(f"Server ms   : {summary(server_ms)}")
    print(f"SQL ms      : {summary(sql_ms)}")
    print(f"Queries     : {summary(queries)}")

    if errors:
        print("First errors:")
        for row in errors[:5]:
            print(f"  status={row['status']} error={row['error']}")

    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
