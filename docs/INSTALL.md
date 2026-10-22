# Install: Agri-Supply Inventory System

Full guide: see `README.md` in the folder that contains both applications. Short version for this app:

1. Copy the `AgriInventory` folder to `C:\xampp\htdocs\` and start **Apache** and **MySQL** in XAMPP.
2. In phpMyAdmin (<http://localhost/phpmyadmin>) import `database/schema.sql` (creates `agri_inventory_db`), then optionally `database/sample_data.sql`.
3. Check `config/config.php` (`base_url`, `db` settings, `timezone`). The defaults suit a standard XAMPP.
4. Open <http://localhost/AgriInventory/setup_admin.php> **on the XAMPP computer**, create the administrator, then **delete `setup_admin.php`**.
5. Sign in at <http://localhost/AgriInventory/> and create other users under **Administration > Users**.
6. Connect it to `AgriSuppliers`: see `README.md`, section 5.

Optional dedicated database user (recommended outside a quick local test), run in phpMyAdmin > SQL:

    CREATE USER 'agri_app'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
    GRANT SELECT, INSERT, UPDATE, DELETE ON agri_inventory_db.* TO 'agri_app'@'localhost';
    FLUSH PRIVILEGES;

Then put `agri_app` and its password in the `db` section of `config/config.php`.
