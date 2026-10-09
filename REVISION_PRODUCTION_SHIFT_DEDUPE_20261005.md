# Revision - Production / Shift Detail Reuse

Tanggal: 2026-10-05

## Bug

START ulang untuk Part/Process yang sama dapat membuat row `productions` baru dan row `production_shift_details` baru. Pada halaman Production, dua detail Shift yang sebenarnya satu konteks kemudian membuat `Plan Shift` terjumlah dua kali, misalnya 504 + 504 = 1.008.

Penyebab utama:

1. `startSession()` selalu INSERT ke `productions` setelah detail sebelumnya selesai.
2. FINISH pada `plan_basis=cycle_7h` sebelumnya selalu menandai header `productions` completed walaupun GOOD belum mencapai target.
3. Writer shift detail belum melakukan lookup composite business key sebelum INSERT.

## Business rule final

### Productions

Sebelum membuat header baru, START mencari production dengan:

- Machine sama;
- Part sama;
- Process sama;
- mode `production`;
- GOOD masih di bawah `target_qty`.

Jika ditemukan, `production_id` tersebut direuse. Pergantian shift hanya membuat shift detail baru di bawah production yang sama.

FINISH menutup shift detail. Header production baru `completed` jika GOOD production sudah mencapai target. Jika belum, header tetap `running` dengan `session_state=awaiting_next_shift` sehingga dapat dilanjutkan.

### production_shift_details

Exact shift detail direuse jika composite key sama:

- `production_id` sama;
- `work_date` sama;
- `shift_id` sama;
- `operator_employee_id` sama.

Row lama di-UPDATE/reopen; tidak membuat detail baru dan tidak mereset:

- target;
- gross/good/reject;
- `counter_epoch`;
- started_at;
- Tool usage;
- cycle/alarm/operator history.

Database unique key disesuaikan menjadi:

`(production_id, work_date, shift_id, operator_employee_id)`.

Pergantian Operator melalui flow **Operator Sakit** tetap merupakan flow khusus: row shift detail yang sama di-update ke Operator replacement agar histori sesi tetap satu, sementara `production_operator_histories` mempertahankan histori Operator sebelumnya.

## Defect

Karena detail completed dapat dibuka kembali lalu FINISH lagi, `production_nc_details` diperlakukan sebagai snapshot final detail. FINISH berikutnya mengganti breakdown defect lama pada detail tersebut, bukan menambah row defect duplikat.

## Existing duplicate data

Patch ini mencegah duplikasi baru. Data duplikat yang sudah ada sebelum patch tidak digabung otomatis karena child data (cycle, runtime, alarm, Tool usage, NC, operator history) harus dijaga. Pada database development/test, reset data transaksi Production sebelum pengujian ulang adalah opsi paling aman. Pada database yang harus dipertahankan, backup lalu lakukan merge terkontrol.

## Migration

Jalankan:

```bash
php spark migrate
```

Migration baru: `2026-10-05-000004_AdjustProductionShiftDetailBusinessKey.php`.
