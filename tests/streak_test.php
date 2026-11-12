<?php
require __DIR__ . '/../helpers/Streak.php';
use Helpers\Streak;
date_default_timezone_set('Africa/Porto-Novo');

// Reference week: Mon 2026-11-09 .. Sun 2026-11-15. "today" varies per case.
function t($name, $dates, $today, $exp, $expFrozen = null) {
    $r = Streak::compute($dates, new DateTimeImmutable($today));
    $ok = $r['streak'] === $exp && ($expFrozen === null || $r['frozen_dates'] === $expFrozen);
    printf("%s %-62s streak=%d (want %d) frozen=[%s] avail=%s\n", $ok ? 'PASS' : 'FAIL', $name,
        $r['streak'], $exp, implode(',', $r['frozen_dates']), $r['freeze_available'] ? 'y' : 'n');
    return $ok;
}
$all = true;
// Thursday 2026-11-12
$all &= t('no data',                                   [],                                           '2026-11-12', 0);
$all &= t('today done only',                           ['2026-11-12'],                               '2026-11-12', 1);
$all &= t('yesterday done, today pending (old bug => 0)', ['2026-11-11','2026-11-10'],               '2026-11-12', 2);
$all &= t('3 consecutive incl today',                  ['2026-11-12','2026-11-11','2026-11-10'],     '2026-11-12', 3);
$all &= t('one miss bridged by freeze (Tue missed)',   ['2026-11-12','2026-11-11','2026-11-09'],     '2026-11-12', 3, ['2026-11-10']);
$all &= t('two misses same week => breaks at 2nd',     ['2026-11-12','2026-11-09'],                  '2026-11-12', 1);
$all &= t('two consecutive misses => break',           ['2026-11-12','2026-11-08','2026-11-07'],     '2026-11-12', 1);
$all &= t('today pending, yesterday missed, older done => alive via freeze', ['2026-11-10','2026-11-09'], '2026-11-12', 2, ['2026-11-11']);
$all &= t('today+yesterday missed => dead',            ['2026-11-10','2026-11-09'],                  '2026-11-13', 0);
$all &= t('trailing freeze with nothing older is dropped', ['2026-11-12'],                           '2026-11-12', 1, []);
$all &= t('freeze bridges Tue gap between two done days',  ['2026-11-12','2026-11-10'],              '2026-11-12', 2, ['2026-11-11']);
// new week resets: Sun miss (week 45) and Wed miss (week 46) both bridged
$all &= t('one freeze per week, two different weeks',  ['2026-11-12','2026-11-10','2026-11-09','2026-11-08','2026-11-06','2026-11-05'], '2026-11-12', 6, ['2026-11-11','2026-11-07']);
$all &= t('2nd miss in the SAME week (Nov 8 & 6, wk45) breaks', ['2026-11-12','2026-11-10','2026-11-09','2026-11-07','2026-11-05'], '2026-11-12', 4, ['2026-11-11','2026-11-08']);
// long streak > 60 (old LIMIT 60 cap)
$long = []; $d = new DateTimeImmutable('2026-11-12');
for ($i = 0; $i < 90; $i++) { $long[] = $d->modify("-$i day")->format('Y-m-d'); }
$all &= t('90-day streak not capped at 60',            $long,                                        '2026-11-12', 90);
echo $all ? "\nALL PASS\n" : "\nSOME FAILED\n";
