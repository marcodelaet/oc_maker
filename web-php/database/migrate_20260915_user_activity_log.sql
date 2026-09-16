-- Log de atividades dos usuários no sistema
USE oc_maker;

CREATE TABLE IF NOT EXISTS user_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    actor_type ENUM('user', 'guest') NOT NULL DEFAULT 'guest',
    actor_label VARCHAR(190) NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NULL,
    entity_id VARCHAR(64) NULL,
    message VARCHAR(500) NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    client_os VARCHAR(64) NULL,
    client_browser VARCHAR(120) NULL,
    client_platform VARCHAR(120) NULL,
    reverse_hostname VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_activity_user (user_id),
    KEY idx_user_activity_action (action),
    KEY idx_user_activity_created (created_at),
    KEY idx_user_activity_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
