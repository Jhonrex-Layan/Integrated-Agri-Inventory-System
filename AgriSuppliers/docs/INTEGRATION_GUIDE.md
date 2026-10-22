# Installing and connecting both systems (XAMPP, Windows)

## 1. Files
Extract `AgriSuppliers` and `AgriInventory` into `C:\xampp\htdocs\`. Start **Apache** and **MySQL** in the XAMPP control panel.
Enable `extension=curl` in `C:\xampp\php\php.ini` if it is commented out (the Inventory system calls the API with cURL), then restart Apache.
Apache must have `mod_rewrite` enabled and `AllowOverride All` for htdocs (XAMPP default).

## 2. Databases (phpMyAdmin, http://localhost/phpmyadmin)
1. Import `AgriSuppliers/database/schema.sql`, then `sample_data.sql`.
2. Import `AgriInventory/database/schema.sql`, then `sample_data.sql`.
*Already imported an earlier schema?* Run `database/migrations/stage4_upgrade.sql` for each database instead (or drop and re-import if there is no data to keep).
Sample data: import into **empty** databases so supplier IDs 1-4 and product IDs 1-8 line up with the Inventory sample products.

## 3. Administrators
Open `http://localhost/AgriSuppliers/setup_admin.php` and `http://localhost/AgriInventory/setup_admin.php` **from the XAMPP computer**.
Create one administrator in each, then delete `setup_admin.php` from both folders. Optional: import
`AgriInventory/database/sample_opening_stock.sql` (after the admin exists) to load opening stock.
Create the other roles under Administration > Users (an approving officer is needed for purchase order approval unless the administrator approves).

## 4. Connect Inventory to Suppliers
1. In AgriSuppliers sign in > **Administration > API Clients** > create a client named "AgriInventory" with scopes **read, receive, refund**. Copy the key.
2. In `AgriInventory/config/`, copy `api_client.sample.php` to `api_client.php`, paste the key. Better: save it as
   `C:\xampp\agri_secrets\inventory_api.php` (outside htdocs); that file is used first when it exists.
3. In AgriInventory open the Dashboard and press **Check connection**. The badge should turn green ("Supplier API: connected").

## 5. End-to-end walk-through (this is the flow to test)
1. **Suppliers** > Product Catalog: add a product for a supplier, then **Approve** it (an approving officer or the administrator).
2. **Purchase Orders** > New: pick the supplier and approved products (several lines), **Save & submit**. An approving officer (not the preparer) approves; optionally "Mark as sent".
3. **Deliveries** > Record delivery for the PO: enter delivered and rejected quantities > **Confirm delivery** (partial deliveries are fine).
4. In **Inventory > Receiving** the delivery appears under "Confirmed deliveries waiting for verification". **Verify & accept**: map each line to a local product, adjust the accepted quantity, accept.
   Stock increases once, a stock movement is written, and the acknowledgment is sent.
5. Back in Suppliers the delivery shows **Acknowledged**, the PO's received quantities rise, and the PO becomes *Partially received* or *Completed*.
6. **Damaged & Refunds** (Inventory): report damage on a supplier-linked product and tick "ask the supplier for a refund". In Suppliers > **Refund Requests** approve or reject it, then press **Sync refund decisions** in Inventory.
7. **Failure test:** deactivate the API client in Suppliers (or stop Apache for the Suppliers site) *after* opening a delivery's Verify page is not possible, so instead
   open the Verify page, then deactivate the client, then press **Accept into stock**: the fetch already happened, stock is received locally, and the acknowledgment shows
   **pending**. Re-activate the client and open Receiving (or press **Retry**): the acknowledgment is delivered and stock is *not* increased a second time.
   Pressing Accept twice, or accepting the same delivery from two browsers, is blocked by the unique receipt per delivery.

## 6. Roles
Administrator: everything. Supplier staff: suppliers, catalog, PO preparation, deliveries, notes. Inventory staff: products, categories, movements, receiving, sales, damage reports.
Approving officer: approves/rejects POs and catalog items, decides refunds, reviews adjustments, voids sales.
