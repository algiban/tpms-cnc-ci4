# TPMS CI4 — Flow Audit Fix 2026-10-05

Perubahan ini sengaja dibatasi pada hasil audit flow PIC / Operator / Setting / Production / Tool lifetime. Struktur Machine Tool, Tool Type, Part Process, cycle-time, counter contract, dashboard, dan fitur lain yang tidak terkait tidak dirombak.

## Phase 1 — Role & authorization

- Planning Employee hanya untuk personel shift (Operator/Kanit). PIC tidak dijadwalkan.
- PIC TPMS cukup Employee aktif role PIC; tidak terikat Machine, work date, atau shift.
- Operator TPMS harus Employee aktif role Operator dan ter-assign ke Machine + work date + shift aktif.
- Endpoint `operator` memvalidasi RFID Operator sebelum HMI meminta Part Code.
- START Production wajib RFID Operator; jalur START melalui PIC dihapus.
- Production wajib Part Code.

## Phase 2 — Setting flow

- `setting-start` dimulai dari PIC + Part Code dan langsung membuka runtime `setting`.
- Process dipilih setelah Setting aktif melalui `setting-configure`.
- Setting resolve physical Tool dari Required Tool Type dan menampilkan position milik Process tanpa lifetime blocking.
- PIC disimpan terpisah pada `production_shift_details.pic_employee_id`.
- Setting finish wajib PIC yang sama. Standalone Setting kembali idle/completed; Setting di tengah Production kembali running pada shift detail yang sama.
- Riwayat Setting dicatat sebagai event/alarm info beserta PIC.

## Phase 3 — Production lifetime & service

- Production tetap hanya memakai Tool `ready/warning` dengan remaining > 0.
- remaining <= 5: warning.
- remaining = 1: danger / last warning.
- remaining <= 0: critical + service_required.
- `change-edge` wajib RFID PIC aktif dan log menyimpan PIC; tidak ada validasi jadwal PIC.
- endpoint baru `reset-tool` untuk replace/reset Tool critical; PIC disimpan di log.
- `service-complete` wajib RFID PIC aktif dan resolution notes menyimpan PIC; tidak ada validasi jadwal PIC.
- FINISH Production wajib RFID Operator pada shift detail.

## Phase 4 — Finishing

- Production Report/Detail, Monitoring, dan Machine Utility dapat menampilkan PIC untuk Setting.
- Production Detail menampilkan Resolution/PIC pada riwayat alarm.
- Seeder tetap menyediakan Employee PIC, tetapi tidak membuat Planning PIC.
- Regression test disesuaikan dengan Machine Tool tanpa position dan position Process.
- Dokumentasi Device API diperbarui agar tidak lagi menggunakan kontrak PIC/Part Code lama untuk START Production.

## Migration

Jalankan:

```bash
php spark migrate
```

Migration baru menambah nullable `pic_employee_id` pada `production_shift_details` beserta index dan foreign key ke `employees`.

## Regression test

Gunakan database disposable dengan nama berakhiran `_test` atau `_qa`:

```bash
TPMS_REFACTOR_TEST=1 php tests/refactor/run.php
```

Jangan menjalankan regression test tersebut ke database production karena fixture test melakukan truncate table.
