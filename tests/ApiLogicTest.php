<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../api/lib.php';

/**
 * Tests für die reine API-Logik in api/lib.php.
 *
 * Abgedeckte Grenzfälle:
 *  - Quartalsgrenzen (Q1→Q2, Q2→Q3, Q3→Q4, Q4→Q1)
 *  - Jahresübergang (31.12. → 1.1.)
 *  - Sommerzeit-Umstellung (Europa/Berlin, März + Oktober)
 *  - 24:00-Slot (wird zu 00:00 des Folgetags)
 *  - Fehlende Tarifdaten (leeres Array)
 *  - Extremwerte next_hours (1, 168)
 *  - Wertumrechnung ct/kWh → €/kWh
 */
final class ApiLogicTest extends TestCase
{
    // ── Fixture-Tarifdaten ────────────────────────────────────────────────────

    /**
     * Minimalset mit Q1–Q4 für 2025 und 2026 (unterschiedliche Preise je Jahr).
     * Q1/Q4: NT 06h + ST 16h + HT 02h + NT 02h
     * Q2/Q3: ganztägig ST
     */
    private function buildTariffs(): array
    {
        $winterDay = [
            ['from' => '00:00', 'to' => '06:00', 'tariff' => 'NT',  'price_ct_kwh_net' => 2.00],
            ['from' => '06:00', 'to' => '20:00', 'tariff' => 'ST',  'price_ct_kwh_net' => 10.00],
            ['from' => '20:00', 'to' => '22:00', 'tariff' => 'HT',  'price_ct_kwh_net' => 18.00],
            ['from' => '22:00', 'to' => '24:00', 'tariff' => 'NT',  'price_ct_kwh_net' => 2.00],
        ];
        $summerDay = [
            ['from' => '00:00', 'to' => '24:00', 'tariff' => 'ST',  'price_ct_kwh_net' => 10.00],
        ];
        $winter26 = [
            ['from' => '00:00', 'to' => '06:00', 'tariff' => 'NT',  'price_ct_kwh_net' => 2.50],
            ['from' => '06:00', 'to' => '20:00', 'tariff' => 'ST',  'price_ct_kwh_net' => 11.00],
            ['from' => '20:00', 'to' => '22:00', 'tariff' => 'HT',  'price_ct_kwh_net' => 19.00],
            ['from' => '22:00', 'to' => '24:00', 'tariff' => 'NT',  'price_ct_kwh_net' => 2.50],
        ];

        return [
            '2025' => [
                'Q1' => $winterDay,
                'Q2' => $summerDay,
                'Q3' => $summerDay,
                'Q4' => $winterDay,
            ],
            '2026' => [
                'Q1' => $winter26,
                'Q2' => $summerDay,
                'Q3' => $summerDay,
                'Q4' => $winter26,
            ],
        ];
    }

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Berlin');
    }

    // =========================================================================
    // quarterForMonth
    // =========================================================================

    public static function monthQuarterProvider(): array
    {
        return [
            'Januar'   => [1,  'Q1'],
            'Februar'  => [2,  'Q1'],
            'März'     => [3,  'Q1'],
            'April'    => [4,  'Q2'],
            'Mai'      => [5,  'Q2'],
            'Juni'     => [6,  'Q2'],
            'Juli'     => [7,  'Q3'],
            'August'   => [8,  'Q3'],
            'September'=> [9,  'Q3'],
            'Oktober'  => [10, 'Q4'],
            'November' => [11, 'Q4'],
            'Dezember' => [12, 'Q4'],
        ];
    }

    #[DataProvider('monthQuarterProvider')]
    public function testQuarterForMonth(int $month, string $expected): void
    {
        self::assertSame($expected, quarterForMonth($month));
    }

    /** Monatsgrenzen: letzter Monat eines Quartals → nächstes Quartal */
    public function testQuarterBoundaryMarchToApril(): void
    {
        self::assertSame('Q1', quarterForMonth(3));
        self::assertSame('Q2', quarterForMonth(4));
    }

    public function testQuarterBoundaryJuneToJuly(): void
    {
        self::assertSame('Q2', quarterForMonth(6));
        self::assertSame('Q3', quarterForMonth(7));
    }

    public function testQuarterBoundarySeptemberToOctober(): void
    {
        self::assertSame('Q3', quarterForMonth(9));
        self::assertSame('Q4', quarterForMonth(10));
    }

    public function testQuarterBoundaryDecemberToJanuary(): void
    {
        self::assertSame('Q4', quarterForMonth(12));
        self::assertSame('Q1', quarterForMonth(1));
    }

    // =========================================================================
    // slotsForDate
    // =========================================================================

    public function testSlotsForDateReturnsCorrectQuarter(): void
    {
        $tariffs = $this->buildTariffs();
        // 2025-Q2: ganztägig ST → 1 Slot
        $date  = new \DateTimeImmutable('2025-05-15', $this->tz());
        $slots = slotsForDate($tariffs, $date);
        self::assertCount(1, $slots);
        self::assertSame('ST', $slots[0]['tariff']);
    }

    public function testSlotsForDateQ1LastDay(): void
    {
        $tariffs = $this->buildTariffs();
        $date    = new \DateTimeImmutable('2025-03-31', $this->tz());
        $slots   = slotsForDate($tariffs, $date);
        self::assertCount(4, $slots);  // winterDay hat 4 Slots
    }

    public function testSlotsForDateQ2FirstDay(): void
    {
        $tariffs = $this->buildTariffs();
        $date    = new \DateTimeImmutable('2025-04-01', $this->tz());
        $slots   = slotsForDate($tariffs, $date);
        self::assertCount(1, $slots);  // summerDay hat 1 Slot
        self::assertSame('ST', $slots[0]['tariff']);
    }

    public function testSlotsForDateQ4LastDay(): void
    {
        $tariffs = $this->buildTariffs();
        $date    = new \DateTimeImmutable('2025-12-31', $this->tz());
        $slots   = slotsForDate($tariffs, $date);
        self::assertCount(4, $slots);
    }

    public function testSlotsForDateJanuaryFirstNextYear(): void
    {
        $tariffs = $this->buildTariffs();
        $date    = new \DateTimeImmutable('2026-01-01', $this->tz());
        $slots   = slotsForDate($tariffs, $date);
        // 2026/Q1 hat die 2026-Winterpreise
        self::assertCount(4, $slots);
        self::assertSame(2.50, $slots[0]['price_ct_kwh_net']);
    }

    public function testSlotsForDateMissingYear(): void
    {
        $tariffs = $this->buildTariffs();
        $date    = new \DateTimeImmutable('2030-06-15', $this->tz());
        $slots   = slotsForDate($tariffs, $date);
        self::assertSame([], $slots);
    }

    public function testSlotsForDateMissingQuarterInExistingYear(): void
    {
        $tariffs = ['2025' => ['Q1' => [['from' => '00:00', 'to' => '24:00', 'tariff' => 'ST', 'price_ct_kwh_net' => 5.0]]]];
        $date    = new \DateTimeImmutable('2025-06-01', $this->tz());
        $slots   = slotsForDate($tariffs, $date);
        self::assertSame([], $slots);
    }

    // =========================================================================
    // buildEvccSlots
    // =========================================================================

    public function testBuildEvccSlotsValueConversion(): void
    {
        $slots = [
            ['from' => '00:00', 'to' => '12:00', 'tariff' => 'NT', 'price_ct_kwh_net' => 2.54],
        ];
        $date   = new \DateTimeImmutable('2025-06-01', $this->tz());
        $result = buildEvccSlots($slots, $date);

        self::assertCount(1, $result);
        self::assertEqualsWithDelta(0.0254, $result[0]['value'], 0.0000001);
    }

    public function testBuildEvccSlotsMidnightEndBecomesNextDay(): void
    {
        $slots = [
            ['from' => '22:00', 'to' => '24:00', 'tariff' => 'NT', 'price_ct_kwh_net' => 2.00],
        ];
        $date   = new \DateTimeImmutable('2025-06-15', $this->tz());
        $result = buildEvccSlots($slots, $date);

        self::assertCount(1, $result);
        // End-Timestamp muss auf 2025-06-16T00:00:00 zeigen
        $end = new \DateTimeImmutable($result[0]['end']);
        self::assertSame('2025-06-16', $end->format('Y-m-d'));
        self::assertSame('00:00:00', $end->format('H:i:s'));
    }

    public function testBuildEvccSlotsRfc3339Format(): void
    {
        $slots = [
            ['from' => '06:00', 'to' => '22:00', 'tariff' => 'ST', 'price_ct_kwh_net' => 10.00],
        ];
        $date   = new \DateTimeImmutable('2025-11-01', $this->tz());
        $result = buildEvccSlots($slots, $date);

        // RFC3339 = "Y-m-d\TH:i:sP"
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result[0]['start']
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result[0]['end']
        );
    }

    public function testBuildEvccSlotsEmptyInput(): void
    {
        $date   = new \DateTimeImmutable('2025-06-01', $this->tz());
        $result = buildEvccSlots([], $date);
        self::assertSame([], $result);
    }

    public function testBuildEvccSlotsStartBeforeEnd(): void
    {
        $slots = [
            ['from' => '00:00', 'to' => '06:00', 'tariff' => 'NT', 'price_ct_kwh_net' => 2.00],
            ['from' => '06:00', 'to' => '24:00', 'tariff' => 'ST', 'price_ct_kwh_net' => 10.00],
        ];
        $date   = new \DateTimeImmutable('2025-03-15', $this->tz());
        $result = buildEvccSlots($slots, $date);

        foreach ($result as $slot) {
            $start        = new \DateTimeImmutable($slot['start']);
            $end          = new \DateTimeImmutable($slot['end']);
            $durationSecs = $end->getTimestamp() - $start->getTimestamp();
            self::assertGreaterThan(0, $durationSecs, 'end must be strictly after start');
        }
    }

    // ── DST: Uhrenumstellung ──────────────────────────────────────────────────

    /**
     * Sommerzeit-Beginn 2026: 29. März, 02:00 → 03:00 (Europa/Berlin).
     * Slot-Grenzen müssen trotzdem korrekte UTC-Offsets haben (+01:00 vor, +02:00 nach).
     */
    public function testBuildEvccSlotsDstSpringForward(): void
    {
        $slots = [
            ['from' => '00:00', 'to' => '06:00', 'tariff' => 'NT', 'price_ct_kwh_net' => 2.00],
            ['from' => '06:00', 'to' => '24:00', 'tariff' => 'ST', 'price_ct_kwh_net' => 10.00],
        ];
        // 2026-03-29 ist der Umstellungstag Winterzeit→Sommerzeit in Deutschland
        $date   = new \DateTimeImmutable('2026-03-29', $this->tz());
        $result = buildEvccSlots($slots, $date);

        self::assertCount(2, $result);

        // Der erste Slot endet um 06:00 Ortszeit, was nach dem Sprung +02:00 sein muss
        $end0 = new \DateTimeImmutable($result[0]['end']);
        self::assertSame('2026-03-29', $end0->setTimezone($this->tz())->format('Y-m-d'));
        self::assertSame('06:00', $end0->setTimezone($this->tz())->format('H:i'));

        // 00:00–06:00 Winterzeit = 5 Stunden effektiv (Stunde 02:00–03:00 fällt weg)
        $start0 = new \DateTimeImmutable($result[0]['start']);
        $durationMin = (int)(($end0->getTimestamp() - $start0->getTimestamp()) / 60);
        self::assertSame(300, $durationMin, 'Slot 00:00–06:00 am DST-Tag dauert 5h (300 min), nicht 6h');

        // Letzter Slot muss am Folgetag 00:00 enden (24:00-Konvention)
        $endLast = new \DateTimeImmutable($result[1]['end']);
        self::assertSame('2026-03-30', $endLast->setTimezone($this->tz())->format('Y-m-d'));
        self::assertSame('00:00', $endLast->setTimezone($this->tz())->format('H:i'));
    }

    /**
     * Sommerzeit-Ende 2026: 25. Oktober, 03:00 → 02:00 (Europa/Berlin).
     * Der Slot 00:00–06:00 dauert an diesem Tag 7h statt 6h.
     */
    public function testBuildEvccSlotsDstFallBack(): void
    {
        $slots = [
            ['from' => '00:00', 'to' => '06:00', 'tariff' => 'NT', 'price_ct_kwh_net' => 2.00],
            ['from' => '06:00', 'to' => '24:00', 'tariff' => 'ST', 'price_ct_kwh_net' => 10.00],
        ];
        // 2026-10-25 ist der Umstellungstag Sommerzeit→Winterzeit
        $date   = new \DateTimeImmutable('2026-10-25', $this->tz());
        $result = buildEvccSlots($slots, $date);

        self::assertCount(2, $result);

        $start0 = new \DateTimeImmutable($result[0]['start']);
        $end0   = new \DateTimeImmutable($result[0]['end']);
        $durationMin = (int)(($end0->getTimestamp() - $start0->getTimestamp()) / 60);
        // 00:00–06:00 Sommerzeit → der Rückfall fügt eine Stunde hinzu → 7h = 420 min
        self::assertSame(420, $durationMin, 'Slot 00:00–06:00 am DST-Rückfall-Tag dauert 7h (420 min)');
    }

    // =========================================================================
    // buildSlotsForRange — Quartal- und Jahresgrenzen
    // =========================================================================

    /** Abfrage, die exakt auf dem Quartalswechsel Q1→Q2 liegt (31. März 23:00 Uhr) */
    public function testQuarterTransitionQ1ToQ2(): void
    {
        $tariffs = $this->buildTariffs();
        // "now" = 2025-03-31 23:00 — letzter Slot Q1 läuft noch
        $now    = new \DateTimeImmutable('2025-03-31 23:00:00', $this->tz());
        $result = buildSlotsForRange($tariffs, $now, 48);

        // Muss Slots aus Q1 (winterDay) und Q2 (summerDay) enthalten
        $tariffTypes = array_unique(array_column($result, 'value'));
        // Q1-NT = 0.02 €/kWh, Q1-HT = 0.18, Q2-ST = 0.10
        $values = array_unique(array_column($result, 'value'));
        sort($values);
        self::assertContains(round(2.00 / 100, 6),  $values, 'NT-Wert aus Q1 muss enthalten sein');
        self::assertContains(round(10.00 / 100, 6), $values, 'ST-Wert muss enthalten sein');

        // Zeitbereich: letzter Slot endet nach now + 48h
        $lastSlot = end($result);
        $end      = new \DateTimeImmutable($lastSlot['end']);
        self::assertGreaterThanOrEqual(
            $now->modify('+48 hours')->getTimestamp(),
            $end->getTimestamp()
        );
    }

    /** Abfrage über den Jahreswechsel (31. Dez 23:00 → 48h) */
    public function testYearTransitionDec31ToJan1(): void
    {
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-12-31 23:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 48);

        self::assertNotEmpty($result);

        // Erster Slot muss in 2025-Q4 starten
        $firstStart = new \DateTimeImmutable($result[0]['start']);
        self::assertLessThanOrEqual(
            (new \DateTimeImmutable('2025-12-31 23:00:00', $this->tz()))->getTimestamp(),
            $firstStart->getTimestamp()
        );

        // Es müssen Slots aus 2026 (unterschiedliche Preise) dabei sein
        $has2026 = false;
        foreach ($result as $slot) {
            $start = new \DateTimeImmutable($slot['start']);
            if ($start->setTimezone($this->tz())->format('Y') === '2026') {
                $has2026 = true;
                break;
            }
        }
        self::assertTrue($has2026, 'Slots aus 2026 müssen nach dem Jahreswechsel enthalten sein');

        // 2026-Q1-Preis (2.50 ct → 0.025) muss vorkommen
        $values = array_column($result, 'value');
        self::assertContains(round(2.50 / 100, 6), $values, '2026-NT-Preis muss im Ergebnis auftauchen');
    }

    /** Abfrage über Q4→Q1 mit Jahreswechsel, nur 1h next_hours */
    public function testYearTransitionMinimalWindow(): void
    {
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-12-31 23:30:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 1);

        // Innerhalb einer Stunde: endet am 1.1. 00:30, liegt im NT-Slot 22:00–24:00
        // → mindestens 1 Slot
        self::assertNotEmpty($result);
    }

    public function testQuarterTransitionQ2ToQ3(): void
    {
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-06-30 22:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 4);

        self::assertNotEmpty($result);
        // Q2 und Q3 sind identisch (summerDay), also alle ST
        foreach ($result as $slot) {
            self::assertEqualsWithDelta(0.10, $slot['value'], 0.0001);
        }
    }

    public function testQuarterTransitionQ3ToQ4(): void
    {
        $tariffs = $this->buildTariffs();
        // Q3 endet 30. September, Q4 beginnt 1. Oktober
        $now    = new \DateTimeImmutable('2025-09-30 23:00:00', $this->tz());
        $result = buildSlotsForRange($tariffs, $now, 48);

        // Q3-ST (0.10) und Q4-NT/ST/HT (0.02, 0.10, 0.18) müssen auftauchen
        $values = array_unique(array_column($result, 'value'));
        self::assertContains(round(18.00 / 100, 6), $values, 'HT aus Q4 muss enthalten sein');
    }

    // ── Extremwerte ───────────────────────────────────────────────────────────

    public function testNextHoursOne(): void
    {
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-06-15 12:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 1);

        self::assertNotEmpty($result);
        $until = $now->modify('+1 hour');
        foreach ($result as $slot) {
            $start = new \DateTimeImmutable($slot['start']);
            $end   = new \DateTimeImmutable($slot['end']);
            // slot must overlap with [now, now+1h]: end > now AND start < until
            self::assertGreaterThan($now->getTimestamp(), $end->getTimestamp(), 'slot must not have expired before now');
            self::assertLessThan($until->getTimestamp(), $start->getTimestamp(), 'slot must start before the window closes');
        }
    }

    public function testNextHoursMax168(): void
    {
        $tariffs = $this->buildTariffs();
        // Startpunkt: Mitte Q2
        $now     = new \DateTimeImmutable('2025-06-01 00:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 168);

        self::assertNotEmpty($result);
        // Letzter Slot muss nach now+168h enden (oder genau dann aufhören)
        $until = $now->modify('+168 hours');
        $last  = end($result);
        $end   = new \DateTimeImmutable($last['end']);
        self::assertGreaterThanOrEqual($until->getTimestamp(), $end->getTimestamp());
    }

    /** 168h über Q2→Q3-Grenze (enthält Jahresanfang → nein, hier Juni→Juli) */
    public function testNextHours168SpansQuarter(): void
    {
        $tariffs = $this->buildTariffs();
        // 7 Tage ab 25. Juni → reicht bis 2. Juli (Q3)
        $now    = new \DateTimeImmutable('2025-06-25 00:00:00', $this->tz());
        $result = buildSlotsForRange($tariffs, $now, 168);

        // Q2 und Q3 sind beide ST → alles 0.10 €/kWh
        foreach ($result as $slot) {
            self::assertEqualsWithDelta(0.10, $slot['value'], 0.0001);
        }
    }

    // ── Fehlende Tarifdaten ───────────────────────────────────────────────────

    public function testMissingTariffDataReturnsEmpty(): void
    {
        $result = buildSlotsForRange([], new \DateTimeImmutable('2025-06-01 12:00:00', $this->tz()), 48);
        self::assertSame([], $result);
    }

    public function testPartialTariffDataMissingYear(): void
    {
        // Nur 2025 vorhanden, aber "now" liegt in 2030
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2030-06-01 12:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 48);
        self::assertSame([], $result);
    }

    // ── Mitternacht als Startpunkt ────────────────────────────────────────────

    public function testStartAtExactMidnight(): void
    {
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-07-01 00:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 24);

        self::assertNotEmpty($result);
        // Erster Slot muss auf oder vor now starten (er läuft zu diesem Zeitpunkt bereits)
        $firstStart = new \DateTimeImmutable($result[0]['start']);
        self::assertLessThanOrEqual($now->getTimestamp(), $firstStart->getTimestamp());
    }

    /** Startpunkt genau auf Q-Grenze 00:00 des ersten Quartalstags */
    public function testStartAtQuarterBoundaryMidnight(): void
    {
        $tariffs = $this->buildTariffs();
        // Erster Tag Q4
        $now    = new \DateTimeImmutable('2025-10-01 00:00:00', $this->tz());
        $result = buildSlotsForRange($tariffs, $now, 2);

        self::assertNotEmpty($result);
        // Der erste Slot muss aus Q4 kommen (NT um 00:00 = 0.02 €/kWh)
        self::assertEqualsWithDelta(0.02, $result[0]['value'], 0.0001);
    }

    // ── Slot-Filterung: Überschneidung, kein Abschneiden ─────────────────────

    public function testSlotsAreNotTrimmedAtBoundary(): void
    {
        // Slot 00:00–06:00 und now = 03:00 → Slot muss trotzdem enthalten sein (end > now)
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-11-15 03:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 1);

        // Slot 00:00–06:00 überlappt noch mit [03:00, 04:00]
        $starts = array_map(
            fn($s) => (new \DateTimeImmutable($s['start']))->setTimezone($this->tz())->format('H:i'),
            $result
        );
        self::assertContains('00:00', $starts, 'Bereits laufender Slot muss im Ergebnis enthalten sein');
    }

    public function testSlotsDoNotIncludeAlreadyExpired(): void
    {
        // Slot 00:00–06:00 und now = 06:00 → Slot darf NICHT mehr enthalten sein (end == now, nicht > now)
        $tariffs = $this->buildTariffs();
        $now     = new \DateTimeImmutable('2025-11-15 06:00:00', $this->tz());
        $result  = buildSlotsForRange($tariffs, $now, 1);

        $starts = array_map(
            fn($s) => (new \DateTimeImmutable($s['start']))->setTimezone($this->tz())->format('H:i'),
            $result
        );
        self::assertNotContains('00:00', $starts, 'Abgelaufener Slot (end == now) darf nicht enthalten sein');
    }

    // ── filterSlotsByRange ────────────────────────────────────────────────────

    public function testFilterSlotsByRangeExcludesExpired(): void
    {
        $tz   = $this->tz();
        $from  = new \DateTimeImmutable('2025-06-15 12:00:00', $tz);
        $until = new \DateTimeImmutable('2025-06-15 14:00:00', $tz);

        $slots = [
            // Abgelaufen: endet um 11:00
            ['start' => '2025-06-15T09:00:00+02:00', 'end' => '2025-06-15T11:00:00+02:00', 'value' => 0.10],
            // Teilweise überlappend: läuft noch, endet 13:00
            ['start' => '2025-06-15T11:00:00+02:00', 'end' => '2025-06-15T13:00:00+02:00', 'value' => 0.10],
            // Vollständig in Fenster
            ['start' => '2025-06-15T13:00:00+02:00', 'end' => '2025-06-15T14:00:00+02:00', 'value' => 0.10],
            // Beginnt nach until
            ['start' => '2025-06-15T14:00:00+02:00', 'end' => '2025-06-15T16:00:00+02:00', 'value' => 0.10],
        ];

        $result = filterSlotsByRange($slots, $from, $until);
        self::assertCount(2, $result);
    }
}
