<?php

namespace App\Services;

use DateTimeImmutable;
use DomainException;

final class ProductionRules
{
    public const DEFECTS = ['Material', 'Short Shot', 'Bari', 'Dimensi', 'Flash', 'Silver Marks', 'Others'];

    public static function integer($value, string $label, int $minimum = 0): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)) {
            throw new DomainException("{$label} harus bilangan bulat.");
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $minimum || $number > 2147483647) {
            throw new DomainException("{$label} di luar batas {$minimum}–2147483647.");
        }
        return $number;
    }

    public static function text($value, string $label, int $max = 100): string
    {
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new DomainException("{$label} wajib diisi, maksimal {$max} karakter.");
        }
        return trim($value);
    }



    public static function optionalText($value, string $label, int $max = 100): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::text($value, $label, $max);
    }

    public static function alarmKind($value): string
    {
        $kind = self::text($value, 'kind', 50);
        if (!in_array($kind, ['tool_broken', 'machine_fault'], true)) {
            throw new DomainException('kind harus tool_broken atau machine_fault.');
        }

        return $kind;
    }

    public static function alarmSeverity($value, string $kind): string
    {
        if ($kind === 'tool_broken') {
            return 'critical';
        }

        $severity = strtolower(str_replace('-', '_', self::text($value, 'severity', 30)));
        if (!in_array($severity, ['non_critical', 'critical'], true)) {
            throw new DomainException('severity harus non_critical atau critical.');
        }

        return $severity;
    }

    public static function alarmEffect(string $kind, string $severity): string
    {
        if ($kind === 'tool_broken' || $severity === 'critical') {
            return 'service_stop';
        }

        return 'pause';
    }

    public static function uid($value): string
    {
        $uid = strtoupper(str_replace([':', '-', ' '], '', self::text($value, 'RFID')));
        if (!preg_match('/^(?:[A-F0-9]{2}){4,10}$/D', $uid)) {
            throw new DomainException('RFID harus UID hex 4–10 byte, contoh A1B2C3D4.');
        }
        return $uid;
    }

    public static function date($value): string
    {
        $value = self::text($value, 'Tanggal', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Tanggal tidak valid.');
        }
        return $value;
    }

    public static function shift(array $shifts, DateTimeImmutable $now): array
    {
        $matches = [];
        $time = $now->format('H:i:s');
        foreach ($shifts as $shift) {
            $start = $shift['start_time'];
            $end = $shift['end_time'];
            if ($start === $end) {
                throw new DomainException('Jam awal dan akhir shift tidak boleh sama.');
            }
            $overnight = $start > $end;
            if ((!$overnight && $time >= $start && $time < $end)
                || ($overnight && ($time >= $start || $time < $end))) {
                $shift['work_date'] = ($overnight && $time < $end)
                    ? $now->modify('-1 day')->format('Y-m-d') : $now->format('Y-m-d');
                $matches[] = $shift;
            }
        }
        if (count($matches) !== 1) {
            throw new DomainException('Jam shift kosong atau tumpang tindih. Periksa master shifts.');
        }
        return $matches[0];
    }

    public static function defects($raw, int $actual): array
    {
        if (!is_array($raw) || array_diff(array_keys($raw), self::DEFECTS)) {
            throw new DomainException('Kategori cacat tidak valid.');
        }
        $result = [];
        foreach (self::DEFECTS as $type) {
            $result[$type] = self::integer($raw[$type] ?? 0, $type);
        }
        if (array_sum($result) > $actual) {
            throw new DomainException('Total part cacat melebihi aktual produksi. Satu part hanya satu kategori cacat.');
        }
        return $result;
    }

    public static function period(array $query): array
    {
        $mode = $query['mode'] ?? 'today';
        $month = $query['month'] ?? date('Y-m');
        if ($mode === 'today') {
            $start = $end = date('Y-m-d');
        } elseif ($mode === 'month') {
            if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
                throw new DomainException('Bulan tidak valid.');
            }
            $start = self::date($month . '-01');
            $end = (new DateTimeImmutable($start))->format('Y-m-t');
        } elseif ($mode === 'range') {
            $start = self::date($query['start'] ?? '');
            $end = self::date($query['end'] ?? '');
        } else {
            throw new DomainException('Mode tanggal tidak valid.');
        }
        if ($start > $end || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 366) {
            throw new DomainException('Rentang tanggal maksimal 367 hari dan tanggal awal tidak boleh melebihi akhir.');
        }
        return compact('mode', 'start', 'end', 'month');
    }
}
