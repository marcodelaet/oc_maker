ALTER TABLE campaign_deal_daily_reports ADD COLUMN network VARCHAR(128) NOT NULL DEFAULT '' AFTER screen_code;
ALTER TABLE campaign_deal_daily_reports DROP INDEX uniq_deal_report_day_screen;
ALTER TABLE campaign_deal_daily_reports ADD INDEX idx_cddr_deal_network (deal_db_id, network);
ALTER TABLE campaign_deal_daily_reports ADD UNIQUE KEY uniq_deal_report_day_dims (deal_db_id, report_date, screen_code, network);
