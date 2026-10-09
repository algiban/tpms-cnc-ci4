# QA Validation — FIX-08 Dynamic Shift Target

Date: 2026-09-29

## Static validation

- PHP syntax lint: PASS for 163 PHP files under `app/` and `tests/`.
- No active `MAX(d.target_qty)` aggregation remains in DashboardModel.
- No active source text remains that describes `production_shift_details.target_qty` as carry-over/remaining overall target.
- ProductionModel allows `target_duration_days` and `shifts_per_day`.
- Migration `000007` adds only the two requested planning columns to `productions`.

## Business-rule smoke tests

### Case 1

```text
overall target = 1000
duration       = 5 days
shift/day      = 3
planned shifts = 15
```

Generated targets when every shift meets target:

```text
67,67,67,67,67,67,67,67,67,67,66,66,66,66,66
sum = 1000
```

PASS.

### Case 2 — previous shift under target

```text
overall target  = 1000
shift 1 target  = 67
shift 1 GOOD    = 60
remaining GOOD  = 940
remaining shift = 14
shift 2 target  = ceil(940/14) = 68
```

PASS.

### Case 3

```text
overall target = 1000
duration       = 5 days
shift/day      = 2
shift 1 target = 100
```

PASS.

### Case 4 — plan window exhausted

```text
overall target = 1000
GOOD           = 960
used shifts    = 15 of 15
next shift     = 40
```

Production is not falsely completed and may continue to finish the remaining GOOD target.

PASS.

## PHPUnit

The FIX-08 unit tests are included, but PHPUnit cannot execute in the current QA container because PHP CLI is missing `dom`, `mbstring`, and `xmlwriter`. The planner itself was executed directly with PHP CLI for the smoke tests above.
