<?php
/**
 * Pure API logic — no I/O, no HTTP headers.
 * Included by index.php and directly tested by PHPUnit.
 */

declare(strict_types=1);

function quarterForMonth(int $month): string
{
    return match (true) {
        $month <= 3  => 'Q1',
        $month <= 6  => 'Q2',
        $month <= 9  => 'Q3',
        default      => 'Q4',
    };
}

function slotsForDate(array $tariffs, \DateTimeImmutable $date): array
{
    $y = (int) $date->format('Y');
    $q = quarterForMonth((int) $date->format('n'));
    return $tariffs[(string) $y][$q] ?? [];
}

function buildEvccSlots(array $yamlSlots, \DateTimeImmutable $date): array
{
    $result  = [];
    $tz      = $date->getTimezone();
    $dateStr = $date->format('Y-m-d');

    foreach ($yamlSlots as $slot) {
        $startTs = new \DateTimeImmutable("{$dateStr}T{$slot['from']}:00", $tz);
        if ($slot['to'] === '24:00') {
            $endTs = (new \DateTimeImmutable("{$dateStr}T00:00:00", $tz))->modify('+1 day');
        } else {
            $endTs = new \DateTimeImmutable("{$dateStr}T{$slot['to']}:00", $tz);
        }

        $result[] = [
            'start' => $startTs->format(\DateTimeInterface::RFC3339),
            'end'   => $endTs->format(\DateTimeInterface::RFC3339),
            'value' => round($slot['price_ct_kwh_net'] / 100, 6),
        ];
    }

    return $result;
}

function filterSlotsByRange(array $allSlots, \DateTimeImmutable $from, \DateTimeImmutable $until): array
{
    return array_values(array_filter($allSlots, function (array $slot) use ($from, $until): bool {
        $start = new \DateTimeImmutable($slot['start']);
        $end   = new \DateTimeImmutable($slot['end']);
        return $end > $from && $start < $until;
    }));
}

function buildSlotsForRange(array $tariffs, \DateTimeImmutable $now, int $nextHours): array
{
    $tz         = $now->getTimezone();
    $today      = new \DateTimeImmutable($now->format('Y-m-d'), $tz);
    $until      = $now->modify("+{$nextHours} hours");
    $daysNeeded = (int) ceil($nextHours / 24) + 1;

    $allSlots = [];
    for ($i = 0; $i < $daysNeeded; $i++) {
        $date     = $today->modify("+{$i} days");
        $allSlots = array_merge($allSlots, buildEvccSlots(slotsForDate($tariffs, $date), $date));
    }

    return filterSlotsByRange($allSlots, $now, $until);
}
