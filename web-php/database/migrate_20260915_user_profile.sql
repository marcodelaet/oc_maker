-- Perfil estendido: username, sobrenome, nascimento, telefone
USE oc_maker;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS username VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS last_name VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS birth_date DATE NULL,
  ADD COLUMN IF NOT EXISTS phone_country_code VARCHAR(8) NOT NULL DEFAULT '+55',
  ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL;

ALTER TABLE users ADD UNIQUE KEY uk_users_username (username);
ALTER TABLE users ADD UNIQUE KEY uk_users_phone (phone_country_code, phone);

-- Username inicial (deduplicação feita em setup.php)
UPDATE users
SET username = LOWER(REPLACE(REPLACE(SUBSTRING_INDEX(email, '@', 1), '.', '_'), '-', '_'))
WHERE username IS NULL OR username = '';
