-- Foto de perfil do usuário
USE oc_maker;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(500) NULL;
