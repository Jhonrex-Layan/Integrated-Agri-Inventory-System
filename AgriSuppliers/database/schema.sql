-- =====================================================================
-- Agri Supplier Management System  |  database: agri_supplier_db
-- Requires MariaDB 10.4+ (XAMPP 8.x) or MySQL 8.0.16+ (CHECK constraints)
-- Import via phpMyAdmin > Import, or:  mysql -u root < schema.sql
-- =====================================================================
CREATE DATABASE IF NOT EXISTS agri_supplier_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agri_supplier_db;
SET NAMES utf8mb4;

-- ---------- users ----------
CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  email          VARCHAR(150) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('administrator','supplier_staff','approving_officer') NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------- suppliers ----------
-- id is the stable external ID used by the Inventory system. It is never reused:
-- suppliers are archived, not deleted (all FKs below are RESTRICT).
CREATE TABLE suppliers (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_code      VARCHAR(20)  NOT NULL,
  business_name      VARCHAR(150) NOT NULL,
  contact_person     VARCHAR(120) NOT NULL,
  telephone          VARCHAR(40)  NOT NULL,
  email              VARCHAR(150) NULL,
  address            VARCHAR(255) NOT NULL,
  registration_no    VARCHAR(60)  NULL,
  tax_id             VARCHAR(60)  NULL,
  status             ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_suppliers_code (supplier_code),
  KEY idx_suppliers_status (status),
  KEY idx_suppliers_name (business_name),
  CONSTRAINT fk_suppliers_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE supplier_notes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  note_type    ENUM('contact','feedback','general') NOT NULL DEFAULT 'general',
  rating       TINYINT UNSIGNED NULL,
  note         TEXT NOT NULL,
  created_by   INT UNSIGNED NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notes_supplier (supplier_id),
  CONSTRAINT chk_notes_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
  CONSTRAINT fk_notes_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_notes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- supplier product catalog ----------
CREATE TABLE supplier_products (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  supplier_sku       VARCHAR(60)  NOT NULL,
  product_name       VARCHAR(150) NOT NULL,
  description        TEXT NULL,
  category_hint      VARCHAR(80)  NULL,
  unit               VARCHAR(20)  NOT NULL,
  current_price      DECIMAL(12,2) NOT NULL,
  min_order_qty      DECIMAL(12,2) NOT NULL DEFAULT 1,
  availability       ENUM('available','limited','unavailable') NOT NULL DEFAULT 'available',
  is_approved        TINYINT(1) NOT NULL DEFAULT 0,   -- only approved items are published via API
  is_archived        TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sp_supplier_sku (supplier_id, supplier_sku),
  KEY idx_sp_name (product_name),
  KEY idx_sp_publish (is_approved, is_archived),
  CONSTRAINT chk_sp_price CHECK (current_price >= 0),
  CONSTRAINT chk_sp_moq CHECK (min_order_qty > 0),
  CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE supplier_price_history (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_product_id  INT UNSIGNED NOT NULL,
  price                DECIMAL(12,2) NOT NULL,
  effective_from       DATETIME NOT NULL,
  changed_by           INT UNSIGNED NULL,
  reason               VARCHAR(255) NULL,
  KEY idx_sph_product_date (supplier_product_id, effective_from),
  CONSTRAINT chk_sph_price CHECK (price >= 0),
  CONSTRAINT fk_sph_product FOREIGN KEY (supplier_product_id) REFERENCES supplier_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sph_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- purchase orders ----------
CREATE TABLE purchase_orders (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number           VARCHAR(30) NOT NULL,
  supplier_id         INT UNSIGNED NOT NULL,
  status              ENUM('draft','pending_approval','approved','sent',
                           'partially_received','completed','cancelled','rejected')
                      NOT NULL DEFAULT 'draft',
  order_date          DATE NOT NULL,
  expected_date       DATE NULL,
  total_amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes               TEXT NULL,
  rejection_reason    VARCHAR(255) NULL,
  submitted_at        DATETIME NULL,
  sent_at             DATETIME NULL,
  closed_note         VARCHAR(255) NULL,          -- why a partly received order was closed short or cancelled
  created_by          INT UNSIGNED NOT NULL,
  approved_by         INT UNSIGNED NULL,
  approved_at         DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_number (po_number),
  KEY idx_po_status (status),
  KEY idx_po_supplier (supplier_id),
  KEY idx_po_dates (order_date, expected_date),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE purchase_order_items (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id    INT UNSIGNED NOT NULL,
  supplier_product_id  INT UNSIGNED NOT NULL,
  product_name         VARCHAR(150) NOT NULL,      -- snapshots keep history stable
  supplier_sku         VARCHAR(60)  NOT NULL,
  unit                 VARCHAR(20)  NOT NULL,
  qty_ordered          DECIMAL(12,2) NOT NULL,
  qty_received         DECIMAL(12,2) NOT NULL DEFAULT 0,  -- raised only by Inventory acknowledgments
  unit_price           DECIMAL(12,2) NOT NULL,            -- price at time of order
  line_total           DECIMAL(14,2) NOT NULL,
  UNIQUE KEY uq_poi_line (purchase_order_id, supplier_product_id),
  CONSTRAINT chk_poi_qty CHECK (qty_ordered > 0 AND qty_received >= 0 AND qty_received <= qty_ordered),
  CONSTRAINT chk_poi_price CHECK (unit_price >= 0),
  CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_sp FOREIGN KEY (supplier_product_id) REFERENCES supplier_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- deliveries ----------
-- status flow: expected -> confirmed (visible to Inventory) -> acknowledged (Inventory accepted)
CREATE TABLE deliveries (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_ref       VARCHAR(40) NOT NULL,
  purchase_order_id  INT UNSIGNED NOT NULL,
  status             ENUM('expected','confirmed','acknowledged','cancelled') NOT NULL DEFAULT 'expected',
  expected_date      DATE NULL,
  arrival_date       DATETIME NULL,
  notes              TEXT NULL,
  recorded_by        INT UNSIGNED NOT NULL,
  confirmed_at       DATETIME NULL,
  -- Inventory acknowledgment (idempotent: the same receipt ref may be re-sent safely)
  ack_receipt_ref    VARCHAR(60) NULL,
  acknowledged_at    DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_deliveries_ref (delivery_ref),
  UNIQUE KEY uq_deliveries_ack_ref (ack_receipt_ref),
  KEY idx_deliveries_status (status),
  KEY idx_deliveries_po (purchase_order_id),
  CONSTRAINT fk_del_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_del_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE delivery_items (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id        INT UNSIGNED NOT NULL,
  po_item_id         INT UNSIGNED NOT NULL,
  qty_delivered      DECIMAL(12,2) NOT NULL,                 -- physically arrived
  qty_rejected       DECIMAL(12,2) NOT NULL DEFAULT 0,       -- rejected at supplier-side check
  qty_acknowledged   DECIMAL(12,2) NULL,                     -- accepted by Inventory
  UNIQUE KEY uq_di_line (delivery_id, po_item_id),
  CONSTRAINT chk_di_qty CHECK (qty_delivered >= 0 AND qty_rejected >= 0 AND qty_rejected <= qty_delivered),
  CONSTRAINT chk_di_ack CHECK (qty_acknowledged IS NULL OR (qty_acknowledged >= 0 AND qty_acknowledged <= qty_delivered - qty_rejected)),
  CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
  CONSTRAINT fk_di_poi FOREIGN KEY (po_item_id) REFERENCES purchase_order_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- audit and API ----------
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

-- Only a SHA-256 hash of each API key is stored; the plain key is shown once at creation.
CREATE TABLE api_clients (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_name         VARCHAR(80) NOT NULL,
  key_prefix          CHAR(8)  NOT NULL,
  key_hash            CHAR(64) NOT NULL,
  scopes              VARCHAR(255) NOT NULL DEFAULT 'read',   -- comma list: read,receive,refund
  rate_limit_per_min  INT UNSIGNED NOT NULL DEFAULT 120,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at        DATETIME NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_key_hash (key_hash),
  CONSTRAINT fk_apic_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE api_rate_limits (
  client_id     INT UNSIGNED NOT NULL,
  window_start  DATETIME NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (client_id, window_start),
  CONSTRAINT fk_rl_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE api_sync_logs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id        INT UNSIGNED NULL,
  method           VARCHAR(8)   NOT NULL,
  endpoint         VARCHAR(200) NOT NULL,
  http_status      SMALLINT UNSIGNED NOT NULL,
  idempotency_key  VARCHAR(80) NULL,
  outcome          ENUM('success','duplicate','rejected','error') NOT NULL,
  message          VARCHAR(255) NULL,
  ip_address       VARCHAR(45) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_asl_created (created_at),
  KEY idx_asl_outcome (outcome),
  CONSTRAINT fk_asl_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- supplier refund requests (submitted by the Inventory system for damaged goods) ----------
-- request_ref is chosen by Inventory and is UNIQUE, so a retried submission can never create a second row.
CREATE TABLE refund_requests (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_ref          VARCHAR(40) NOT NULL,
  supplier_id          INT UNSIGNED NOT NULL,
  supplier_product_id  INT UNSIGNED NULL,
  product_name         VARCHAR(150) NOT NULL,
  quantity             DECIMAL(12,2) NOT NULL,
  unit_cost            DECIMAL(12,2) NOT NULL,
  amount               DECIMAL(14,2) NOT NULL,
  reason               VARCHAR(255) NOT NULL,
  status               ENUM('received','under_review','approved','rejected','refunded') NOT NULL DEFAULT 'received',
  response_note        VARCHAR(255) NULL,
  reviewed_by          INT UNSIGNED NULL,
  reviewed_at          DATETIME NULL,
  client_id            INT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refund_ref (request_ref),
  KEY idx_refund_status (status),
  KEY idx_refund_supplier (supplier_id),
  CONSTRAINT chk_refund_qty CHECK (quantity > 0 AND amount >= 0),
  CONSTRAINT fk_refund_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_sp FOREIGN KEY (supplier_product_id) REFERENCES supplier_products(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_client FOREIGN KEY (client_id) REFERENCES api_clients(id) ON DELETE SET NULL
) ENGINE=InnoDB;
