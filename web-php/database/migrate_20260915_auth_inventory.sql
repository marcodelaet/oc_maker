-- Auth, inventário normalizado, grupos de rede e calculadora
USE oc_maker;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  role ENUM('administrador', 'business_intelligence', 'checking', 'financeiro', 'comercial') NOT NULL DEFAULT 'comercial',
  totp_secret VARCHAR(64) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_network_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_network_group_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_networks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  normalized_name VARCHAR(120) NOT NULL,
  group_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_network_normalized (normalized_name),
  KEY idx_network_group (group_id),
  CONSTRAINT fk_network_group FOREIGN KEY (group_id) REFERENCES retail_network_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(64) NOT NULL,
  veiculo VARCHAR(120) NULL,
  denominacao VARCHAR(255) NULL,
  faces INT NULL,
  classe_social VARCHAR(64) NULL,
  regiao VARCHAR(64) NULL,
  estado VARCHAR(8) NULL,
  cidade VARCHAR(120) NULL,
  segmento VARCHAR(120) NULL,
  rede_id INT UNSIGNED NULL,
  rede_name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_inventory_codigo (codigo),
  KEY idx_inventory_rede (rede_id),
  CONSTRAINT fk_inventory_rede FOREIGN KEY (rede_id) REFERENCES retail_networks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_inventory (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  inventory_item_id INT UNSIGNED NOT NULL,
  dias INT NOT NULL DEFAULT 0,
  insercoes DECIMAL(14,2) NOT NULL DEFAULT 0,
  impactos DECIMAL(14,2) NOT NULL DEFAULT 0,
  desconto DECIMAL(8,4) NOT NULL DEFAULT 0,
  bruto_negociado DECIMAL(14,2) NOT NULL DEFAULT 0,
  liquido DECIMAL(14,2) NOT NULL DEFAULT 0,
  cpm DECIMAL(14,4) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_doc_inventory (document_id, inventory_item_id),
  KEY idx_doc_inventory_document (document_id),
  CONSTRAINT fk_doc_inventory_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_inventory_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calculator_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  rede_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  tipo_produto VARCHAR(64) NOT NULL DEFAULT 'PADRÃO',
  tipo_compra ENUM('PROGRAMÁTICA', 'PI') NOT NULL DEFAULT 'PROGRAMÁTICA',
  revenue_varejista_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_calc_document (document_id),
  CONSTRAINT fk_calc_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_calc_rede FOREIGN KEY (rede_id) REFERENCES retail_networks(id) ON DELETE CASCADE,
  CONSTRAINT fk_calc_group FOREIGN KEY (group_id) REFERENCES retail_network_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupo Pão de Açúcar (exemplo)
INSERT IGNORE INTO retail_network_groups (id, name) VALUES (1, 'Grupo Pão de Açúcar');
INSERT IGNORE INTO retail_networks (name, normalized_name, group_id) VALUES
  ('PÃO DE AÇUCAR', 'pao de acucar', 1),
  ('MINUTO PÃO DE AÇUCAR', 'minuto pao de acucar', 1),
  ('MERCADO EXTRA', 'mercado extra', 1),
  ('EXTRA MERCADO', 'extra mercado', 1);
