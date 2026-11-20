<?php
/**
 * HTML table with vital statistics for the website (called via HTTP).
 * Same rows and markup as the old AutoExport/vitalstats-data.php, reading the new tables.
 *
 *  - Blood pressure: today (since last sleep start) or, if none, last 24 h
 *  - Blood pressure: 1 month and 3 months (avg, highest/lowest systolic reading)
 *  - Heart rate 1 week (+ resting / walking average)
 *  - Blood oxygen 1 week, respiratory rate 1 week
 *  - Sleep 1 month
 *  - footer: "updated … via" (newest record of any metric, heart rate or blood pressure)
 */

declare(strict_types=1);

require __DIR__ . '/../lib/raw.php';
date_default_timezone_set(config()['timezone']);
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$pdo->exec("SET time_zone = '+00:00'");   // all stored timestamps are UTC

function q(string $sql, array $args = []): object
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetch(PDO::FETCH_OBJ) ?: (object)[];
}

/** UTC 'Y-m-d H:i:s' for a unix timestamp. */
function utc(int $t): string
{
    return gmdate('Y-m-d H:i:s', $t);
}

function metricId(string $name): int
{
    return (int)(q("SELECT id FROM metric WHERE name = ?", [$name])->id ?? 0);
}

function unit(string $name, string $fallback): string
{
    return (string)(q("SELECT unit FROM metric WHERE name = ?", [$name])->unit ?? $fallback);
}

function plural(int $n): string
{
    return $n . ' record' . ($n === 1 ? '' : 's');
}

/** Blood pressure stats since $from: count, averages, highest and lowest systolic reading. */
function bpStats(string $from): object
{
    $s = q("SELECT COUNT(*) n, AVG(systolic) sys, AVG(diastolic) dia FROM blood_pressure WHERE ts > ?", [$from]);
    $s->hi = q("SELECT systolic, diastolic FROM blood_pressure WHERE ts > ? ORDER BY systolic DESC LIMIT 1", [$from]);
    $s->lo = q("SELECT systolic, diastolic FROM blood_pressure WHERE ts > ? ORDER BY systolic ASC LIMIT 1", [$from]);
    return $s;
}

function sampleStats(string $metric, string $from): object
{
    return q("SELECT COUNT(*) n, AVG(qty) avg FROM sample WHERE metric_id = ? AND ts > ?", [metricId($metric), $from]);
}

$r0 = fn($v) => round((float)$v);
$cell = 'style="border: none;"';
$now = time();
$month   = utc($now - 2630000);
$months3 = utc($now - 7889238);
$week    = utc($now - 604800);
$mmHg    = unit('blood_pressure_systolic', 'mmHg');

echo '<table style="border: none;">';

// --- blood pressure "today" = since the last sleep started, else last 24 h ---------------
$sleep = q("SELECT COALESCE(sleep_start, in_bed_start) t FROM sleep_night
            WHERE COALESCE(sleep_start, in_bed_start) IS NOT NULL ORDER BY night DESC LIMIT 1")->t ?? null;
$bp = $sleep ? bpStats($sleep) : (object)['n' => 0];
$label = 'Today';
if ($bp->n < 1) {
    $bp = bpStats(utc($now - 86400));
    $label = '24h';
}
if ($bp->n > 0) {
    echo '<tr><td ' . $cell . '>Blood Pressure <small>(' . $label . ')</small><br /><small>' . plural((int)$bp->n)
       . '</small></td><td ' . $cell . '><strong>' . $r0($bp->sys) . '/' . $r0($bp->dia) . '</strong><br />'
       . $mmHg . '</td></tr>';
}

// --- blood pressure 3 months (the 1-month line was removed from the page) -----------------
$bp = bpStats($months3);
echo '<tr><td ' . $cell . '>Blood Pressure <small>(3 Month)</small><br /><small>' . (int)$bp->n
   . ' records, ' . $r0($bp->hi->systolic ?? 0) . '/' . $r0($bp->hi->diastolic ?? 0) . '|'
   . $r0($bp->lo->systolic ?? 0) . '/' . $r0($bp->lo->diastolic ?? 0) . '</small></td><td ' . $cell . '><strong>'
   . $r0($bp->sys) . '/' . $r0($bp->dia) . '</strong><br />' . $mmHg . '</td></tr>';

// --- heart rate 1 week -----------------------------------------------------------------------
$hr = q("SELECT COUNT(*) n, AVG(bpm_avg) avg FROM heart_rate WHERE ts > ?", [$week]);
$rest = sampleStats('resting_heart_rate', $week);
$walk = sampleStats('walking_heart_rate_average', $week);
echo '<tr><td ' . $cell . '>Heart Rate <small>(1 Week)</small><br /><small>' . plural((int)$hr->n) . '</small></td><td '
   . $cell . '><strong>' . $r0($hr->avg) . ' ' . unit('heart_rate', 'count/min') . '</strong><br /><small>('
   . $r0($rest->avg) . ' Rest / ' . $r0($walk->avg) . ' Walk)</small></td></tr>';

// --- blood oxygen / respiratory rate 1 week --------------------------------------------------
$ox = sampleStats('blood_oxygen_saturation', $week);
echo '<tr><td ' . $cell . '>Blood Oxygen <small>(1 Week)</small><br /><small>' . (int)$ox->n . ' records</small></td><td '
   . $cell . '><strong>' . $r0($ox->avg) . unit('blood_oxygen_saturation', '%') . '</strong></td></tr>';

$rr = sampleStats('respiratory_rate', $week);
echo '<tr><td ' . $cell . '>Respiratory Rate <small>(1 Week)</small><br /><small>' . (int)$rr->n . ' records</small></td><td '
   . $cell . '><strong>' . $r0($rr->avg) . ' ' . unit('respiratory_rate', 'count/min') . '</strong></td></tr>';

// --- sleep 1 month (nights with sleep data) -----------------------------------------------------
$sl = q("SELECT COUNT(*) n, AVG(asleep_h) h FROM sleep_night WHERE asleep_h > 0 AND night > ?",
    [gmdate('Y-m-d', $now - 2630000)]);
echo '<tr><td ' . $cell . '>';
echo 'Sleeping <small>(1 Month)</small><br /><small>' . (int)$sl->n . ' records</small></td><td ' . $cell . '><strong>'
   . round((float)$sl->h, 1) . ' hours</strong>';
echo '</td></tr>';

echo '</table>';

// --- footer: newest record of any kind (sample metrics, heart rate, blood pressure) ---------------
$newest = q("SELECT m.name, m.label, UNIX_TIMESTAMP(x.t) t FROM (SELECT metric_id, MAX(ts) t FROM sample "
          . "WHERE ts <= UTC_TIMESTAMP() GROUP BY metric_id) x JOIN metric m ON m.id = x.metric_id ORDER BY x.t DESC LIMIT 1");
$candidates = [
    (trim((string)($newest->label ?? '')) ?: ucwords(str_replace('_', ' ', (string)($newest->name ?? 'data'))))
                     => (int)($newest->t ?? 0),
    'Heart Rate'     => (int)(q("SELECT UNIX_TIMESTAMP(MAX(ts)) t FROM heart_rate WHERE ts <= UTC_TIMESTAMP()")->t ?? 0),
    'Blood Pressure' => (int)(q("SELECT UNIX_TIMESTAMP(MAX(ts)) t FROM blood_pressure WHERE ts <= UTC_TIMESTAMP()")->t ?? 0),
];
arsort($candidates);
$via = (string)array_key_first($candidates);
$udate = $candidates[$via];

if (!function_exists('HumanAgo') && is_file('/var/www/html/lib/_snip/human.inc.php')) {
    require_once '/var/www/html/lib/_snip/human.inc.php';
}
$ago = function_exists('HumanAgo') ? HumanAgo($udate, 1) : date('M j G:i', $udate);

echo '<span align="right" class="tooltips " title="data is auto collected from serval devices, some from multiple devices, '
   . 'not all data is updated at the same time."><small>updated ' . $ago . ' via ' . $via . '</small></span>';
