<?php
declare(strict_types=1);

namespace Helpers;

/**
 * Streak calculation with a weekly "freeze".
 *
 * A streak is the number of days with at least one block marked done.
 * Rules:
 *  - Today not being done yet is NOT a miss: counting starts from yesterday
 *    (the day is still in progress).
 *  - Each ISO week (Monday–Sunday) has ONE automatic freeze: the first missed
 *    day of that week doesn't break the streak (it isn't counted either).
 *  - A freeze never covers two days in a row, and a second miss in the same
 *    week breaks the streak.
 *  - A freeze only counts if it bridges two done days: freezing a day that has
 *    no done day before it does nothing.
 *
 * Everything is derived from the done dates — no extra table, nothing to
 * "activate": the user who forgets to open the app still gets protected.
 */
final class Streak
{
    /**
     * @param string[] $doneDates 'Y-m-d' dates having at least one done block
     * @return array{streak:int, freeze_available:bool, freeze_used_this_week:bool, frozen_dates:string[]}
     */
    public static function compute(array $doneDates, ?\DateTimeImmutable $today = null): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $done  = [];
        foreach ($doneDates as $d) {
            $done[(new \DateTimeImmutable((string)$d))->format('Y-m-d')] = true;
        }

        $cursor = isset($done[$today->format('Y-m-d')]) ? $today : $today->modify('-1 day');

        $streak    = 0;
        $frozen    = [];
        $usedWeeks = [];
        $pending   = null; // a freeze waiting for an older done day to bridge to

        for ($i = 0; $i < 800; $i++) {
            $key = $cursor->format('Y-m-d');

            if (isset($done[$key])) {
                $streak++;
                if ($pending !== null) {
                    $frozen[] = $pending;
                    $pending  = null;
                }
            } else {
                $week = $cursor->format('o-W');
                if ($pending !== null || isset($usedWeeks[$week])) break;
                $pending          = $key;
                $usedWeeks[$week] = true;
            }

            $cursor = $cursor->modify('-1 day');
        }

        $currentWeek = $today->format('o-W');
        $usedNow     = false;
        foreach ($frozen as $f) {
            if ((new \DateTimeImmutable($f))->format('o-W') === $currentWeek) {
                $usedNow = true;
                break;
            }
        }

        return [
            'streak'                => $streak,
            'freeze_available'      => !$usedNow,
            'freeze_used_this_week' => $usedNow,
            'frozen_dates'          => array_slice($frozen, 0, 10),
        ];
    }
}
