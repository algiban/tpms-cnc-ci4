# Refactor TPMS — Direct Machine Tools (02-10-2026)

## Hasil dan batas perubahan

Project CodeIgniter 4 existing tetap digunakan. Authentication web, role admin/user, device token, CSRF, event idempotency, counter epoch, cycle trigger, NC, pause/resume, critical service, planning employee, registration TPMS, dan modul logs dipertahankan.

Alur aktif menjadi **Machine → machine_tools → Physical Tool → Tool Type**, serta **Part → Process → Required Tool Types**. Part tidak diassign ke Machine atau physical Tool. Instance Production/Setting memakai tabel production existing; tidak dibuat sistem session terpisah.

Menu, route, import/export, dan start API RackTools ditutup. Tabel RackTools, pivot legacy, kolom rack/batch/target lama dan controller tanpa route disimpan untuk referensi histori. Auto routing tetap mati. Jangan menghapus tabel arsip tersebut secara manual.

## Impact analysis

| Area | Tabel / file utama | Perubahan |
|---|---|---|
| Machine | `machines`, MachineController, MachineModel, master-data/machines | Registration Code required dan unique; metadata existing tetap tersedia |
| Tool | `tools`, ToolController, ToolModel, master-data/tools | Tool Type FK, Cutting/Current Edge, maintenance, direct assignment |
| Tool Type | `tool_types`, ToolTypeController, master-data/tool-types | Master kebutuhan process, active/inactive |
| Assignment | `machine_tools`, MachineToolService, MachineToolController | Unique physical Tool; unique position per Machine; transaksi reassign |
| Part/process | `parts`, `part_processes`, `process_tool_requirements`, PartConfigurationService, PartController, MasterData | Waktu dan requirement/lifetime per process; tanpa assignment Part–Machine/Tool |
| Plan | ProcessPlan, part-process-editor.js | Perhitungan authoritative dalam millisecond, floor integer |
| Production/API | EspProductionService, ProductionApiController, Routes | PIC/Part Code, resolving tools, session independent per machine, snapshot |
| Setting | productions.mode, production_shift_details, production_runtime_intervals | PIC aktif, PIC yang sama saat finish, runtime setting, qty selalu 0 |
| Histori | production_tool_usages, tool_lifetime_logs, ActivityLogService | Snapshot Tool/position/edge/type, log edge dan assignment |
| Monitoring | MonitoringController, MonitoringMetrics, monitoring/index, monitoring-compact.js | Compact cards/details, alarm footer, AVG Running |
| Utility | MachineUtilityController, machine-utility/index | Compact actual-time timeline, Setting, elapsed daily/current runtime, rate/NC/loss |
| Production detail | ProductionController, production/index | Flow dan session timeline/runtime di detail; snapshot standard/tools |
| Report | CycleTimeReport, CycleTimeReportController, reports/cycle-time | Machine × Part × Process, standard/actual/variance dan CSV |
| Transfer | MasterDataTransferController, enam template XLSX | Tool Types, machine assignments, per-process Part; PIC import |
| Regression | Seeder existing, runtime guard, TPMS heartbeat, dashboard | Field/status baru dan histori existing tetap kompatibel |

## Database change plan dan hasil implementasi

Migration: `2026-10-02-000013_DirectMachineTools.php`, setelah semua migration existing.

- `machines.registration_code` diisi dari `machines.code` untuk data lama, kemudian NOT NULL dan UNIQUE. Metadata tonnage/screw_diameter ditambahkan jika belum ada agar form/seeder existing dapat menyimpan field tersebut.
- `tools.total_side` → `cutting_edge`; `current_side` → `current_edge`. Check constraint: 1 ≤ current_edge ≤ cutting_edge. Nilai edge existing tidak direset.
- `support` dan legacy `type` disalin ke `legacy_metadata_json` sebelum Support di-drop. Kolom type lama tetap arsip; alur baru hanya memakai tool_type_id.
- `tool_types`: distinct legacy type yang sudah dinormalisasi. Type kosong masuk UNCLASSIFIED inactive untuk direview.
- `machine_tools`: FK Machine/Tool, UNIQUE tool_id dan UNIQUE(machine_id, position). FK restrict mencegah master dihapus saat masih diassign.
- `process_tool_requirements`: UNIQUE(process_id, tool_type_id), FK process cascade, Tool Type restrict. Lifetime harus positif di backend.
- Legacy part_tools dikonversi menjadi requirement setiap process. Jika beberapa tool dengan type sama dipakai pada Part lama, migration menyatukannya menjadi satu requirement type dengan lifetime minimum. Review konfigurasi process hasil migrasi.
- Assignment legacy hanya dikonversi bila seluruh referensi setuju pada satu Machine dan satu position, serta posisi kosong. Ambigu/bentrok dicatat pada `tpms_refactor_issues`; master Tool tetap ada. Tool tanpa bukti assignment tetap Unassigned.
- `productions`: mode, plan_basis, standard_machine_time_ms dan standard_loading_time_ms.
- `production_tool_usages`: snapshot code/name/type/Cutting Edge/Current Edge, selain position dan lifetime snapshot existing.
- Histori lama yang belum memiliki snapshot dibackfill dari master saat migrasi. Nilai master saat produksi lama tidak dapat direkonstruksi jika sebelumnya tidak pernah disimpan; backfill ini bukan bukti nilai asli saat produksi tersebut.

DDL MySQL/MariaDB tidak transactional. Migrasi menolak nilai edge legacy invalid dan memeriksa sesi Running/Paused/Service Required/Awaiting Defects/Setting sebelum DDL. Ini tidak menggantikan maintenance window: hentikan writer TPMS/web selama upgrade. `down()` sengaja menolak penghapusan refactor; rollback memakai backup database sebelum migrasi.

## Flow operasional

1. **Tool assignment:** pilih physical Tool → Machine + position → cek sesi aktif dan posisi → Reassign jika pindah → lepaskan assignment sebelumnya dan simpan baru dalam satu transaksi → log.
2. **Part registration:** Part → jumlah/mode process existing → M/L Time per process → required Tool Types + lifetime → hitung plan. Setiap process wajib minimal satu type aktif. Next Grinding memakai pilihan existing.
3. **Tool resolution:** required type dicocokkan hanya dengan physical Tool pada Machine perangkat. Type yang kurang/tidak siap disebutkan di error. Jika beberapa tool satu type terpasang di Machine, dipilih posisi pertama menurut natural order, tanpa fallback ke type lain.
4. **Production PIC:** validasi RFID Employee active, role PIC → Part + Process → konfirmasi tools terpasang → START. PIC menjadi personel sesi.
5. **Production Part Code:** pilih/input Part Code + Part + Process → validasi yang sama → START. Operator planning existing dapat dipakai; tanpa operator, Part Code dikonfirmasi kembali saat finish.
6. **Production:** counter/cycle existing tetap berjalan, lifetime bertambah dari gross, GOOD = gross − NC. Session baru adalah satu pelaksanaan satu process dalam satu shift, dengan plan/shift snapshot. Finish menutup session, termasuk saat output di bawah plan. START berikutnya membuat session baru; tidak ada global lock terhadap Part.
7. **Setting:** mode setting → RFID PIC → Part + Process → START → runtime state setting, qty 0 → RFID PIC yang sama → FINISH. Tools boleh belum terpasang saat setting. Gunakan catalogue mode setting agar pilihan process tidak diblokir oleh requirement tool produksi.
8. **Edge:** PIC mengirim Change Edge pada Tool di Machine perangkat → edge harus meningkat dan ≤ cutting_edge → lifetime edge baru 0 → log. Saat Production aktif hanya di Service Required. Alarm tetap menunggu service-complete. Untuk kembali ke edge 1 gunakan operasi physical replacement/reset dengan alasan.
9. **Monitoring:** baca snapshot → status berdasarkan heartbeat/alarm/session → progress GOOD/plan → AVG hanya status Running → detail kiri Part/operator dan history, kanan Machine dan Tools/position.
10. **Report:** completed cycles dari histori Production → filter Machine/Part/Process/tanggal → standard START snapshot dibanding actual trigger → variance actual − standard.

Semua writer produksi dan assignment berbagi runtime lock existing untuk transaksi singkat. Lock ini mencegah race counter/assignment; bukan larangan Part yang sama berjalan pada Machine berbeda.

## Perhitungan dan definisi

- Effective shift = 25.200 detik, terlepas dari jam kalender shift existing.
- Waktu input detik dibulatkan ke millisecond; cycle = Machine + Loading.
- Plan/shift = floor(25.200.000 / cycle_ms); daily plan = plan/shift × shifts_per_day (1–3).
- Machine/Loading nonnegatif, total > 0 dan maksimal satu effective shift. Tidak ada input manual jumlah target atau duration untuk sesi baru.
- Kolom/API `target_qty` masih ada sebagai alias penyimpanan/kompatibilitas. UI aktif memakai istilah Plan. `plan_basis=legacy` menjaga interpretasi histori lama.
- Production rate pada Utility = GOOD / observed Running hours. Setting/Offline/Idle tidak dihitung sebagai output atau running hours.
- Utility menampilkan segmen sesuai jam nyata. Waktu masa depan dibiarkan kosong dan tidak dihitung. Current Runtime adalah durasi segmen status terakhir dalam tanggal kerja terpilih; muncul saat tanggal kerja sedang berjalan. Daily Runtime mencakup durasi status yang telah teramati, dengan Production/Setting dan losses ditampilkan terpisah.
- Offline diperkirakan setelah heartbeat stale 60 detik. Hilangnya heartbeat masa lalu yang tidak pernah dicatat tidak dapat direkonstruksi secara pasti.
- Report averages berbobot jumlah sample cycle dalam group; bukan rata-rata antar session. First cycle tidak memiliki loading sebelumnya, jadi loading/total cycle report tidak memakai sample pertama. Machine Time memakai seluruh completed cycles. M/L sample count ditampilkan; nilai tanpa sample ditampilkan `—`.
- Setting yang melintasi pergantian shift tetap menunggu PIC semula, sedangkan timeline durasinya dipecah menurut window shift.

## Urutan deployment

1. Backup database dan project existing. Uji restore backup di lingkungan terpisah.
2. Selesaikan seluruh session aktif, hentikan writer, kemudian pasang kode ini dengan `.env` database/baseURL existing.
3. Jalankan `php spark migrate`. Untuk database baru saja, seeder dapat dijalankan: MasterDataSeeder, AdminSeeder, ProductionSeeder (demo). Jangan menjalankan seeder demo untuk mengisi database produksi existing.
4. Review Registration Code, Tool Types inactive, assignment Unassigned, dan panel Migration Review di Tools. Lengkapi assignment dan waktu/requirement process yang masih kosong. Review hasil konsolidasi type legacy.
5. Sesuaikan simulator/firmware/HMI ke kontrak API di `API.md`. File firmware/simulator tidak terdapat dalam ZIP sumber, sehingga tidak diubah atau diuji di sini. Endpoint scan Rack lama sudah tidak tersedia.
6. Lakukan acceptance pada hardware TPMS, RFID, dan browser desktop/mobile aktual; kemudian aktifkan kembali writer.

Detail tes dan keterbatasan: `QA.md`. Business integration script hanya boleh dijalankan pada database disposable; script tersebut menghapus seluruh fixture/data database uji.
