-- Troca obrigatória de senha no primeiro acesso
USE oc_maker;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0;
