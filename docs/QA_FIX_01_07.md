# TPMS CI4 — QA Fix 01–07

Baseline: `tpms-ci4 - terbaru (28-09-26)(1).zip`  
Patch: 29 September 2026

Dokumen ini menjelaskan perubahan business logic dan kontrak API setelah QA produksi.

## WAJIB setelah mengganti source

```bash
php spark migrate
```

Migration baru:

```text
2026-09-29-000006_HardenProductionFlow.php
```

Jangan jalankan firmware final lama sebelum mengikuti kontrak counter pada FIX-02.

---

## FIX-01 — Production Quantity Model

### Definisi final

- `target_qty` = target **GOOD** keseluruhan production.
- `actual_qty` pada schema lama dipertahankan untuk kompatibilitas, tetapi artinya sekarang ditegaskan sebagai **GROSS quantity**.
- `good_qty` = gross - reject.
- `reject_qty` = jumlah NC/reject yang dikonfirmasi saat shift ditutup.
- `remaining_good_qty` = max(target - cumulative good, 0).
- Production hanya `completed` jika `cumulative good >= target`.

Contoh:

```text
Target good = 100
Gross       = 100
Reject      = 3
Good        = 97
Remaining   = 3
Status      = belum completed
```

Shift berikutnya mendapat target carry-over `3`, bukan `0`.

Header `productions` sekarang menyimpan total gross/good/reject yang disinkronkan dari seluruh `production_shift_details`.

---

## FIX-02 — Shift Rollover & Counter Contract

Counter contract sekarang eksplisit:

```text
counter_contract = reset_per_shift_v2
```

### Aturan

1. Counter ESP di-reset ke `0` ketika shift detail baru dimulai.
2. Server mengeluarkan `production_shift_detail_id` dan `counter_epoch` unik pada START/STATE.
3. Setiap request COUNT wajib mengirim keduanya.
4. Server menolak COUNT jika `shift_id` atau `work_date` sudah bukan shift aktif menurut jam server.
5. Server menolak event dari shift lama walaupun `production_id` masih sama.

Payload COUNT minimum setelah patch:

```json
{
  "event_id": "uuid-event",
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "production_id": 123,
  "production_shift_detail_id": 456,
  "counter_epoch": "epoch-dari-start-atau-state",
  "counter_total": 1
}
```

Response START/STATE memberikan:

```json
{
  "production_shift_detail_id": 456,
  "counter_contract": "reset_per_shift_v2",
  "counter_epoch": "...",
  "counter_expected_total": 0
}
```

Jika shift berubah, firmware harus menutup shift lama, mengambil START/STATE terbaru, reset counter lokal, lalu menggunakan epoch baru.

---

## FIX-03 — Runtime Configuration Lock

Resource berikut tidak boleh dipindah/diubah ketika masih digunakan shift production aktif:

- machine;
- slot;
- TPMS/device assignment;
- part;
- racktools dan isi rack;
- tool setup;
- employee yang menjadi operator/unit head aktif.

Status detail yang dianggap mengunci resource:

```text
running
paused
service_required
awaiting_defects
```

Server mengembalikan conflict (`409`) bila admin mencoba mengubah resource yang sedang dipakai production aktif.

---

## FIX-04 — Authorization & TPMS Credential

- Semua mutation Master Data memakai `auth + admin + csrf`.
- Users mutation dan Planning save juga memakai `admin + csrf`.
- API ESP/TPMS tetap machine-to-machine dan tidak memakai CSRF; autentikasi tetap dilakukan oleh API/controller.
- Auto routing dimatikan untuk mencegah endpoint controller diakses melalui method yang tidak didefinisikan.
- Token TPMS tidak lagi ditampilkan di halaman TPMS / Device Assignments.
- Token tidak lagi disalin ke assignment history dan token history lama dinull-kan oleh migration.

Catatan: token masih digunakan secara internal untuk autentikasi device dan provisioning; yang dihilangkan adalah exposure ke UI/history.

---

## FIX-05 — Planning & Attendance

Planning yang tidak boleh diedit sekarang ditentukan dari:

```text
production_shift_details.work_date
+ shift_id
+ machine
+ detail status
```

bukan lagi hanya `productions.production_date`.

Operator dan Unit Head memiliki role terpisah. Saat shift dimulai, server menyimpan snapshot:

```text
operator_employee_id
unit_head_employee_id
```

Attendance dicocokkan dengan:

```text
employee + work_date + shift_id + assignment_role
```

Bug summary attendance yang sebelumnya ikut terpotong maksimum 15 baris juga diperbaiki: batas 15 hanya untuk detail tampilan, sedangkan statistik memakai seluruh range query.

---

## FIX-06 — Dashboard & Monitoring

Semua pencapaian/remaining menggunakan GOOD:

```text
progress = good / target_good
remaining = target_good - good
```

Gross dan Reject tetap ditampilkan terpisah.

Field `actual_qty` pada beberapa response lama tetap dipertahankan sebagai alias kompatibilitas untuk gross agar klien lama tidak langsung error, tetapi business decision tidak lagi memakai gross sebagai completion.

---

## FIX-07 — Regression / Integration Business Tests

Test tambahan:

```text
tests/unit/ProductionQuantityTest.php
tests/integration/ProductionFlowBusinessScenarioTest.php
```

Skenario yang dilindungi oleh test policy/business flow:

```text
START
→ COUNT sampai nominal target
→ alarm pause / resume
→ service_required / service-complete
→ shift rollover
→ STOP / confirm defects
→ reject membuat good turun di bawah target
→ next shift memakai remaining good
→ FINISH
→ completed hanya ketika cumulative good >= target
```

### Manual API regression yang tetap wajib sebelum commissioning hardware

1. START shift baru, pastikan epoch/detail ID diterima.
2. COUNT `0 → 1 → 2`; pastikan tidak double increment pada retry `event_id` yang sama.
3. Kirim COUNT dengan epoch salah; harus `409`.
4. Simulasikan pergantian shift; COUNT shift lama harus `409`.
5. Trigger alarm pause lalu RESUME.
6. Trigger critical lifetime/service, reset/service tool sesuai flow, lalu SERVICE COMPLETE.
7. STOP shift, kirim reject, FINISH.
8. Pastikan `good = gross - reject` dan production belum completed bila good < target.
9. START shift berikutnya dan pastikan `shift_target_qty = remaining_good_qty`.
10. Produksi sisa good, STOP + FINISH, pastikan header menjadi completed.
11. Saat production aktif, coba pindah machine/TPMS/edit part/rack; harus ditolak `409`.
12. Login sebagai role `user`, coba POST mutation Master Data langsung; harus ditolak oleh admin filter.

---

## Catatan kompatibilitas firmware

Patch ini **mengubah kontrak COUNT**. Firmware/simulator yang hanya mengirim `counter_total` tanpa:

```text
production_shift_detail_id
counter_epoch
```

akan ditolak. Ini disengaja untuk mencegah counter shift lama masuk ke shift baru.

Sebelum firmware final dibuat, gunakan response START/STATE sebagai sumber `counter_epoch` dan reset counter lokal setiap shift baru.
