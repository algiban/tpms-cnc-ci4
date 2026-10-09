# CodeIgniter 4 Application Starter

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library


#       #########################################################################
Manual Book
## Create First User
- buka terminal, lalu jalankan `php spark db:seed AdminSeeder`
untuk users selanjutnya bisa menambahkan di menu users


## TPMS Machining — Revision Phase 2 (30-09-2026)

Phase 2 sudah termasuk seluruh Phase 1. Lihat `REVISION_ATASAN_PHASE2.md`. Setelah update jalankan `php spark migrate`.

## Boss Revision Phase 2 — Final Cumulative — 30/09/2026

Lihat `REVISION_ATASAN_PHASE2.md`. Phase 2 sudah mencakup Phase 1, two-trigger cycle, Machine/Loading Time, Runtime Progress, dan Monitoring process-aware. Jalankan `php spark migrate`.

## Boss Revision — Final Integration / Hardening

Lihat `REVISION_ATASAN_FINAL_INTEGRATION.md`.

Tambahan: Process Flow per batch, live Final GOOD semantics, cycle performance summary, dan interrupted-cycle guard tanpa penambahan gross counter.


## FIX 11 — FK Type Hotfix (30-09-2026)

Fixed MySQL errno 150 during `part_processes` creation by aligning all new Phase 1/2 foreign-key columns with the base schema (`BIGINT UNSIGNED`). See `FIX_11_FOREIGN_KEY_TYPE_HOTFIX.md`.

## FIX 12 — Tool Current Side (30-09-2026)

Tools sekarang memiliki `current_side`. Monitoring menampilkan `current_side / total_side`. Action **Ganti Side** menaikkan side, mereset lifetime side ke 0, dan menulis log `side_change` dengan catatan bahwa physical tool tidak diganti. Action **Reset / Replace Tool** mengembalikan Current Side ke 1. Jalankan `php spark migrate` untuk migration `000011`.

## FIX 13 — RFID + Import/Export Master Data Restored

RFID Capture, Excel Import, dan Export setiap Master Data ditambahkan ulang pada baseline Current Side + FK Fix. Jalankan `php spark migrate` untuk migration `2026-09-30-000012_RestoreRfidImportExport.php`.

Import mendukung Customers, Materials, Slots, Machines, Tools, Rack Tools, Parts, Employees, TPMS Devices, dan Device Assignments. Template XLSX tersedia di `public/templates/import/`. Export tersedia dalam CSV pada setiap halaman master data.

## FIX 14 — Standalone Next Grinding

Master Parts mendukung `process_type=next_grinding`. Next Grinding tidak memiliki mode Auto/Manual. Nilai internal `process_mode=none` hanya dipakai karena kolom database saat ini NOT NULL dan tidak ditampilkan sebagai mode di UI. Multi-process yang menandai proses terakhir sebagai Next Grinding juga menyimpan mode process terakhir sebagai `none`.

## FIX 15 — Machine Utility Runtime Timeline

Runtime Progress dipindahkan dari Production ke halaman `Machine Utility` (`/machine-utility`). Timeline menggunakan posisi jam aktual per Machine, tanggal, Rack Tools, dan 3 shift, dengan state Run, Break, Idle, Alarm, Offline, serta Other Rack saat filter Rack aktif. Tidak ada migration baru.

## 2026-10-05 - Production/Shift Detail Dedupe
START Production sekarang mereuse header Production Part/Process yang belum mencapai target. `production_shift_details` direuse berdasarkan `production_id + work_date + shift_id + operator_employee_id`, sehingga START ulang pada shift/operator yang sama tidak menggandakan target. FINISH hanya menutup shift detail; header Production baru completed ketika GOOD mencapai target. Lihat `REVISION_PRODUCTION_SHIFT_DEDUPE_20261005.md`.
