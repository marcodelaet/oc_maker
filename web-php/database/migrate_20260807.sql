-- Migração para bases já existentes (executar uma vez)
USE oc_maker;

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS inicio DATE NULL AFTER agencia,
    ADD COLUMN IF NOT EXISTS termino DATE NULL AFTER inicio,
    ADD COLUMN IF NOT EXISTS tipo_deal VARCHAR(20) NULL AFTER tipo_venda,
    ADD COLUMN IF NOT EXISTS deal_id VARCHAR(255) NULL AFTER planejador_ssp,
    ADD COLUMN IF NOT EXISTS oc_informe_ssp VARCHAR(255) NULL AFTER deal_id,
    ADD COLUMN IF NOT EXISTS checking_fotografico TINYINT(1) NOT NULL DEFAULT 1 AFTER oc_informe_ssp,
    ADD COLUMN IF NOT EXISTS relatorios_adicionais TINYINT(1) NOT NULL DEFAULT 0 AFTER checking_fotografico,
    ADD COLUMN IF NOT EXISTS prazo_pagamento INT NOT NULL DEFAULT 15 AFTER relatorios_adicionais,
    ADD COLUMN IF NOT EXISTS prazo_unidade VARCHAR(10) NOT NULL DEFAULT 'DFM' AFTER prazo_pagamento,
    ADD COLUMN IF NOT EXISTS total_insercoes BIGINT UNSIGNED NULL AFTER total_lojas,
    ADD COLUMN IF NOT EXISTS total_impactos BIGINT UNSIGNED NULL AFTER total_insercoes,
    ADD COLUMN IF NOT EXISTS budget_bruto DECIMAL(18, 2) NULL AFTER total_impactos,
    ADD COLUMN IF NOT EXISTS budget_liquido DECIMAL(18, 2) NULL AFTER budget_bruto,
    ADD COLUMN IF NOT EXISTS source_path VARCHAR(500) NULL AFTER source_file;

ALTER TABLE documents DROP INDEX IF EXISTS uk_document_id;
