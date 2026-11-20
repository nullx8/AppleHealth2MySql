AppleHealth2MySql
=================

Sync Apple Health data into a MySQL / MariaDB database, automatically, from your iPhone.

The export from Apple Health to JSON is done by the (paid) iOS app
**Health Auto Export**. This project is the other end: a small PHP receiver that takes
those JSON uploads, keeps the originals, removes duplicates and sorts everything into
proper tables you can query, chart or brag about.

Background story: https://0x8.in.th/random/automating-health-data-apple-health-export-to-mysql

**Live example:** https://0x8.in.th/vitals – my own health page, built on this database
with the code in `production/` (charts, current values, ECG, sleep, activity …).


What it does
------------

1. **Receive** – `ios.php` accepts the upload from Health Auto Export (REST API automation),
   checks a shared secret header and saves the request body untouched as
   `incoming/<timestamp>_<id>.json.gz`. Nothing gets lost, even if the database is down.
2. **Land** – every single data point goes into the `raw` table, with a fingerprint so the
   same reading sent twice (or twenty times, the app loves to resend) is stored only once.
3. **Sort** – right after landing, the points are sorted into typed tables:
   - `sample` – single-value metrics (steps, energy, SpO2, HRV, weight, …), per metric and minute
   - `heart_rate` – min / avg / max per minute
   - `blood_pressure`
   - `sleep_night` and `sleep_stage` – nightly summary and the single sleep phases
   - `workout`, `ecg`, `symptom`, `hr_notification`
   - `daily` – per-day totals / averages for every metric, ready for charts

   Unknown data layouts are not thrown away; they stay in `raw` (marked as unmapped) until
   the sorter learns about them.

All timestamps are stored in UTC, together with the original timezone offset of the reading,
so travelling doesn't turn your sleep chart into modern art.


Requirements
------------

- A Linux server with a web server that runs PHP (Apache, nginx, LiteSpeed … whatever you like)
  - developed on LiteSpeed, nothing server-specific in the code
- **PHP 8.1 or newer** (developed on 8.3) with these extensions: `pdo_mysql`, `zlib`, `mbstring`,
  and `zip` (only for manual imports)
  - generous memory: uploads with a lot of history can be big, `ios.php` allows itself 2 GB,
    the CLI import 4 GB
- **MariaDB 10.5+** (developed on 10.11). MySQL 8.0.21+ should work too, except
  `sql/03_labels.sql`, which uses MariaDB's `ADD COLUMN IF NOT EXISTS`
- **Health Auto Export** for iOS, with the premium features needed for REST API automations
- An iPhone, and optionally an Apple Watch, a blood pressure monitor, a scale … the more
  gadgets, the more data
- Some coffee, 230 volts (110 also works, slightly slower)


Setup
-----

1. **Database**

   ```sh
   mysql -e "CREATE DATABASE health CHARACTER SET utf8mb4"
   mysql -e "CREATE USER 'health'@'localhost' IDENTIFIED BY 'a-good-password'"
   mysql -e "GRANT ALL ON health.* TO 'health'@'localhost'"
   for f in sql/0*.sql; do mysql health < "$f"; done
   ```

2. **Configuration**

   ```sh
   cp config.example.php config.php
   php -r 'echo bin2hex(random_bytes(24)), "\n";'     # use this as your upload token
   ```

   Fill in the database login, your timezone and the token in `config.php`.

3. **Web server** – only `ios.php` needs to be reachable from the phone.
   **Do not** expose the whole folder: `incoming/` contains your raw health data and
   `config.php` your database password. Either publish just `ios.php` (e.g. via a symlink in
   your web root, which is how I do it), or deny access to everything else.
   Making `ios.php` reachable only from your home network or VPN (Tailscale, WireGuard, …)
   is even better.

   The web server user needs write access to `incoming/`.

4. **Health Auto Export** – create a REST API automation:
   - URL: `https://your-server/path/ios.php`
   - Format: JSON
   - Headers: add the header named in `upload_header` (default `x-upload-token`) with your token
   - pick the metrics you want (or just all of them)

   Uploads without the header get a `401`, a wrong value a `403`, and nothing is stored.
   The answer to a good upload lists how many points were received, new and duplicate, per
   metric, so you can see the result right in the app.


Command line tools
------------------

| Command | What for |
|---|---|
| `php import.php` | import manual exports (the app's "Export" button, zip / json / json.gz) dropped into `import/` |
| `php import.php FILE …` | same, for files anywhere |
| `php reimport.php` | land files from `incoming/` that are not in the database yet |
| `php sort.php` | sort pending `raw` rows (normally happens automatically after each upload) |
| `php sort.php --all` | re-sort everything, e.g. after improving the sorter |
| `php status.php` | last uploads, sorting state, newest data per metric |
| `php tools/rehash.php` | recompute duplicate fingerprints after changing the rules |

`tools/check_sync.sh` is a little debugging helper I use while testing (it applies the metric
labels and shows whether the latest upload was sorted). It is **not** needed for production and
is tailored to my setup (path, timezone, metrics), so adjust it or ignore it.


About `production/`
-------------------

This folder is my actual production code, the stuff that feeds the health page on my website:
chart data as JSON, small HTML snippets with current values, a chart script (Chart.js) and a
test page. It is included **as an example** of what you can build on top of the database.

See it in action: **https://0x8.in.th/vitals**

It is tailored to my own application, so your mileage may vary:

- timezone and "GMT+7" are hardcoded
- links point to my own site, and it includes a helper file from my server (with a fallback)
- the "Smokes & Coffee" chart: I log cigarettes as **zinc** (12 mg each), because Apple Health
  has no nicotine type. Creative, yes. Medically advisable, no.
- output is meant for my own pages and does not escape everything, so don't put it on a public
  site as-is

Take it apart, steal the ideas, but don't expect it to work out of the box.


ToDo
----

- ~~make the code usable by anyone else but me~~ ✅ done – sorry it took me 4 years
- ~~create proper stats output by ios.php (so it can be seen in the response window in the export app)~~ ✅ done
- deal with the app to ensure it exports in the background
  (still somewhat up to iOS's mood of the day)
- a generic, configurable version of the `production/` charts


Changelog
---------

- **2026-11** – complete rewrite
  - raw uploads are archived before anything else happens
  - all data points land in `raw` with duplicate detection, then get sorted into typed tables
  - support for heart rate, blood pressure, sleep phases, workouts, ECG, symptoms and
    heart rate notifications
  - shared secret header for uploads
  - CLI tools for manual imports, re-imports, sorting and status
- **2022-06** – collection of basic structural ideas (tables named after the data set, date as
  primary key, lots of optimism)


License
-------

GPL v3, see [LICENSE](LICENSE).
