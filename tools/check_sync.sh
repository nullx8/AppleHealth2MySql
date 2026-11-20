#!/bin/sh
# Applies metric labels and shows whether the latest upload was sorted into the typed tables.
cd /var/www/Local/HealtAutoExport || exit 1
mysql -uroot health < sql/03_labels.sql
mysql -uroot health -e "
SELECT id, received_at, automation_name, status, points_received recv, points_new new
  FROM upload ORDER BY id DESC LIMIT 2;
SELECT CASE sorted WHEN 0 THEN 'pending' WHEN 1 THEN 'sorted' WHEN 2 THEN 'UNMAPPED' WHEN 3 THEN 'sorted+extra' END state,
       COUNT(*) n
  FROM raw WHERE upload_id = (SELECT MAX(id) FROM upload) GROUP BY sorted;
SELECT COALESCE(m.label, m.name) metric, CONVERT_TZ(s.ts,'+00:00','+07:00') local_time, s.qty, m.unit
  FROM sample s JOIN metric m ON m.id = s.metric_id
 WHERE m.name IN ('zinc','caffeine') AND s.ts > NOW() - INTERVAL 3 DAY ORDER BY s.ts;
SELECT COALESCE(m.label, m.name) metric, d.day, d.n, d.total
  FROM daily d JOIN metric m ON m.id = d.metric_id
 WHERE m.name IN ('zinc','caffeine') AND d.day >= CURDATE() - INTERVAL 3 DAY ORDER BY d.day, metric;
SELECT 'sample' tbl, COUNT(*) n FROM sample UNION ALL SELECT 'heart_rate', COUNT(*) FROM heart_rate
 UNION ALL SELECT 'sleep_night', COUNT(*) FROM sleep_night UNION ALL SELECT 'daily', COUNT(*) FROM daily;"
