# Troubleshooting
| Symptom | Likely cause / fix |
|---|---|
| "Database connection failed" | MySQL not running in XAMPP, wrong name/user/password in `config/config.php`. |
| Blank page or "An unexpected error occurred" | Read `storage/logs/app.log` in the project folder (set `debug` to `true` in `config/config.php` temporarily). |
| Inventory badge "Supplier API: disconnected" | Apache stopped; wrong `base_url`; key typed wrongly; client deactivated; Inventory logs show the exact error under Sync Logs. |
| "The Supplier API is not configured" | `config/api_client.php` missing or still contains `PASTE_KEY_HERE`. |
| API returns 401 although the key is right | Apache dropped the Authorization header: the `api/v1/.htaccess` rule restores it; confirm `mod_rewrite` is on and `AllowOverride All`. |
| API returns 403 `insufficient_scope` | Edit the API client and tick read, receive and refund. |
| API returns 429 | Raise the client's rate limit. |
| "cURL extension is not enabled" | Enable `extension=curl` in php.ini, restart Apache. |
| Acknowledgment stays "pending" | The Supplier system was unreachable; open Receiving (auto-retries) or press Retry. Status "failed" shows the supplier's reason (for example the delivery was cancelled). |
| Delivery missing from Receiving | It must be **Confirmed** (not just Expected) on the Supplier side, and not already received locally. |
| Cannot approve my own PO | Approval needs a different user (the administrator is exempt for local testing). |
| 419 "session token is invalid" | The page sat open too long; reload it. |
