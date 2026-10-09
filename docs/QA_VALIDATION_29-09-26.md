# QA Validation — 29 September 2026

## PASS

- PHP syntax lint: **160 file** pada `app/` dan `tests/` lolos `php -l`.
- `public/assets/js/planning-grid.js` lolos `node --check`.
- Production quantity smoke test lolos:
  - Target 100, Gross 100, Reject 3 -> Good 97.
  - Remaining Good = 3.
  - Belum completed.
  - Good 100 -> completed.
- Static regression check tidak menemukan lagi pola keputusan aktif `actual_qty` vs `target_qty` untuk completion/carry-over.
- Token TPMS tidak lagi direferensikan oleh view aktif TPMS / Device Assignments.
- Form mutation utama yang dicek memiliki `csrf_field()` sesuai route CSRF baru.

## TEST FILE YANG DITAMBAHKAN

```text
tests/unit/ProductionQuantityTest.php
tests/integration/ProductionFlowBusinessScenarioTest.php
```

## LIMITASI ENVIRONMENT QA

Full PHPUnit tidak dapat dijalankan pada container pemeriksaan karena PHP CLI di environment ini tidak memiliki extension:

```text
dom
mbstring
xmlwriter
```

Pesan PHPUnit:

```text
PHPUnit requires the "dom", "filter", "json", "libxml", "mbstring",
"tokenizer", "xmlwriter" extensions, but the "dom", "mbstring",
"xmlwriter" extensions are not available.
```

Ini adalah keterbatasan environment pemeriksaan, bukan syntax error project. Jalankan PHPUnit kembali pada mesin/server development yang memiliki extension tersebut.

## COMMAND SETELAH EXTRACT

```bash
composer install
php spark migrate
./vendor/bin/phpunit tests/unit/ProductionQuantityTest.php tests/integration/ProductionFlowBusinessScenarioTest.php
```

Setelah itu jalankan manual API regression checklist pada `docs/QA_FIX_01_07.md` sebelum firmware final/commissioning hardware.
