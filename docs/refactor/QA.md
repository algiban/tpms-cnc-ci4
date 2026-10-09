# QA refactor 02-10-2026

Lingkungan uji terpisah: PHP 8.3.6, CodeIgniter 4.7.4, MariaDB 10.11.14, MySQLi, database disposable. Tidak memakai atau mengubah database pengguna.

## Verifikasi yang dijalankan

| Pemeriksaan | Hasil |
|---|---|
| Migration lengkap database kosong | Semua 14 file migration berhasil |
| Seeder database kosong | MasterDataSeeder dan ProductionSeeder berhasil; AdminSeeder diuji pada fixture |
| Upgrade fixture legacy | 7 assertions: preflight aktif/invalid edge sebelum DDL, edge/support archive, rename/drop, assignment pasti/ambigu, requirement conversion, histori retained |
| PHPUnit unit + integration existing dan baru | 17 tests, 41 assertions lulus |
| Business integration `tests/refactor/run.php` | 49 assertions lulus |
| Monitoring live API | 6 assertions lulus: status, Part/process/tools/position, AVG 20%+60%=40%, exclusion Setting, Registration Code/context, alarm |
| HTTP smoke | 32 assertions lulus: halaman master/Production/Utility/report/log/planning/users/monitoring, export, route Rack 404, device auth, auth web dan CSRF |
| HTTP CRUD/import/role | 25 assertions lulus: Machine registration create/edit/duplikat, type active/inactive, Tool create/edit/maintenance/assignment/unassign/delete guard, Part create/edit/delete, atomic import rollback, multi-process import, PIC import dan non-admin denied |
| Template XLSX | Enam template ditulis, dihitung ulang, diinspect dan dirender; Import/Contoh/Petunjuk dipertahankan; tidak ada formula errors |
| PHP/JavaScript syntax | 194 file PHP lulus lint; JavaScript monitoring, process editor dan import lulus syntax check |

Business integration memeriksa beberapa required Tool Types, resolving fisik berbeda di dua Machine, start bersamaan Part/process sama, missing type error, reassign atomic, posisi unik, Tool hanya satu Machine, active Tool guard, snapshot tidak berubah sesudah edge/reassign, plan/floor/daily, non-PIC ditolak, event replay/payload collision, cycle START/STOP dan timing, stale epoch, pause/count-block/resume, GOOD/NC, lifetime gross, critical alarm/service-complete, Part Code start/finish, Setting catalogue/start/state/same PIC finish/no count/no cycle/runtime, serta DB constraint edge/registration/assignment.

Unit timeline memeriksa Setting sebagai durasi terpisah, Offline mengalahkan state session, serta waktu mendatang tidak dihitung. Unit AVG memverifikasi contoh Running 80%+60%, Idle 20%, Offline 100%, Setting 40%, Alarm 90% menghasilkan 70% dari dua mesin Running.

## Menjalankan ulang

Siapkan `.env`/environment yang menunjuk **database MySQL/MariaDB disposable bernama dengan akhiran `_test` atau `_qa`**, DBPrefix kosong, lalu:

```bash
php spark migrate
php vendor/bin/phpunit --no-coverage tests/unit tests/integration
TPMS_REFACTOR_TEST=1 php tests/refactor/run.php
```

**run.php melakukan TRUNCATE pada seluruh tabel kecuali migrations di database uji.** Jangan mengarahkannya ke database operasional. Guard flag/nama DB tidak menggantikan pemeriksaan konfigurasi. Script meninggalkan fixture untuk inspeksi sesudah tes.

Syntax checks:

```bash
find app tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/js/monitoring-compact.js
node --check public/assets/js/part-process-editor.js
```

## Batas verifikasi

- Smoke HTTP/CRUD menggunakan server PHP lokal dan session web sebenarnya. Tes non-admin mempertahankan redirect/error existing, bukan mengubah filter menjadi status 403.
- Browser rendering/responsive end-to-end belum dapat dijalankan: Chromium tidak tersedia dan unduhan browser gagal. CSS responsive sudah diimplementasikan dan HTML/JS diperiksa; layout, modal Bootstrap, CDN dependency, keyboard/mobile tetap perlu acceptance di browser aktual.
- Firmware/simulator/HMI dan hardware RFID/trigger tidak tersedia dalam sumber sehingga tidak diuji. Sesuaikan client berdasarkan API.md dan uji retry/offline/shift rollover nyata sebelum deployment.
- Uji migrasi memakai fixture representatif, bukan salinan database produksi. Review data ambigu, blank type, legacy cycle 0, dan hasil konsolidasi requirements setelah migrasi staging.
- Histori di atas hanya membuktikan data yang pernah disimpan. Snapshot legacy yang tidak tersedia dibackfill dari master pada waktu upgrade.
