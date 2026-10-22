-- Upgrade an EXISTING agri_supplier_db created from the Stage 1 schema. (New installs: just import schema.sql.)
-- Written for MariaDB (XAMPP): ADD COLUMN IF NOT EXISTS is MariaDB syntax.
USE agri_supplier_db;
ALTER TABLE purchase_orders
  ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL AFTER rejection_reason,
  ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER submitted_at,
  ADD COLUMN IF NOT EXISTS closed_note VARCHAR(255) NULL AFTER sent_at;
CREATE TABLE IF NOT EXISTS refund_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_ref VARCHAR(40) NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  supplier_product_id INT UNSIGNED NULL,
  product_name VARCHAR(150) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('received','under_review','approved','rejected','refunded') NOT NULL DEFAULT 'received',
  response_note VARCHAR(255) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  client_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refund_ref (request_ref),
  KEY idx_refund_status (status),
  KEY idx_refund_supplier (supplier_id),
  CONSTRAINT chk_refund_qty CHECK (quantity > 0 AND amount >= 0),
  CONSTRAINT fk_refund_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_sp FOREIGN KEY (supplier_product_id) REFERENCES supplier_products(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE SET NULL
) ENGINE=InnoDB;
