-- Vincula documentos ao usuário que os criou (controle de acesso comercial na home).

ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS idx_documents_created_by (created_by);
