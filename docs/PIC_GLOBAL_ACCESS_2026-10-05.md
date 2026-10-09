# PIC Global Access — 2026-10-05

PIC adalah technical support global, bukan personel yang dijadwalkan per shift.

## Aturan

- RFID PIC valid bila Employee aktif dan `role = PIC`.
- PIC tidak membutuhkan `employee_shift_assignments`.
- PIC dapat melakukan Setting, Change Edge, Reset/Replace Tool, Service Complete, dan resolve Operator Sakit pada Machine mana pun sesuai flow yang sedang aktif.
- Identitas PIC tetap disimpan pada `pic_employee_id`, alarm resolution, dan activity log untuk audit.
- Planning Employee hanya menampilkan Operator dan Kanit/Unit Head.
- Operator tetap wajib sesuai Planning Machine + work date + shift.
- Data Planning PIC lama, bila masih ada di database, diabaikan oleh runtime dan tidak ditampilkan sebagai role planning.
- Setting tetap wajib diselesaikan oleh PIC yang sama dengan PIC yang memulai sesi; ini adalah ownership sesi, bukan aturan jadwal.

Perubahan ini tidak mengubah struktur Tool/Part, lifetime 5/1/0, reuse `production_shift_detail`, atau flow Operator Sakit/replacement.
