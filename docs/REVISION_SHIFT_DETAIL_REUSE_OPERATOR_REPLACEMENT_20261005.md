# TPMS Machining — Shift Detail Reuse + Operator Replacement

Tanggal: 5 Oktober 2026

## Scope

Perubahan dibatasi pada runtime Production/Setting dan pergantian Operator. Master Part, Tool Type, physical Tool, lifetime 5/1/0, import/export, authentication umum, serta modul lain yang tidak berkaitan tidak dirombak.

## Phase 1 — Reuse Production Shift Detail saat Setting

Kasus yang didukung:
- Shift 1: 07:00–15:00
- Part A, Operator 1
- Production 07:00–12:00
- Setting 12:00–13:00
- Production lanjut 13:00–15:00

Hasil:
- `production_id` tetap sama.
- `production_shift_detail_id` tetap sama.
- `counter_epoch`, quantity, target, Tool usage, dan Operator tetap sama.
- open cycle yang belum STOP ditutup sebagai `interrupted` tanpa menambah quantity.
- runtime berubah `production -> setting -> production`.
- Setting mid-production hanya dapat dimulai saat detail `running`, shift/work date sama, dan Part Code sama.
- Process harus sama dengan Process production aktif.
- PIC cukup Employee aktif role PIC; tidak memiliki Planning Machine/date/shift.
- PIC wajib konfirmasi Tool/position sebelum `setting-finish`.
- `setting-finish` kembali ke `running`, bukan `completed`.

Migration `2026-10-05-000003_AddSettingConfiguredAtToProductionShiftDetails.php` menambah `setting_configured_at` agar Setting tidak dapat selesai sebelum Process/Tool/position dikonfirmasi.

Standalone Setting tetap mengikuti flow lama yang benar: `PIC -> Part Code -> Process -> Tool/position -> Finish -> Idle/Completed`.

## Phase 2 — Operator Sakit dan Replacement

Flow final:
1. Operator aktif memilih Operator Sakit/Berhalangan dan mengisi alasan.
2. Operator aktif wajib konfirmasi menggunakan RFID-nya.
3. Backend membuat alert `operator_sick` dengan `severity=critical` dan `effect=operator_stop`.
4. Cycle terbuka di-interrupt dan detail menjadi `operator_change_required`.
5. Production/detail/counter/quantity/Tool usage tidak dibuat ulang.
6. PIC aktif role PIC wajib resolve alert menggunakan RFID; tidak perlu jadwal Machine/date/shift.
7. Selama alert belum resolved, Admin tidak boleh mengganti Planning Operator aktif.
8. Setelah PIC resolve, Admin mengganti Planning Operator 1 -> Operator 2.
9. Operator 2 scan RFID pada TPMS.
10. Backend validasi role + Planning baru, mengganti `production_shift_details.operator_employee_id`, lalu detail lama kembali `running`.

Main shift detail menyimpan Operator aktif/terakhir. Histori Operator tidak hilang karena disimpan pada tabel `production_operator_histories`.

Migration `2026-10-05-000002_CreateProductionOperatorHistories.php` menambah tabel histori tersebut.

## Phase 3 — Monitoring, Recovery, Guard, Detail

- `operator_change_required` diperlakukan sebagai Alarm pada Monitoring, Dashboard, dan Machine Utility.
- Production Detail menampilkan **Riwayat Operator Shift**.
- Runtime/master-data guard tetap menganggap `setting` dan `operator_change_required` sebagai sesi aktif.
- Tool/Tool Type tidak dapat dimodifikasi seolah mesin idle ketika sedang Setting atau menunggu Operator pengganti.
- `productions.session_state` disinkronkan ke `operator_change_required` dan kembali `running` setelah replacement.
- State API mengembalikan:
  - `operator_replacement_required`
  - `operator_sick_resolved`
  - `planning_change_required`
  - `operator_replacement_ready`

Flags tersebut dihitung dari database sehingga recovery setelah polling/reboot tetap deterministik.

## Phase 4 — Finishing / QA

- Regression scenario ditambahkan untuk:
  - Setting mid-production tetap menggunakan detail dan counter epoch yang sama.
  - Operator Sick harus RFID Operator aktif.
  - Alert Operator Sick critical/operator_stop.
  - replacement ditolak sebelum PIC resolve.
  - replacement ditolak sebelum Planning Admin berubah.
  - setelah Planning berubah, state menunjukkan `operator_replacement_ready`.
  - Operator baru resume detail/counter epoch yang sama.
  - field utama shift detail berubah ke Operator baru.
  - histori Operator lama tetap tersimpan dan histori Operator baru ditutup saat shift selesai.
- 199 file PHP (`app` + `tests`) lolos `php -l`.
- `php spark`/integration DB tidak dapat dijalankan pada runner ini karena ekstensi PHP `mbstring` tidak tersedia (`mb_strpos()`), sehingga migration dan regression DB harus dijalankan pada environment proyek yang lengkap.
- Simulator **belum diubah pada pekerjaan ini**; CI4 ini menjadi kontrak backend untuk tahap simulator berikutnya.

## Setelah extract

```bash
php spark migrate
```

Kemudian pada database disposable dengan nama berakhiran `_test` atau `_qa`:

```bash
TPMS_REFACTOR_TEST=1 php tests/refactor/run.php
```

Jangan menjalankan regression test ke database production karena fixture melakukan truncate table.
