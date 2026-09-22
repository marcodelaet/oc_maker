ALTER TABLE campaign_creatives ADD COLUMN duration_seconds DECIMAL(10,3) NULL AFTER height;
ALTER TABLE campaign_creatives ADD COLUMN frame_rate DECIMAL(6,2) NULL AFTER duration_seconds;
