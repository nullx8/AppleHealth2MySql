<?php
/**
 * Chart data for the website, one endpoint for all charts (JSON, oldest first).
 *
 *   chartdata.php?set=<set>&p=<period>
 *
 * Field names match the old AutoExport/test/*-chart-data.php files, so existing
 * Chart.js code only needs the URL changed.
 *
 * set=bp           fields: date, SYS, DIA
 *    p=last (default)  readings since last sleep start (if > 2), else last 15     date " HH:MM"
 *    p=24 | 72 | 7     last 24 h / 3 days / 7 days                               date "MM-DD HH:MM" (7: " HH:MM")
 *    p=30 | 90 | all   last 30 / 90 days / everything                            date "MM-DD HH:MM" (90: "MM-DD")
 *    p=1               daily average, last 90 days                               date "MM-DD"
 * set=bpcounts     number of readings per period (for the period buttons): all, 90, 30, 7, 72
 * set=heartrate    fields: date, heartrate
 *    p=last (default)  last 300 minutes with data                                date " HH:MM"
 *    p=24 | 25 | 72    last 24 h / 25 h / 3 days, per minute                       date " HH:MM"
 *    p=36              48 h before the newest reading, per minute                  date " HH:MM"
 *    p=7 | 90          hourly average                                              date "MM-DD HH:00"
 *    p=all             daily average                                               date "YYYY-MM-DD"
 * set=sleep        fields: date, sleep (hours asleep), inbed     last p nights (default 90)   date "MM-DD"
 * set=weight       fields: date, avg, weight (= avg)             daily average, last p days with data (default 90)
 * set=respiratory  fields: date, respiratory                     daily average, last p days (default 90)
 */

declare(strict_types=1);

require __DIR__ . '/../lib/raw.php';
date_default_timezone_set(config()['timezone']);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');      // public chart data, may be fetched from the website's origin

$set = preg_replace('/[^a-z]/', '', strtolower((string)($_REQUEST['set'] ?? '')));
$p   = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_REQUEST['p'] ?? 'last')));

// ---------------------------------------------------------------------------

// No cache here: the production proxy (get-vitals-bits.php) caches the answers.

$pdo = db();
$pdo->exec("SET time_zone = '+00:00'");     // stored timestamps are UTC; local = ts + tz

function rows(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function metricId(string $name): int
{
    $r = rows("SELECT id FROM metric WHERE name = ?", [$name]);
    return (int)($r[0]['id'] ?? 0);
}

/** UTC 'Y-m-d H:i:s', $seconds ago. */
function ago(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() - $seconds);
}

/** Local-time label from the stored UTC ts + tz offset. */
function label(string $format): string
{
    return "DATE_FORMAT(ts + INTERVAL tz MINUTE, '$format')";
}

const HM   = ' %H:%i';          // " 14:05"
const MDHM = '%m-%d %H:%i';     // "11-16 14:05"
const MD   = '%m-%d';           // "11-16"

$num = function (array $data, array $fields): array {
    foreach ($data as &$r) {
        foreach ($fields as $f) {
            if (isset($r[$f])) {
                $r[$f] = $r[$f] + 0;
            }
        }
    }
    return $data;
};

// ---------------------------------------------------------------------------

switch ($set) {

    case 'bp':
        $sel = fn(string $fmt) => "SELECT " . label($fmt) . " date, ROUND(systolic) SYS, ROUND(diastolic) DIA FROM blood_pressure";
        $data = match ($p) {
            'all' => rows($sel(MDHM) . " ORDER BY ts"),
            '24'  => rows($sel(MDHM) . " WHERE ts > ? ORDER BY ts", [ago(86400)]),
            '72'  => rows($sel(MDHM) . " WHERE ts > ? ORDER BY ts", [ago(3 * 86400)]),
            '7'   => rows($sel(HM)   . " WHERE ts > ? ORDER BY ts", [ago(7 * 86400)]),
            '30'  => rows($sel(MDHM) . " WHERE ts > ? ORDER BY ts", [ago(30 * 86400)]),
            '90'  => rows($sel(MD)   . " WHERE ts > ? ORDER BY ts", [ago(90 * 86400)]),
            '1'   => rows("SELECT DATE_FORMAT(day, '%m-%d') date, ROUND(MAX(IF(m.name = 'blood_pressure_systolic', d.avg, NULL))) SYS,
                                  ROUND(MAX(IF(m.name = 'blood_pressure_diastolic', d.avg, NULL))) DIA
                           FROM daily d JOIN metric m ON m.id = d.metric_id
                           WHERE m.name IN ('blood_pressure_systolic', 'blood_pressure_diastolic') AND d.day > ?
                           GROUP BY day ORDER BY day", [gmdate('Y-m-d', time() - 90 * 86400)]),
            default => (function () use ($sel) {
                // since the last sleep started (if more than 2 readings), else the last 15 readings
                $s = rows("SELECT COALESCE(sleep_start, in_bed_start) t FROM sleep_night
                           WHERE COALESCE(sleep_start, in_bed_start) IS NOT NULL ORDER BY night DESC LIMIT 1");
                $since = $s[0]['t'] ?? null;
                if ($since) {
                    $d = rows($sel(HM) . " WHERE ts > ? ORDER BY ts DESC LIMIT 200", [$since]);
                    if (count($d) > 2) {
                        return array_reverse($d);
                    }
                }
                return array_reverse(rows($sel(HM) . " ORDER BY ts DESC LIMIT 15"));
            })(),
        };
        $data = $num($data, ['SYS', 'DIA']);
        break;

    case 'bpcounts':
        $c = fn(?int $s) => (int)rows("SELECT COUNT(*) n FROM blood_pressure" . ($s ? " WHERE ts > ?" : ''),
            $s ? [ago($s)] : [])[0]['n'];
        $data = ['all' => $c(null), '90' => $c(90 * 86400), '30' => $c(30 * 86400), '7' => $c(7 * 86400), '72' => $c(3 * 86400)];
        break;

    case 'heartrate':
        $perMinute = "SELECT " . label(HM) . " date, ROUND(bpm_avg) heartrate FROM heart_rate";
        $hourly = fn(int $s) => rows("SELECT DATE_FORMAT(h, '%m-%d %H:00') date, ROUND(AVG(a)) heartrate FROM (
                                          SELECT ts + INTERVAL tz MINUTE h, bpm_avg a FROM heart_rate WHERE ts > ?) x
                                      GROUP BY DATE_FORMAT(h, '%Y-%m-%d %H') ORDER BY MIN(h)", [ago($s)]);
        $data = match ($p) {
            '24'  => rows($perMinute . " WHERE ts > ? ORDER BY ts", [ago(86400)]),
            '25'  => rows($perMinute . " WHERE ts > ? ORDER BY ts", [ago(90000)]),
            '72'  => rows($perMinute . " WHERE ts > ? ORDER BY ts", [ago(3 * 86400)]),
            '36'  => rows($perMinute . " WHERE ts > (SELECT MAX(ts) - INTERVAL 48 HOUR FROM heart_rate) ORDER BY ts"),
            '7'   => $hourly(7 * 86400),
            '90'  => $hourly(90 * 86400),
            'all' => rows("SELECT d.day date, ROUND(d.avg) heartrate FROM daily d
                           WHERE d.metric_id = ? ORDER BY d.day", [metricId('heart_rate')]),
            default => array_reverse(rows($perMinute . " ORDER BY ts DESC LIMIT 300")),
        };
        $data = $num($data, ['heartrate']);
        break;

    case 'sleep':
        $n = ctype_digit($p) ? max(1, min(3650, (int)$p)) : 90;
        $data = array_reverse(rows("SELECT DATE_FORMAT(night, '%m-%d') date, ROUND(asleep_h, 1) sleep, ROUND(in_bed_h, 1) inbed
                                    FROM sleep_night WHERE asleep_h > 0 ORDER BY night DESC LIMIT $n"));
        $data = $num($data, ['sleep', 'inbed']);
        break;

    case 'weight':
    case 'bmi':
        $n = ctype_digit($p) ? max(1, min(3650, (int)$p)) : 90;
        $data = array_reverse(rows("SELECT DATE_FORMAT(day, '%m-%d') date, ROUND(avg, 2) avg, ROUND(avg, 2) weight
                                    FROM daily WHERE metric_id = ? ORDER BY day DESC LIMIT $n", [metricId('weight_body_mass')]));
        $data = $num($data, ['avg', 'weight']);
        break;

    case 'respiratory':
        $n = ctype_digit($p) ? max(1, min(3650, (int)$p)) : 90;
        $data = rows("SELECT DATE_FORMAT(day, '%m-%d') date, ROUND(avg, 2) respiratory FROM daily
                      WHERE metric_id = ? AND day > ? ORDER BY day", [metricId('respiratory_rate'), gmdate('Y-m-d', time() - $n * 86400)]);
        $data = $num($data, ['respiratory']);
        break;

    // Today's activity + last 7 days (move = active kcal, exercise = min, stand = hours).
    case 'activity':
        $ids = [];
        foreach (['active_energy', 'apple_exercise_time', 'apple_stand_hour', 'step_count', 'walking_running_distance', 'flights_climbed'] as $n) {
            $ids[$n] = metricId($n);
        }
        $from = date('Y-m-d', strtotime('-6 days'));          // local days (timezone set above)
        $byDay = [];
        foreach (rows("SELECT metric_id, day, total FROM daily WHERE metric_id IN (" . implode(',', $ids) . ") AND day >= ?", [$from]) as $r) {
            $byDay[$r['day']][array_search((int)$r['metric_id'], $ids, true)] = (float)$r['total'];
        }
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $v = $byDay[$d] ?? [];
            $days[] = [
                'date'     => substr($d, 5),
                'weekday'  => date('D', strtotime($d)),
                'move'     => round(($v['active_energy'] ?? 0) / 4.184),   // kJ -> kcal
                'exercise' => round($v['apple_exercise_time'] ?? 0),
                'stand'    => round($v['apple_stand_hour'] ?? 0),
                'steps'    => round($v['step_count'] ?? 0),
                'km'       => round($v['walking_running_distance'] ?? 0, 2),
                'floors'   => round($v['flights_climbed'] ?? 0),
            ];
        }
        // "today" = the 24 hours up to the newest activity data (last sync), so it is always a full day,
        // independent of when the request (or a cache) happens.
        $idList = implode(',', $ids);
        // one MAX per metric (index lookup each); MAX(ts) over "metric_id IN (…)" scans millions of rows (~8 s)
        $end = rows("SELECT MAX(t) t FROM (" . implode(' UNION ALL ', array_map(
            fn($id) => "SELECT MAX(ts) t FROM sample WHERE metric_id = " . (int)$id, $ids)) . ") m")[0]['t'] ?? null;
        $sum = [];
        if ($end) {
            foreach (rows("SELECT metric_id, SUM(qty) total FROM sample
                           WHERE metric_id IN ($idList) AND ts > ? - INTERVAL 24 HOUR AND ts <= ?
                           GROUP BY metric_id", [$end, $end]) as $r) {
                $sum[array_search((int)$r['metric_id'], $ids, true)] = (float)$r['total'];
            }
        }
        $endLocal = $end ? (new DateTimeImmutable($end, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
        $today = [
            'date'     => $endLocal ? $endLocal->format('m-d') : null,
            'until'    => $endLocal ? $endLocal->format('j M H:i') : null,
            'window'   => '24h',
            'move'     => round(($sum['active_energy'] ?? 0) / 4.184),
            'exercise' => round($sum['apple_exercise_time'] ?? 0),
            'stand'    => round($sum['apple_stand_hour'] ?? 0),
            'steps'    => round($sum['step_count'] ?? 0),
            'km'       => round($sum['walking_running_distance'] ?? 0, 2),
            'floors'   => round($sum['flights_climbed'] ?? 0),
        ];
        $data = ['today' => $today, 'days' => $days];
        break;

    // p=30 (default, or any number of days): daily steps + km + 7-day average
    // p=months: average steps per day for every month since the first data (2015)
    case 'steps':
        $sid = metricId('step_count');
        $did = metricId('walking_running_distance');
        if ($p === 'months') {
            $data = rows("SELECT DATE_FORMAT(day, '%Y-%m') date, ROUND(SUM(total) / COUNT(*)) steps, COUNT(*) days
                          FROM daily WHERE metric_id = ? GROUP BY date ORDER BY date", [$sid]);
            $data = $num($data, ['steps', 'days']);
            break;
        }
        $n = ctype_digit($p) ? max(7, min(365, (int)$p)) : 30;
        $from = date('Y-m-d', strtotime('-' . ($n + 6) . ' days'));      // 6 extra days for the first averages
        $s = rows("SELECT day, total FROM daily WHERE metric_id = ? AND day >= ?", [$sid, $from]);
        $k = rows("SELECT day, total FROM daily WHERE metric_id = ? AND day >= ?", [$did, $from]);
        $s = array_column($s, 'total', 'day');
        $k = array_column($k, 'total', 'day');
        $all = [];
        for ($i = $n + 5; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $all[] = ['d' => $d, 'steps' => (float)($s[$d] ?? 0), 'km' => (float)($k[$d] ?? 0)];
        }
        $data = [];
        foreach ($all as $i => $r) {
            if ($i < 6) {
                continue;
            }
            $avg = array_sum(array_column(array_slice($all, $i - 6, 7), 'steps')) / 7;
            $data[] = ['date' => substr($r['d'], 5), 'steps' => round($r['steps']), 'km' => round($r['km'], 2), 'avg7' => round($avg)];
        }
        break;

    // Resting heart rate + HRV: p=months (default, all data) or p=weeks (last 2 years)
    case 'fitness':
        $rid = metricId('resting_heart_rate');
        $hid = metricId('heart_rate_variability');
        $group = $p === 'weeks' ? "DATE_FORMAT(day - INTERVAL WEEKDAY(day) DAY, '%Y-%m-%d')" : "DATE_FORMAT(day, '%Y-%m')";
        $since = $p === 'weeks' ? date('Y-m-d', strtotime('-2 years')) : '2000-01-01';
        $data = rows("SELECT $group date,
                             ROUND(AVG(IF(metric_id = $rid, avg, NULL)), 1) resting,
                             ROUND(AVG(IF(metric_id = $hid, avg, NULL)), 1) hrv
                      FROM daily WHERE metric_id IN ($rid, $hid) AND day >= ?
                      GROUP BY date HAVING resting IS NOT NULL OR hrv IS NOT NULL ORDER BY date", [$since]);
        $data = $num($data, ['resting', 'hrv']);
        break;

    // Smokes (nicotine logged as zinc, 12 mg per smoke) and coffees (caffeine, 70 mg per cup) per day:
    // p=N: the last N days with any record (default 30, no gaps) or p=months (average per logged day, all data)
    case 'intake':
        $zid = metricId('zinc');
        $cid = metricId('caffeine');
        if ($p === 'months') {
            $data = rows("SELECT DATE_FORMAT(day, '%Y-%m') date,
                                 ROUND(AVG(IF(metric_id = $zid, total, NULL)) / 12, 1) smokes,
                                 ROUND(AVG(IF(metric_id = $zid, total, NULL))) nicotine,
                                 ROUND(AVG(IF(metric_id = $cid, total, NULL)) / 70, 1) coffee,
                                 ROUND(AVG(IF(metric_id = $cid, total, NULL))) caffeine
                          FROM daily WHERE metric_id IN ($zid, $cid) GROUP BY date ORDER BY date");
            $data = $num($data, ['smokes', 'nicotine', 'coffee', 'caffeine']);
            break;
        }
        // the last N days that have any record (no gaps for days without logging)
        $n = ctype_digit($p) ? max(7, min(365, (int)$p)) : 30;
        $days = array_column(rows("SELECT DISTINCT day FROM daily WHERE metric_id IN ($zid, $cid) ORDER BY day DESC LIMIT $n"), 'day');
        $byDay = [];
        if ($days) {
            foreach (rows("SELECT metric_id, day, total FROM daily WHERE metric_id IN ($zid, $cid) AND day >= ?", [min($days)]) as $r) {
                $byDay[$r['day']][(int)$r['metric_id'] === $zid ? 'z' : 'c'] = (float)$r['total'];
            }
        }
        $data = [];
        foreach (array_reverse($days) as $d) {
            $data[] = ['date' => (substr($d, 0, 4) === date('Y') ? substr($d, 5) : $d), 'weekday' => date('D', strtotime($d)),
                       'smokes' => round(($byDay[$d]['z'] ?? 0) / 12, 1), 'nicotine' => round($byDay[$d]['z'] ?? 0),
                       'coffee' => round(($byDay[$d]['c'] ?? 0) / 70, 1), 'caffeine' => round($byDay[$d]['c'] ?? 0)];
        }
        break;

    // One ECG recording (Apple Watch, lead I, 512 Hz, µV), reduced to 128 Hz (mean of 4 samples):
    // p=last (default): the newest recording with a usable classification, p=any: the newest of all
    case 'ecg':
        $good = $p === 'any' ? '' : "WHERE classification NOT LIKE 'Inconclusive%'";
        $e = rows("SELECT ts, tz, ts_end, classification, avg_hr, sampling_hz, voltages FROM ecg $good ORDER BY ts DESC LIMIT 1")[0] ?? null;
        if (!$e) {
            $data = null;
            break;
        }
        $v = array_column(json_decode($e['voltages'], true) ?: [], 'voltage');
        $hz = (float)($e['sampling_hz'] ?: 512);
        $step = max(1, (int)round($hz / 128));
        $uv = [];
        foreach (array_chunk($v, $step) as $c) {
            $uv[] = array_sum($c) / count($c);
        }
        // remove baseline wander like Apple's display does: running median 200 ms, then 600 ms = baseline
        $median = function (array $x, int $w): array {
            $n = count($x);
            $h = intdiv($w, 2);
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $win = array_slice($x, max(0, $i - $h), min($n, $i + $h + 1) - max(0, $i - $h));
                sort($win);
                $out[] = $win[intdiv(count($win), 2)];
            }
            return $out;
        };
        $rate = $hz / $step;
        $base = $median($median($uv, (int)round(0.2 * $rate)), (int)round(0.6 * $rate));
        foreach ($uv as $i => $x) {
            $uv[$i] = (int)round($x - $base[$i]);
        }
        $local = (new DateTimeImmutable($e['ts'], new DateTimeZone('UTC')))->modify(((int)$e['tz']) . ' minutes');
        $data = ['date' => $local->format('j M Y H:i'), 'classification' => $e['classification'],
                 'bpm' => $e['avg_hr'] ? (int)$e['avg_hr'] : null, 'hz' => $hz / $step,
                 'seconds' => round(count($v) / $hz, 1), 'uv' => $uv];
        break;

    // Hour-of-day x day grid for the last p days (default 56): m=steps (sum) or m=hr (avg bpm)
    case 'heatmap':
        $n = ctype_digit($p) ? max(7, min(180, (int)$p)) : 56;
        $m = ($_REQUEST['m'] ?? 'steps') === 'hr' ? 'hr' : 'steps';
        $from = gmdate('Y-m-d H:i:s', strtotime(date('Y-m-d', strtotime('-' . ($n - 1) . ' days'))));
        $cells = $m === 'hr'
            ? rows("SELECT DATE(ts + INTERVAL tz MINUTE) d, HOUR(ts + INTERVAL tz MINUTE) h, ROUND(AVG(bpm_avg)) v
                    FROM heart_rate WHERE ts >= ? GROUP BY d, h", [$from])
            : rows("SELECT DATE(ts + INTERVAL tz MINUTE) d, HOUR(ts + INTERVAL tz MINUTE) h, ROUND(SUM(qty)) v
                    FROM sample WHERE metric_id = ? AND ts >= ? GROUP BY d, h", [metricId('step_count'), $from]);
        $days = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $days[] = date('Y-m-d', strtotime("-$i days"));
        }
        $data = ['metric' => $m, 'unit' => $m === 'hr' ? 'bpm' : 'steps', 'days' => $days,
                 'cells' => array_map(fn($c) => [$c['d'], (int)$c['h'], (float)$c['v']], $cells)];
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown set', 'sets' => ['bp', 'bpcounts', 'heartrate', 'sleep', 'weight', 'respiratory',
                                                              'activity', 'steps', 'fitness', 'intake', 'ecg', 'heatmap']]);
        exit;
}

echo json_encode($data, JSON_UNESCAPED_SLASHES);
