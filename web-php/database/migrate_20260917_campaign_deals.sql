-- Deals, criativos, público no inventário e slots da campanha

ALTER TABLE document_inventory
  ADD COLUMN IF NOT EXISTS publico DECIMAL(18, 2) NULL AFTER cpm;

ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS campaign_slots TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER campaign_reviewed_at;

CREATE TABLE IF NOT EXISTS campaign_deals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  deal_id VARCHAR(128) NOT NULL,
  slots TINYINT UNSIGNED NOT NULL DEFAULT 1,
  screen_type VARCHAR(32) NULL,
  target_impressions BIGINT UNSIGNED NULL,
  target_impactos BIGINT UNSIGNED NULL,
  target_consumo DECIMAL(18, 2) NULL,
  deal_value DECIMAL(18, 2) NULL,
  fee_adjust_percent DECIMAL(8, 2) NULL,
  cpm DECIMAL(18, 4) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_document_deal (document_id, deal_id),
  KEY idx_document (document_id),
  CONSTRAINT fk_campaign_deals_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_deal_units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deal_db_id INT UNSIGNED NOT NULL,
  inventory_item_id INT UNSIGNED NOT NULL,
  unit_slots TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_deal_unit (deal_db_id, inventory_item_id),
  CONSTRAINT fk_cdu_deal FOREIGN KEY (deal_db_id) REFERENCES campaign_deals(id) ON DELETE CASCADE,
  CONSTRAINT fk_cdu_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_deal_daily_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deal_db_id INT UNSIGNED NOT NULL,
  report_date DATE NOT NULL,
  requisicoes BIGINT UNSIGNED NULL,
  impressoes BIGINT UNSIGNED NULL,
  impactos BIGINT UNSIGNED NULL,
  moeda VARCHAR(8) NOT NULL DEFAULT 'BRL',
  cpm_aplicado DECIMAL(18, 4) NULL,
  consumo DECIMAL(18, 2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_deal_report_date (deal_db_id, report_date),
  CONSTRAINT fk_cddr_deal FOREIGN KEY (deal_db_id) REFERENCES campaign_deals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_creatives (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(64) NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  file_size INT UNSIGNED NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_document (document_id),
  CONSTRAINT fk_campaign_creatives_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
