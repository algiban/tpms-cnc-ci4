# FIX-08 — Dynamic Shift Target Planning

## Tujuan

`productions.target_qty` tetap menjadi target GOOD keseluruhan. Production baru menyimpan dua parameter planning tambahan:

- `target_duration_days`: lama target produksi dalam hari.
- `shifts_per_day`: jumlah shift produksi per hari, valid 1–3.

Tidak ada tabel planning baru dan master `parts` tidak diubah.

## Formula target shift

Saat shift baru dibuat:

```text
total_planned_shifts = target_duration_days × shifts_per_day
remaining_good       = production.target_qty - production.good_qty
remaining_shifts     = total_planned_shifts - jumlah shift detail yang sudah dibuat
shift_target         = ceil(remaining_good / max(1, remaining_shifts))
```

Target shift selalu berbasis GOOD quantity, bukan gross.

### Contoh A

```text
overall target       = 1000
target duration      = 5 hari
shifts per day       = 3
total planned shifts = 15
shift 1 target       = ceil(1000 / 15) = 67
```

Jika Shift 1 hanya menghasilkan GOOD 60:

```text
remaining good   = 1000 - 60 = 940
remaining shifts = 14
shift 2 target   = ceil(940 / 14) = 68
```

### Contoh B

```text
overall target       = 1000
target duration      = 5 hari
shifts per day       = 2
total planned shifts = 10
shift 1 target       = ceil(1000 / 10) = 100
```

## START API contract

Untuk START pertama yang membuat header production baru, request wajib mengirim:

```json
{
  "target_duration_days": 5,
  "shifts_per_day": 3
}
```

Field START lain (`rack_uid`, `part_id`, `setup_hash`, `tools_installed`, `tool_ids`, `operator_uid`, `event_id`) tetap mengikuti contract sebelumnya.

Pada continuation production di shift berikutnya, kedua field boleh tidak dikirim. Jika tetap dikirim, nilainya wajib sama dengan plan yang tersimpan pada `productions`.

## Batas dan perilaku

- `target_duration_days`: 1–3650 hari.
- `shifts_per_day`: 1, 2, atau 3.
- Target shift tidak mengubah overall target.
- Production hanya `completed` ketika cumulative `good_qty >= productions.target_qty`.
- Jika planned shift sudah habis tetapi overall target belum tercapai, shift berikutnya tetap boleh dibuat dan mendapat seluruh remaining GOOD target. Response state menandai `plan_overdue=true`.
- Mencapai target shift tidak otomatis menyelesaikan header production.

## Response state tambahan

State production sekarang juga mengembalikan:

- `target_duration_days`
- `shifts_per_day`
- `planned_shift_count`
- `used_shift_count`
- `remaining_planned_shift_count`
- `plan_overdue`
- `shift_target_reached`

## Migration

Jalankan:

```bash
php spark migrate
```

Migration FIX-08:

```text
2026-09-29-000007_AddProductionTargetPlan.php
```
