-- =====================================================================
-- Agri-Supply Inventory Management System  |  database: agri_inventory_db
-- Requires MariaDB 10.4+ (XAMPP 8.x) or MySQL 8.0.16+ (CHECK constraints)
-- Import via phpMyAdmin > Import, or:  mysql -u root < schema.sql
-- NOTE: supplier IDs here are integration references only. There are NO
-- foreign keys to the Supplier Management database.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS agri_inventory_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agri_inventory_db;
SET NAMES utf8mb4;

CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  email          VARCHAR(150) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('administrator','inventory_staff','approving_officer') NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80) NOT NULL,
  description  VARCHAR(255) NULL,
  is_archived  TINYINT(1) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE products (
  id                            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_code                  VARCHAR(30) NOT NULL,
  name                          VARCHAR(150) NOT NULL,
  description                   TEXT NULL,
  category_id                   INT UNSIGNED NOT NULL,
  unit                          VARCHAR(20) NOT NULL,
  cost_price                    DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  quantity                      DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  status                        ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  -- integration references (plain integers, no cross-database FK)
  external_supplier_id          INT UNSIGNED NULL,
  external_supplier_product_id  INT UNSIGNED NULL,
  external_supplier_sku         VARCHAR(60) NULL,
  created_by                    INT UNSIGNED NULL,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_code (product_code),
  KEY idx_products_name (name),
  KEY idx_products_category (category_id),
  KEY idx_products_status (status),
  KEY idx_products_ext_supplier (external_supplier_id),
  KEY idx_products_ext_sp (external_supplier_product_id),
  CONSTRAINT chk_products_qty CHECK (quantity >= 0),
  CONSTRAINT chk_products_prices CHECK (cost_price >= 0 AND selling_price >= 0 AND reorder_level >= 0),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_products_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- sales ----------
CREATE TABLE sales (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_no          VARCHAR(30) NOT NULL,
  sale_date        DATETIME NOT NULL,
  customer_name    VARCHAR(120) NULL,
  subtotal         DECIMAL(14,2) NOT NULL,
  discount_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount     DECIMAL(14,2) NOT NULL,
  payment_method   ENUM('cash','card','bank_transfer','e_wallet','credit') NOT NULL DEFAULT 'cash',
  amount_tendered  DECIMAL(14,2) NULL,
  status           ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  void_reason      VARCHAR(255) NULL,
  voided_by        INT UNSIGNED NULL,
  voided_at        DATETIME NULL,
  created_by       INT UNSIGNED NOT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_no (sale_no),
  KEY idx_sales_date (sale_date),
  CONSTRAINT chk_sales_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND total_amount >= 0),
  CONSTRAINT fk_sales_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id          INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NOT NULL,
  product_code     VARCHAR(30)  NOT NULL,     -- snapshots preserve history
  product_name     VARCHAR(150) NOT NULL,
  unit_cost        DECIMAL(12,2) NOT NULL,
  unit_price       DECIMAL(12,2) NOT NULL,
  quantity         DECIMAL(12,2) NOT NULL,
  discount_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total       DECIMAL(14,2) NOT NULL,
  KEY idx_si_sale (sale_id),
  KEY idx_si_product (product_id),
  CONSTRAINT chk_si_qty CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0),
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- supplier delivery receipts ----------
-- One local receipt per external delivery (UNIQUE external_delivery_id) = duplicate protection.
-- Stock only increases when status moves to 'accepted', inside one DB transaction.
CREATE TABLE stock_receipts (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no            VARCHAR(30) NOT NULL,
  receipt_ref           VARCHAR(60) NOT NULL,   -- idempotency key sent back in the acknowledgment
  external_delivery_id  INT UNSIGNED NOT NULL,
  external_delivery_ref VARCHAR(40) NULL,
  external_po_id        INT UNSIGNED NOT NULL,
  external_po_number    VARCHAR(30) NULL,
  external_supplier_id  INT UNSIGNED NOT NULL,
  status                ENUM('draft','accepted','rejected') NOT NULL DEFAULT 'draft',
  ack_status            ENUM('not_required','pending','sent','failed') NOT NULL DEFAULT 'not_required',
  ack_attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ack_last_error        VARCHAR(255) NULL,
  ack_sent_at           DATETIME NULL,
  notes                 TEXT NULL,
  received_by           INT UNSIGNED NOT NULL,
  received_at           DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipts_no (receipt_no),
  UNIQUE KEY uq_receipts_ref (receipt_ref),
  UNIQUE KEY uq_receipts_ext_delivery (external_delivery_id),
  KEY idx_receipts_status (status, ack_status),
  CONSTRAINT fk_receipts_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE stock_receipt_items (
  id                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id                   INT UNSIGNED NOT NULL,
  product_id                   INT UNSIGNED NULL,       -- must be mapped before acceptance
  external_delivery_item_id    INT UNSIGNED NOT NULL,
  external_po_item_id          INT UNSIGNED NOT NULL,
  external_supplier_product_id INT UNSIGNED NOT NULL,
  item_name                    VARCHAR(150) NOT NULL,
  qty_delivered_reported       DECIMAL(12,2) NOT NULL,
  qty_rejected_reported        DECIMAL(12,2) NOT NULL DEFAULT 0,
  qty_accepted                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_cost                    DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sri_line (receipt_id, external_delivery_item_id),
  CONSTRAINT chk_sri_qty CHECK (qty_accepted >= 0
        AND qty_accepted <= qty_delivered_reported - qty_rejected_reported),
  CONSTRAINT fk_sri_receipt FOREIGN KEY (receipt_id) REFERENCES stock_receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- stock movements (complete history) ----------
CREATE TABLE stock_movements (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  movement_type   ENUM('stock_in','stock_out','adjustment_in','adjustment_out',
                       'damaged','sale','delivery_receipt','sale_void') NOT NULL,
  quantity        DECIMAL(12,2) NOT NULL,          -- always positive; type gives direction
  balance_after   DECIMAL(12,2) NOT NULL,
  reason          VARCHAR(255) NULL,
  reference_type  ENUM('sale','receipt','damage','manual') NOT NULL DEFAULT 'manual',
  reference_id    INT UNSIGNED NULL,
  review_status   ENUM('not_required','pending','reviewed') NOT NULL DEFAULT 'not_required',
  reviewed_by     INT UNSIGNED NULL,
  reviewed_at     DATETIME NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sm_product_date (product_id, created_at),
  KEY idx_sm_type (movement_type),
  KEY idx_sm_ref (reference_type, reference_id),
  KEY idx_sm_review (review_status),
  CONSTRAINT chk_sm_qty CHECK (quantity > 0 AND balance_after >= 0),
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sm_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sm_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- damaged goods and supplier refund requests ----------
CREATE TABLE damage_reports (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  quantity       DECIMAL(12,2) NOT NULL,
  unit_cost      DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_cost     DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason         VARCHAR(255) NOT NULL,
  status         ENUM('recorded','refund_requested','resolved','written_off') NOT NULL DEFAULT 'recorded',
  reported_by    INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_damage_status (status),
  CONSTRAINT chk_damage_qty CHECK (quantity > 0),
  CONSTRAINT fk_damage_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_user FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- request_ref is the idempotency key sent to the Supplier system. submit_status tracks delivery of the request;
-- remote_status mirrors the supplier's decision (pulled from the API, never invented locally).
CREATE TABLE refund_requests (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  damage_report_id      INT UNSIGNED NOT NULL,
  request_ref           VARCHAR(40) NOT NULL,
  external_supplier_id  INT UNSIGNED NOT NULL,
  external_supplier_product_id INT UNSIGNED NULL,
  amount                DECIMAL(14,2) NOT NULL,
  submit_status         ENUM('pending','submitted','failed') NOT NULL DEFAULT 'pending',
  submit_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error            VARCHAR(255) NULL,
  remote_status         ENUM('received','under_review','approved','rejected','refunded') NULL,
  remote_note           VARCHAR(255) NULL,
  last_synced_at        DATETIME NULL,
  requested_by          INT UNSIGNED NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refund_ref (request_ref),
  KEY idx_refund_submit (submit_status),
  CONSTRAINT fk_refund_damage FOREIGN KEY (damage_report_id) REFERENCES damage_reports(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- audit, sync, settings ----------
CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60) NOT NULL,
  entity_type  VARCHAR(40) NOT NULL,
  entity_id    INT UNSIGNED NULL,
  details      TEXT NULL,
  ip_address   VARCHAR(45) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE api_sync_logs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction        ENUM('pull','push') NOT NULL,
  method           VARCHAR(8)   NOT NULL,
  endpoint         VARCHAR(200) NOT NULL,
  http_status      SMALLINT UNSIGNED NULL,          -- NULL = no response (connection failure)
  status           ENUM('success','failed','pending_retry','resolved') NOT NULL,
  idempotency_key  VARCHAR(80) NULL,
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  error_message    VARCHAR(255) NULL,
  receipt_id       INT UNSIGNED NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_asl_status (status),
  KEY idx_asl_created (created_at),
  CONSTRAINT fk_asl_receipt FOREIGN KEY (receipt_id) REFERENCES stock_receipts(id) ON DELETE SET NULL,
  CONSTRAINT fk_asl_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- key/value store, e.g. last_successful_sync, api_connection_status
CREATE TABLE settings (
  setting_key    VARCHAR(60) PRIMARY KEY,
  setting_value  VARCHAR(255) NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('api_connection_status', 'unknown'),
  ('last_successful_sync',  NULL);
