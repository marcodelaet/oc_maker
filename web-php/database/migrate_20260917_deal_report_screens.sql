ALTER TABLE campaign_deal_daily_reports ADD COLUMN screen_code VARCHAR(128) NOT NULL DEFAULT '' AFTER report_date;
ALTER TABLE campaign_deal_daily_reports ADD INDEX idx_cddr_deal_db (deal_db_id);
ALTER TABLE campaign_deal_daily_reports ADD UNIQUE KEY uniq_deal_report_day_screen (deal_db_id, report_date, screen_code);
ALTER TABLE campaign_deal_daily_reports DROP INDEX uniq_deal_report_date;
