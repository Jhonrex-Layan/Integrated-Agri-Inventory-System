-- Optional. Requires at least one administrator row in users (fails otherwise by design).
USE agri_inventory_db;
SET @admin := (SELECT MIN(id) FROM users WHERE role="administrator");

UPDATE products SET quantity = CASE product_code
  WHEN "S01" THEN 40 WHEN "S02" THEN 8 WHEN "F01" THEN 60
  WHEN "F02" THEN 15 WHEN "P01" THEN 48 WHEN "T01" THEN 12
  WHEN "AF01" THEN 25 ELSE quantity END
WHERE quantity = 0;

INSERT INTO stock_movements (product_id, movement_type, quantity, balance_after, reason, reference_type, created_by)
SELECT id, "stock_in", quantity, quantity, "Opening balance (sample data)", "manual", @admin
FROM products WHERE quantity > 0;
