-- Vínculos entre nomes de tela da Admooh e códigos internos por Deal

CREATE TABLE IF NOT EXISTS campaign_deal_device_aliases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deal_db_id INT UNSIGNED NOT NULL,
  device_name VARCHAR(512) NOT NULL,
  resolution ENUM('map', 'add', 'ignore') NOT NULL,
  screen_code VARCHAR(255) NOT NULL DEFAULT '',
  inventory_item_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_deal_device (deal_db_id, device_name(191)),
  KEY idx_deal (deal_db_id),
  CONSTRAINT fk_cdda_deal FOREIGN KEY (deal_db_id) REFERENCES campaign_deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
