<?php
/**
 * Small HTML snippets with current health values, called via HTTP:
 *   statichealthvalues.php?data=<key>
 *
 * Same keys and output as the old AutoExport/statichealthvalues.php, but reading
 * the new tables (sample, heart_rate, blood_pressure, sleep_night).
 * Results are cached for a few seconds/minutes per key, as before.
 *
 * Keys: all (JSON with every value), smokeavg, respiratory, pulse, oxypulse, steps, bp, weight, bmi,
 *       bedtime, sleepavg, ping (default)
 */

declare(strict_types=1);

require __DIR__ . '/../lib/raw.php';           // __DIR__ resolves the softlink to this folder
date_default_timezone_set(config()['timezone']);
header('Content-Type: text/html; charset=utf-8');

// ---------------------------------------------------------------------------

/** Cached output: recompute after $ttl seconds; on failure serve the last good value. */
function cached(string $key, int $ttl, callable $compute): string
{
    $file = sys_get_temp_dir() . '/healthvalues_' . $key;
    if (is_file($file) && filemtime($file) > time() - $ttl) {
        return (string)file_get_contents($file);
    }
    try {
        $out = $compute();
    } catch (Throwable) {
        $out = null;
    }
    if ($out !== null) {
        @file_put_contents($file, $out, LOCK_EX);
        return $out;
    }
    return is_file($file) ? (string)file_get_contents($file) : '';
}

/** One row as object (timestamps compared/converted in UTC). */
function row(string $sql, array $args = []): ?object
{
    static $init = false;
    if (!$init) {
        db()->exec("SET time_zone = '+00:00'");
        $init = true;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_OBJ);
    return $r ?: null;
}

function metricId(string $name): int
{
    return (int)(row("SELECT id FROM metric WHERE name = ?", [$name])->id ?? 0);
}

/** Latest value of a single-value metric. */
function latest(string $metric): ?object
{
    return row("SELECT qty, UNIX_TIMESTAMP(ts) t FROM sample WHERE metric_id = ? ORDER BY ts DESC LIMIT 1",
        [metricId($metric)]);
}

/** Sum / avg of a single-value metric since $seconds ago. */
function since(string $metric, int $seconds): object
{
    return row("SELECT COALESCE(SUM(qty), 0) total, AVG(qty) avg, COUNT(*) n FROM sample
                WHERE metric_id = ? AND ts > UTC_TIMESTAMP() - INTERVAL ? SECOND",
        [metricId($metric), $seconds]);
}

function humanAgoText(int $ts): string
{
    if (!function_exists('HumanAgo') && is_file('/var/www/html/lib/_snip/human.inc.php')) {
        require_once '/var/www/html/lib/_snip/human.inc.php';
    }
    if (function_exists('HumanAgo')) {
        return HumanAgo($ts, 1);
    }
    $d = max(0, time() - $ts);
    return match (true) {
        $d < 60    => 'just seconds ago',
        $d < 3600  => floor($d / 60) . ' minutes ago',
        $d < 86400 => floor($d / 3600) . ' hours ago',
        default    => floor($d / 86400) . ' days ago',
    };
}

// ---------------------------------------------------------------------------

$key = (string)($_REQUEST['data'] ?? 'ping');

$handlers = [

    // nicotine (logged as zinc, 12 mg per cigarette) over the last 14 days
    'smokeavg' => fn() => cached('smokeavg', 100, function () {
        $s = since('zinc', 14 * 86400);
        return round($s->total / 14 / 12) . ' Smokes/Day';
    }),

    // average respiratory rate over the last month
    'respiratory' => fn() => cached('respiratory', 30, function () {
        $s = since('respiratory_rate', 2630000);
        return $s->avg > 0 ? (string)round((float)$s->avg) : null;
    }),

    'pulse' => fn() => cached('pulse', 30, function () {
        $hr = row("SELECT bpm_avg FROM heart_rate ORDER BY ts DESC LIMIT 1");
        return $hr ? '<strong>' . round($hr->bpm_avg) . '</strong>' : null;
    }),

    'oxypulse' => fn() => cached('oxypulse', 30, function () {
        $hr = row("SELECT bpm_avg FROM heart_rate ORDER BY ts DESC LIMIT 1");
        $ox = latest('blood_oxygen_saturation');
        if (!$hr || !$ox) {
            return null;
        }
        return 'Pulse <strong>' . round($hr->bpm_avg) . '</strong>, Oxygen <strong>' . round($ox->qty) . '%</strong>';
    }),

    // active energy of the last 24 h; Apple delivers kJ -> shown as kcal ("Calories")
    'steps' => fn() => cached('steps', 30, function () {
        $kj = (float)since('active_energy', 86400)->total;
        if ($kj <= 0) {
            return null;
        }
        return '<strong>' . round($kj / 4.184) . ' Calories</strong> burned. <small>(during the last 24h)</small>';
    }),

    // today's average if there are readings today and the latest is above it, else the latest reading
    'bp' => fn() => cached('bp', 30, function () {
        $last = row("SELECT systolic, diastolic FROM blood_pressure ORDER BY ts DESC LIMIT 1");
        if (!$last) {
            return null;
        }
        $midnightUtc = (new DateTimeImmutable('today midnight'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today = row("SELECT COUNT(*) n, AVG(systolic) sys, AVG(diastolic) dia FROM blood_pressure WHERE ts >= ?",
            [$midnightUtc]);
        if ($today->n > 0 && $last->systolic > $today->sys) {
            return ' <strong>' . round((float)$today->sys) . '/' . round((float)$today->dia) . '</strong> <small> mmHg</small>';
        }
        return '<strong> ' . round($last->systolic) . '/' . round($last->diastolic) . '</strong> <small> mmHg</small>';
    }),

    'weight' => fn() => cached('weight', 360, function () {
        $w = latest('weight_body_mass');
        return $w && $w->qty > 1 ? '<strong>' . round($w->qty, 1) . ' kg</strong>' : null;
    }),

    'bmi' => fn() => cached('bmi', 3, function () {
        $w = latest('weight_body_mass');
        $h = latest('height');
        if (!$w || !$h || $h->qty <= 0) {
            return null;
        }
        $bmi = $w->qty / ($h->qty * $h->qty);
        return $bmi > 1 ? '<strong>' . round($bmi, 1) . '</strong>' : null;
    }),

    // local time of the latest night's bedtime, always given:
    //  1. fell asleep: first sleep segment from the Watch (naps starting 12:00-17:59 only if nothing else)
    //  2. else the nightly summary's start (= Sleep Focus on / start of the sleep session)
    //  3. else the first InBed segment (iPhone, Sleep Focus)
    'bedtime' => fn() => cached('bedtime', 360, function () {
        $night = row("SELECT GREATEST(COALESCE((SELECT MAX(night) FROM sleep_night
                                                WHERE COALESCE(sleep_start, in_bed_start) IS NOT NULL), '1970-01-01'),
                                      COALESCE((SELECT MAX(night) FROM sleep_stage), '1970-01-01')) n")->n ?? null;
        if (!$night || $night === '1970-01-01') {
            return null;
        }
        $fmt = "DATE_FORMAT(%s + INTERVAL tz MINUTE, ' %%H:%%i')";
        $tries = [
            'fell asleep' => "SELECT " . sprintf($fmt, 'start_ts') . " t FROM sleep_stage
                              WHERE night = ? AND value NOT IN ('InBed', 'Awake')
                              ORDER BY HOUR(start_ts + INTERVAL tz MINUTE) BETWEEN 12 AND 17, start_ts LIMIT 1",
            'sleep mode on' => "SELECT " . sprintf($fmt, 'COALESCE(sleep_start, in_bed_start)') . " t FROM sleep_night
                                WHERE night = ? AND COALESCE(sleep_start, in_bed_start) IS NOT NULL",
            'sleep mode on ' => "SELECT " . sprintf($fmt, 'start_ts') . " t FROM sleep_stage
                                 WHERE night = ? AND value = 'InBed' ORDER BY start_ts LIMIT 1",
        ];
        foreach ($tries as $what => $sql) {
            $r = row($sql, [$night]);
            if ($r && $r->t) {
                return '<strong title="' . trim($what) . '">' . $r->t . '</strong>';
            }
        }
        return null;
    }),

    // average sleep over the last 90 nights with sleep data
    'sleepavg' => fn() => cached('sleepavg', 1, function () {
        $r = row("SELECT AVG(asleep_h) h FROM (SELECT asleep_h FROM sleep_night WHERE asleep_h > 0
                  ORDER BY night DESC LIMIT 90) x");
        if (!$r || $r->h <= 1) {
            return null;
        }
        $h = (float)$r->h;
        return '<strong>' . (int)$h . 'h ' . round(($h - (int)$h) * 60) . 'm</strong>';
    }),

    // "updated … ago": newest of heart rate, blood oxygen, blood pressure
    // "technicals": how much data, since when, last sync (cheap queries only – no COUNT(*) over raw)
    'technicals' => fn() => cached('technicals', 300, function () {
        $t = row("SELECT (SELECT SUM(points_new) FROM upload) points,
                         (SELECT MIN(day) FROM daily) first_day,
                         (SELECT COUNT(DISTINCT metric_id) FROM daily) metrics,
                         (SELECT MAX(received_at) FROM upload WHERE automation_name NOT LIKE 'manual:%') last_sync");
        if (!$t || !$t->points) {
            return null;
        }
        // upload.received_at is stored in server time (Asia/Bangkok)
        return '<strong>' . number_format((float)$t->points) . '</strong> records since <strong>'
             . date('M Y', strtotime($t->first_day)) . '</strong> · <strong>' . (int)$t->metrics . '</strong> metrics'
             . ($t->last_sync ? ' · last sync <strong>' . date('j M H:i', strtotime($t->last_sync)) . '</strong>' : '');
    }),

    'ping' => fn() => cached('ping', 180, function () {
        // newest record of any kind (per-metric MAX uses the primary key, so this stays fast)
        $newest = row("SELECT m.name, m.label, UNIX_TIMESTAMP(x.t) t FROM (SELECT metric_id, MAX(ts) t FROM sample "
                    . "WHERE ts <= UTC_TIMESTAMP() GROUP BY metric_id) x JOIN metric m ON m.id = x.metric_id "
                    . "ORDER BY x.t DESC LIMIT 1");
        $candidates = [
            (trim((string)($newest->label ?? '')) ?: ucwords(str_replace('_', ' ', (string)($newest->name ?? 'data'))))
                             => $newest->t ?? 0,
            'Heart Rate'     => row("SELECT UNIX_TIMESTAMP(MAX(ts)) t FROM heart_rate WHERE ts <= UTC_TIMESTAMP()")->t ?? 0,
            'Blood Pressure' => row("SELECT UNIX_TIMESTAMP(MAX(ts)) t FROM blood_pressure WHERE ts <= UTC_TIMESTAMP()")->t ?? 0,
        ];
        arsort($candidates);
        $via = (string)array_key_first($candidates);
        $ts = (int)$candidates[$via];
        if ($ts <= 1000) {
            return null;
        }
        return '<span class="tooltips " data-html="true" title="Health data comes from serval devices,<br />'
             . 'not all data is updated at the same time.<br />last: ' . date('M j G:i', $ts) . ' GMT+7  via '
             . $via . '"><small>updated ' . humanAgoText($ts) . '</small>';
    }),
];

// data=all: every value in one JSON response (for proxies: one request instead of one per value)
if ($key === 'all') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_map(fn($h) => $h(), $handlers), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} else {
    echo ($handlers[$key] ?? $handlers['ping'])();   // unknown key -> "updated … ago", as before
}
