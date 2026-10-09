# Device API — Direct Machine Tools v2

Base path `/api/tpms/production/`; method POST JSON. Header `X-TPMS-Key` tetap menggunakan token individual `tpms_devices.token`. Semua payload menyertakan `mac_address`. Endpoint writer wajib memakai `event_id` unik per device; retry harus menggunakan payload yang sama. Respons sukses `{ "ok": true, "data": ... }`; gagal `{ "ok": false, "message": ... }`.

## Prinsip role

- **Operator** hanya untuk Production. RFID harus Employee aktif dengan role `Operator` dan memiliki Planning Employee `assignment_role=operator` pada Machine, work date, dan shift aktif.
- **PIC** untuk Setting dan maintenance Tool. RFID cukup harus terdaftar sebagai Employee aktif dengan role `PIC`. PIC tidak memiliki Planning Employee dan tidak terikat Machine, work date, atau shift.
- PIC tidak dapat menggantikan Operator untuk START/FINISH Production. Operator tidak dapat menggantikan PIC untuk Setting atau service Tool.

## Read operations

- `context`: slot/machine, current shift, planning (`operator`, `pic`, `unit_head`), open session, dan histori.
- `pic`: validasi awal RFID PIC aktif berdasarkan RFID + role `PIC`; tidak ada validasi Planning/shift.
- `operator`: validasi awal RFID Operator terhadap role dan Planning Machine/shift aktif. Dipanggil sebelum HMI meminta Part Code.
- `parts`: filter optional `part_code` / `part_id`; `mode` adalah `production` atau `setting`. Respons memuat Process, posisi Required Tool Type, physical Tool hasil resolve pada Machine, lifetime, dan `selectable`.
  - `mode=production`: Tool Type harus tersedia, Tool harus siap, dan remaining lifetime harus `> 0`.
  - `mode=setting`: physical Tool dan posisi tetap di-resolve, tetapi status/lifetime tidak memblokir karena PIC dapat sedang menyiapkan/mengganti Tool.
- `state`: memulihkan session aktif termasuk `production_shift_detail_id`, `counter_epoch`, status alarm, Setting state, PIC, dan snapshot Tool.

## Production START — wajib Operator lalu Part Code

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "event_id": "start-001",
  "operator_uid": "55667788",
  "part_code": "PART-001",
  "part_id": 10,
  "part_process_id": 21,
  "tools_installed": true,
  "tool_ids": [5, 8]
}
```

Urutan HMI: scan RFID Operator → validasi endpoint `operator` → input Part Code → pilih Process → tampilkan Tool/posisi → konfirmasi Tool. `operator_uid` dan `part_code` keduanya wajib. `part_code` harus sama dengan Part terpilih. `tool_ids` harus sama persis dengan hasil resolver Process pada Machine tersebut.

START ditolak bila:
- RFID bukan Operator aktif / tidak sesuai planning Machine+tanggal+shift;
- Part/Process tidak aktif atau tidak cocok;
- salah satu Required Tool Type tidak tersedia pada Machine;
- Tool tidak `ready/warning`;
- `actual_lifetime >= set_lifetime` Process (remaining `<= 0`);
- Tool/posisi yang dikonfirmasi berubah dari hasil resolver.

## Lifetime Tool saat Production

Batas server:

- remaining `> 5`: normal;
- remaining `<= 5` dan `> 1`: warning, production tetap berjalan;
- remaining `= 1`: danger / peringatan terakhir, production tetap berjalan;
- remaining `<= 0`: critical + `service_required`; runtime production dihentikan sampai maintenance selesai.

Lifetime bertambah berdasarkan gross output, termasuk reject sesuai kontrak production existing.

## Cycle / counter / finish

Kontrak `reset_per_shift_v2` dan `two_trigger_v1` tetap berlaku. Endpoint `cycle-start`, `cycle-stop`, `count`, `pause`, `alarm`, `resume`, `stop`, dan `finish` tetap menggunakan identitas session/detail/epoch aktif.

- `cycle-start`: hanya saat Production `running`.
- `cycle-stop`: satu STOP cycle menaikkan gross satu kali; retry event yang sama idempotent.
- `count`: cumulative `counter_total`, bukan delta.
- `pause`: break/operator pause existing tetap berlaku.
- `resume`: menyelesaikan alarm `pause`; tidak menggantikan `service-complete` untuk critical.
- `finish`: wajib `operator_uid` milik Operator yang tersimpan pada shift detail. Production tidak dapat ditutup menggunakan RFID PIC atau hanya Part Code.

## Setting — PIC → Part Code → Process → Tool/Position

### 1. Validasi PIC (optional pre-check HMI)

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "pic_uid": "11223344"
}
```

Endpoint: `pic`.

### 2. Mulai Setting saat Part Code diterima

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "event_id": "setting-start-001",
  "pic_uid": "11223344",
  "part_code": "PART-001"
}
```

Endpoint: `setting-start`.

### Mid-production Setting / reuse shift detail

Jika `setting-start` dipanggil saat Production `running` pada Machine, work date, shift, dan Part yang sama, backend tidak membuat `productions` maupun `production_shift_details` baru. Open cycle di-interrupt, runtime berpindah `production -> setting`, dan `setting-finish` mengembalikan detail yang sama ke `running`. Process harus sama dengan Process production aktif dan Tool/position wajib dikonfirmasi. Response menandai `setting_scope=production_interrupt` dan `reused_shift_detail=true`.


Pada request sukses:
- jika tidak ada Production aktif, dibuat session standalone `mode=setting`;
- jika ada Production `running` dengan Machine/work date/shift/Part yang sama, backend memakai `production_id` dan `production_shift_detail_id` yang sudah aktif;
- `production_shift_details.pic_employee_id` menyimpan PIC;
- status detail/device menjadi `setting`;
- runtime interval Setting dimulai **sejak request Part Code ini**;
- event/alarm info Setting dibuat dan mencatat PIC + Part;
- Process/Tool belum dianggap terkonfirmasi (`setting_configured=false`).

### 3. Pilih Process dan konfirmasi Tool/posisi

Ambil catalogue `parts` dengan `mode=setting` + `part_code`, lalu kirim:

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "event_id": "setting-configure-001",
  "production_id": 100,
  "pic_uid": "11223344",
  "part_process_id": 21,
  "tools_position_confirmed": true,
  "tool_ids": [5, 8]
}
```

Endpoint: `setting-configure`.

PIC harus sama dengan PIC yang memulai Setting. Backend resolve Required Tool Type → physical Tool pada Machine, lalu menyimpan snapshot Tool + posisi Process.

### 4. Selesai Setting

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "event_id": "setting-finish-001",
  "production_id": 100,
  "pic_uid": "11223344"
}
```

Endpoint: `setting-finish`. Setting harus sudah dikonfigurasi. Runtime Setting ditutup dan event Setting di-resolve dengan PIC penyelesai. Standalone Setting kembali `idle/completed`; mid-production Setting kembali `running` pada shift detail dan `counter_epoch` yang sama.

## Critical Tool maintenance

### Change Edge

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "event_id": "edge-001",
  "pic_uid": "11223344",
  "tool_id": 5,
  "current_edge": 2,
  "reason": "Edge 1 aus"
}
```

Endpoint `change-edge`. PIC cukup Employee aktif role `PIC`; tidak bergantung Planning/shift. Tool harus terpasang pada Machine. Change Edge mereset lifetime ke 0 dan log menyimpan identitas PIC.

### Reset / Replace Tool

```json
{
  "mac_address": "AA:BB:CC:DD:EE:01",
  "event_id": "reset-tool-001",
  "pic_uid": "11223344",
  "tool_id": 5,
  "reason": "Tool fisik diganti"
}
```

Endpoint `reset-tool`. Hanya dapat digunakan untuk Tool yang sedang memiliki critical `service_stop` pada production aktif. Lifetime direset ke 0, current edge kembali 1, status `ready`, dan histori mencatat PIC.

### Service complete

Setelah Change Edge / Reset Tool menyelesaikan kondisi critical, kirim `service-complete` menggunakan `pic_uid` PIC aktif dan `alarm_id` critical. Alarm diselesaikan dengan `resolution_notes` berisi PIC. Production kembali `running` hanya jika blocking condition sudah aman.

## Catatan

- Assignment physical Tool tetap berada di `machine_tools` tanpa position.
- Position adalah kebutuhan Process (`process_tool_requirements.position`) dan disnapshot ke `production_tool_usages.position_snapshot`.
- Endpoint RackTools/change-side lama tidak digunakan pada flow direct Machine Tool ini.

## Operator Sakit / Replacement (2026-10-05)

Flow pergantian Operator di tengah shift menggunakan detail yang sama:

`running -> operator-sick -> operator_change_required -> PIC resolve -> Admin update Planning -> operator-replacement -> running`

Endpoint:
- `POST /api/tpms/production/operator-sick`
- `POST /api/tpms/production/operator-sick-resolve`
- `POST /api/tpms/production/operator-replacement`

`operator-sick` wajib membawa `production_id`, `production_shift_detail_id`, `counter_epoch`, `counter_total`, `operator_uid`, dan `reason`. RFID harus Operator aktif pada detail. Server membuat alert `critical/operator_stop`, meng-interrupt cycle terbuka, dan tidak membuat detail baru.

PIC aktif role `PIC` wajib menyelesaikan alert melalui `operator-sick-resolve`; PIC tidak perlu terjadwal pada Machine/shift. Selama alert belum resolved, Planning Operator aktif tidak boleh diganti. Setelah PIC resolve, Admin boleh mengganti Planning. Operator baru kemudian melakukan RFID melalui `operator-replacement`; backend memvalidasi Planning baru lalu mengubah `production_shift_details.operator_employee_id` dan membuka runtime Production kembali.

`production_shift_detail_id`, `counter_epoch`, quantity dan Tool usage tidak dibuat ulang. Histori personel disimpan pada `production_operator_histories`; `production_shift_details.operator_employee_id` menjadi Operator aktif/terakhir. State juga mengembalikan `operator_sick_resolved`, `planning_change_required`, dan `operator_replacement_ready` agar recovery setelah polling/reboot tetap deterministik.
