-- Rede vinculada por documento (snapshot no momento do link)
USE oc_maker;

ALTER TABLE document_inventory
  ADD COLUMN IF NOT EXISTS rede_name VARCHAR(120) NULL AFTER inventory_item_id;

UPDATE document_inventory di
INNER JOIN inventory_items ii ON ii.id = di.inventory_item_id
SET di.rede_name = ii.rede_name
WHERE di.rede_name IS NULL OR di.rede_name = '';
