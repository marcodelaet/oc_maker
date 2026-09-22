-- Gerenciamento de campanhas: workflow, telas e revisões de inventário

ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'administrador',
    'business_intelligence',
    'checking',
    'financeiro',
    'comercial',
    'programatica'
  ) NOT NULL DEFAULT 'comercial';

ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS campaign_workflow_status ENUM(
    'aguardando_aprovacao',
    'aprovada',
    'rejeitada',
    'finalizada_pausada'
  ) NOT NULL DEFAULT 'aguardando_aprovacao' AFTER pdf_path,
  ADD COLUMN IF NOT EXISTS campaign_rejection_reason TEXT NULL AFTER campaign_workflow_status,
  ADD COLUMN IF NOT EXISTS campaign_reviewed_by INT UNSIGNED NULL AFTER campaign_rejection_reason,
  ADD COLUMN IF NOT EXISTS campaign_reviewed_at TIMESTAMP NULL AFTER campaign_reviewed_by,
  ADD KEY IF NOT EXISTS idx_campaign_workflow (campaign_workflow_status);

UPDATE documents
SET campaign_workflow_status = 'finalizada_pausada'
WHERE campaign_workflow_status = 'aguardando_aprovacao';

CREATE TABLE IF NOT EXISTS inventory_screens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT UNSIGNED NOT NULL,
  screen_code VARCHAR(64) NOT NULL,
  face_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
  cms VARCHAR(32) NULL,
  os_name VARCHAR(64) NULL,
  is_online TINYINT(1) NOT NULL DEFAULT 1,
  offline_duration INT UNSIGNED NULL,
  offline_unit ENUM('horas', 'dias', 'meses', 'anos', 'nunca') NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_screen_code (screen_code),
  KEY idx_inventory_item (inventory_item_id),
  CONSTRAINT fk_screen_inventory_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_unit_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  inventory_item_id INT UNSIGNED NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  rejection_reason TEXT NULL,
  replacement_codigo VARCHAR(64) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_doc_inventory (document_id, inventory_item_id),
  KEY idx_document (document_id),
  CONSTRAINT fk_cur_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_cur_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_screen_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  inventory_item_id INT UNSIGNED NOT NULL,
  face_number TINYINT UNSIGNED NOT NULL,
  screen_code VARCHAR(64) NOT NULL,
  cms VARCHAR(32) NULL,
  os_name VARCHAR(64) NULL,
  is_online TINYINT(1) NOT NULL DEFAULT 1,
  offline_duration INT UNSIGNED NULL,
  offline_unit ENUM('horas', 'dias', 'meses', 'anos', 'nunca') NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_doc_item_face (document_id, inventory_item_id, face_number),
  KEY idx_document (document_id),
  CONSTRAINT fk_csr_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_csr_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
