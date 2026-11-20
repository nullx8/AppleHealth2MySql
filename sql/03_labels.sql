-- Display names for metrics (data keeps Apple's names).
ALTER TABLE metric ADD COLUMN IF NOT EXISTS label VARCHAR(100) NULL AFTER name;
UPDATE metric SET label = 'Nicotine'  WHERE name = 'zinc';      -- logged as zinc (no nicotine type in iOS 15)
UPDATE metric SET label = 'Caffeine'  WHERE name = 'caffeine';
