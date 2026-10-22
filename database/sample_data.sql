-- Sample data for agri_inventory_db. Products start at quantity 0 so the stock history stays
-- consistent; run sample_opening_stock.sql AFTER creating the admin to load opening balances.
-- external_supplier_* values assume the Supplier sample data was loaded into an empty database.
USE agri_inventory_db;

INSERT INTO categories (name, description) VALUES
 ("Seeds","Planting seeds"),("Fertilizers","Soil nutrients"),("Pesticides","Crop protection"),
 ("Animal Feeds","Livestock feeds"),("Tools","Hand and spray tools"),("Farming Equipment","Machinery");

INSERT INTO products (product_code, name, description, category_id, unit, cost_price, selling_price, reorder_level, external_supplier_id, external_supplier_product_id, external_supplier_sku) VALUES
 ("S01","Hybrid Rice Seed 25kg","High-yield hybrid rice seed",1,"bag",2450.00,2850.00,10,1,1,"GF-RICE-25"),
 ("S02","Yellow Corn Seed 10kg","Certified corn seed",1,"bag",1380.00,1650.00,10,1,2,"GF-CORN-10"),
 ("F01","Urea 46-0-0 50kg","Nitrogen fertilizer",2,"sack",1750.00,2050.00,20,2,3,"AC-UREA-50"),
 ("F02","Complete 14-14-14 50kg","Balanced NPK fertilizer",2,"sack",1980.00,2300.00,20,2,4,"AC-NPK-50"),
 ("P01","Glyphosate Herbicide 1L","Broad-spectrum herbicide",3,"bottle",420.00,520.00,24,2,5,"AC-HERB-1L"),
 ("T01","Knapsack Sprayer 16L","Manual knapsack sprayer",5,"pc",1250.00,1590.00,5,3,6,"FT-SPRAY-16"),
 ("AF01","Hog Grower Feed 50kg","Pelleted grower feed",4,"sack",1620.00,1900.00,10,4,8,"VF-HOG-50");
