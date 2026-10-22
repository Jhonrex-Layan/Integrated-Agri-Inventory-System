-- Upgrade an EXISTING agri_inventory_db created from the Stage 1 schema. (New installs: just import schema.sql.)
-- Written for MariaDB (XAMPP).
USE agri_inventory_db;
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS voided_by INT UNSIGNED NULL AFTER void_reason,
  ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL AFTER voided_by;
ALTER TABLE stock_movements
  MODIFY reference_type ENUM('sale','receipt','damage','manual') NOT NULL DEFAULT 'manual';
CREATE TABLE IF NOT EXISTS damage_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NOT NULL,
  status ENUM('recorded','refund_requested','resolved','written_off') NOT NULL DEFAULT 'recorded',
  reported_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_damage_status (status),
  CONSTRAINT chk_damage_qty CHECK (quantity > 0),
  CONSTRAINT fk_damage_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_user FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS refund_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  damage_report_id INT UNSIGNED NOT NULL,
  request_ref VARCHAR(40) NOT NULL,
  external_supplier_id INT UNSIGNED NOT NULL,
  external_supplier_product_id INT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  submit_status ENUM('pending','submitted','failed') NOT NULL DEFAULT 'pending',
  submit_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  remote_status ENUM('received','under_review','approved','rejected','refunded') NULL,
  remote_note VARCHAR(255) NULL,
  last_synced_at DATETIME NULL,
  requested_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refund_ref (request_ref),
  KEY idx_refund_submit (submit_status),
  CONSTRAINT fk_refund_damage FOREIGN KEY (damage_report_id) REFERENCES damage_reports(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- Old product codes such as SEED-0001 keep working; new products use the S01 / F01 / AF01 scheme.

-- receipt lines must remember the supplier's delivery line id so the acknowledgment can be (re)sent
ALTER TABLE stock_receipt_items ADD COLUMN IF NOT EXISTS external_delivery_item_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER product_id;
