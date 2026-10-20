-- Per-site notification email address
ALTER TABLE sites ADD COLUMN notify_email TEXT DEFAULT NULL;
