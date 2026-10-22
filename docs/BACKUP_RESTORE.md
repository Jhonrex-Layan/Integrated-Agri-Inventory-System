# Backup and restore
Back up **both** databases and both folders (the folders hold your `config` files).

**phpMyAdmin:** select the database > Export > Quick > SQL. Restore: create/select the database > Import the `.sql` file.
**Command line (XAMPP):**

    C:\xampp\mysql\bin\mysqldump -u root agri_supplier_db  > agri_supplier_db_backup.sql
    C:\xampp\mysql\bin\mysqldump -u root agri_inventory_db > agri_inventory_db_backup.sql
    C:\xampp\mysql\bin\mysql -u root agri_supplier_db  < agri_supplier_db_backup.sql

Take both backups at the same moment (stop users first) so delivery receipts and acknowledgments stay consistent between the two systems.
If you ever restore only one side, open Inventory > Receiving and check Sync Logs: unacknowledged receipts are re-sent safely because acknowledgments are idempotent.
