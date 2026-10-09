# TPMS CI4 — Reapply Monitoring & Cycle Time Revisions

Base: `tpms-ci4-production-shift-dedupe-final-20261005(1).zip`

## Scope
Only the requested revisions were reapplied.

### 1. Monitoring — AVG Total Production per current shift
- Current shift is resolved from server time and master `shifts`.
- Overnight shift uses the operational `work_date` (date when the shift started).
- Header production value aggregates `production_shift_details` for exactly `work_date + current shift_id`.
- Monitoring does not fail when shift master is temporarily invalid/empty; it falls back to the existing UI shift resolver and returns zero shift aggregate.
- New cache key avoids stale monitoring payload from the previous implementation.

### 2. Monitoring — Recent Production History per shift
- Source changed from `productions` to `production_shift_details`.
- One row represents one shift detail.
- Shows work date, shift, production, part/process, operator, GOOD, NC, target, progress, and detail status.

### 3. Production Detail — cycle section removed
- `Cycle Detail · Machine Time & Loading Time` was removed from Production Detail.
- `ProductionController::detail()` no longer queries `production_cycles`.
- Production detail still opens by `production_shift_details.id`.
- Added `operator_employee_id` to `detailRecord()` so the Cycle Time Report link does not trigger an undefined-key error.
- A button links to Cycle Time Report with date/shift/machine/operator/part/process filters.

### 4. Cycle Time Report — per Date + Shift + Machine + Operator + Part + Process
- Added Shift and Operator filters.
- Summary is grouped by Date + Shift + Machine + Operator + Part + Process.
- Detail endpoint loads individual completed production cycles only when the Detail button is clicked.
- Detail includes completion time, production code, machine time, loading time, cycle time, standard cycle, and variance.
- Added optional per-cycle `operator_employee_id` snapshot migration so operator replacement does not rewrite historical cycle ownership.
- Compatibility guard: Cycle Time Report and cycle writes still work before the migration is run by falling back to `production_shift_details.operator_employee_id`.

## Migration
Recommended after replacing the project:

```bash
php spark migrate
```

Migration added:

`2026-10-06-000001_AddOperatorEmployeeToProductionCycles.php`

The page can still load before migration, but run the migration for accurate cycle attribution when an operator is replaced inside the same shift detail.

## About port 8080
`Failed to listen on localhost:8080 (reason: Address already in use)` is not an application bug. Another process already owns port 8080. Check it with:

```bash
lsof -nP -iTCP:8080 -sTCP:LISTEN
```

Stop the old process if it is no longer needed, or explicitly run:

```bash
php spark serve --port 8081
```

## QA
- 198 PHP files: syntax OK.
- Monitoring inline JavaScript: syntax OK.
- Cycle Time Report detail JavaScript: syntax OK.
- No simulator/API request contract change is required for these revisions.
