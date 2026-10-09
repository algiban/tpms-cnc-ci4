# TPMS — Operator Sakit & Replacement Flow

Revisi ini hanya menambahkan alur pergantian Operator di tengah shift tanpa membuat `production_shift_details` baru.

## Business flow

1. Production sedang `running` dan memiliki Operator aktif.
2. Operator memilih **Operator Sakit/Berhalangan**, mengisi alasan, lalu konfirmasi dengan RFID Operator yang sedang aktif.
3. Backend membuat alarm:
   - `kind = operator_sick`
   - `severity = critical`
   - `effect = operator_stop`
4. Open cycle di-interrupt dan detail berubah menjadi `operator_change_required`. `production_id`, `production_shift_detail_id`, `counter_epoch`, quantity, dan Tool usage tetap sama.
5. PIC aktif role PIC melakukan RFID ke endpoint resolve; tidak perlu Planning Machine/shift. Alert terbaru wajib di-resolve PIC.
6. Setelah alert terbaru resolved, Admin boleh mengubah Planning Employee role Operator untuk Machine/date/shift tersebut.
7. Operator pengganti melakukan RFID pada TPMS. Backend memvalidasi role + Planning terbaru.
8. `production_shift_details.operator_employee_id` diubah menjadi Operator pengganti, status kembali `running`, dan production dilanjutkan pada detail yang sama.

## Traceability Operator

Tabel baru `production_operator_histories` menyimpan histori personel per shift detail. Main field `production_shift_details.operator_employee_id` tetap merepresentasikan Operator aktif/terakhir.

Contoh:

| Shift detail | Operator | Mulai | Selesai | Reason |
|---|---|---|---|---|
| 100 | Operator 1 | 07:00 | 12:00 | operator_sick |
| 100 | Operator 2 | 13:00 | 15:00 | replacement_after_operator_sick |

Production Detail menampilkan histori ini pada section **Riwayat Operator Shift**.

## API tambahan

### POST `/api/tpms/production/operator-sick`

Payload minimum:

```json
{
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "event_id": "unique-event-id",
  "production_id": 50,
  "production_shift_detail_id": 100,
  "counter_epoch": "...",
  "counter_total": 210,
  "operator_uid": "55667788",
  "reason": "Operator sakit"
}
```

RFID wajib milik Operator aktif pada shift detail. `production_shift_detail_id`, `counter_epoch`, dan `counter_total` harus sinkron dengan server. Alert dibuat `critical` dengan `effect=operator_stop`; detail tetap sama dan masuk `operator_change_required`.

### POST `/api/tpms/production/operator-sick-resolve`

```json
{
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "event_id": "unique-event-id",
  "production_id": 50,
  "alarm_id": 123,
  "pic_uid": "11223344",
  "message": "Operator pengganti siap dijadwalkan"
}
```

PIC harus Employee aktif role PIC; tidak bergantung Machine/date/shift. Resolve tidak langsung me-resume production.

### POST `/api/tpms/production/operator-replacement`

```json
{
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "event_id": "unique-event-id",
  "production_id": 50,
  "operator_uid": "77889900"
}
```

Syarat:
- state masih `operator_change_required`;
- alert `operator_sick` **terbaru** sudah resolved PIC;
- shift/work date masih sama;
- Planning Operator sudah diganti Admin;
- RFID adalah Operator baru yang sesuai Planning;
- work date dan shift masih sama dengan detail yang dihentikan.

State API menyimpan recovery flags `operator_sick_resolved`, `planning_change_required`, dan `operator_replacement_ready`, sehingga TPMS yang reboot tetap dapat mengetahui tahap pergantian Operator yang sedang menunggu.

## Planning lock

Planning Operator yang sedang dipakai production tetap terkunci. Satu-satunya exception adalah saat:

- detail `operator_change_required`; dan
- alert `operator_sick` terbaru sudah resolved PIC.

PIC tidak memiliki Planning. Planning Unit Head tetap mengikuti aturan sesi aktif.

## Monitoring

`operator_change_required` diperlakukan sebagai status **Alarm**. Alert `operator_sick` tetap tercatat sebagai critical. Runtime production berhenti selama menunggu replacement, tetapi shift detail tidak diselesaikan.

## Migration

Jalankan:

```bash
php spark migrate
```

Migration baru: `2026-10-05-000002_CreateProductionOperatorHistories.php`.
