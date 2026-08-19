-- Add WordPress database connection fields to sites
ALTER TABLE sites ADD COLUMN wp_db_host TEXT DEFAULT NULL;
ALTER TABLE sites ADD COLUMN wp_db_name TEXT DEFAULT NULL;
ALTER TABLE sites ADD COLUMN wp_db_user TEXT DEFAULT NULL;
ALTER TABLE sites ADD COLUMN wp_db_pass TEXT DEFAULT NULL;
ALTER TABLE sites ADD COLUMN wp_table_prefix TEXT DEFAULT 'wp_';
ALTER TABLE sites ADD COLUMN scan_mode TEXT NOT NULL DEFAULT 'http';
