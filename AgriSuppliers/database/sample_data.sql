-- Sample data for agri_supplier_db (no users: create the admin with setup_admin.php first or after).
-- Fictional businesses. Run AFTER schema.sql on an empty database so IDs start at 1;
-- the Inventory sample data references supplier IDs 1-4 and supplier product IDs 1-8.
USE agri_supplier_db;

INSERT INTO suppliers (supplier_code, business_name, contact_person, telephone, email, address, registration_no, status) VALUES
 ("SUP-0001","GreenField Seeds Trading","Maria Santos","+63 82 555 0101","orders@greenfield.example","12 Harvest Rd, Davao City","DTI-2019-44121","active"),
 ("SUP-0002","AgroChem Distributors","Jose Reyes","+63 82 555 0102","sales@agrochem.example","88 Industrial Ave, Davao City","DTI-2016-10872","active"),
 ("SUP-0003","FarmTech Equipment Corp","Ana Lim","+63 82 555 0103","info@farmtech.example","5 Machinery Lane, Tagum City","SEC-CS201801234","active"),
 ("SUP-0004","Valley Feeds & Livestock Supply","Pedro Cruz","+63 82 555 0104","valleyfeeds@example.com","Km 9 Highway, Digos City","DTI-2020-77015","inactive");

INSERT INTO supplier_products (supplier_id, supplier_sku, product_name, description, category_hint, unit, current_price, min_order_qty, availability, is_approved) VALUES
 (1,"GF-RICE-25","Hybrid Rice Seed 25kg","High-yield hybrid rice seed","Seeds","bag",2450.00,10,"available",1),
 (1,"GF-CORN-10","Yellow Corn Seed 10kg","Certified corn seed","Seeds","bag",1380.00,10,"available",1),
 (2,"AC-UREA-50","Urea 46-0-0 50kg","Nitrogen fertilizer","Fertilizers","sack",1750.00,20,"available",1),
 (2,"AC-NPK-50","Complete 14-14-14 50kg","Balanced NPK fertilizer","Fertilizers","sack",1980.00,20,"limited",1),
 (2,"AC-HERB-1L","Glyphosate Herbicide 1L","Broad-spectrum herbicide","Pesticides","bottle",420.00,24,"available",1),
 (3,"FT-SPRAY-16","Knapsack Sprayer 16L","Manual knapsack sprayer","Tools","pc",1250.00,5,"available",1),
 (3,"FT-TILL-7","Power Tiller 7HP","Gasoline power tiller","Farming Equipment","unit",38500.00,1,"available",0),
 (4,"VF-HOG-50","Hog Grower Feed 50kg","Pelleted grower feed","Animal Feeds","sack",1620.00,10,"unavailable",1);

INSERT INTO supplier_price_history (supplier_product_id, price, effective_from, reason)
SELECT id, current_price, NOW(), "Initial quote" FROM supplier_products;
-- one earlier price on Urea so history/comparison reports have data
INSERT INTO supplier_price_history (supplier_product_id, price, effective_from, reason) VALUES
 (3, 1690.00, DATE_SUB(NOW(), INTERVAL 90 DAY), "Previous quote");
