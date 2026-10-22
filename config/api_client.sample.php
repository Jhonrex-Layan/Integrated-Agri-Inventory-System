<?php
// Copy to api_client.php (same folder), or better, to a folder OUTSIDE htdocs such as
// C:/xampp/agri_secrets/inventory_api.php - the client loads that path first if it exists.
// Create the key in AgriSuppliers > Administration > API Clients (scopes: read, receive, refund).
return [
    'base_url'        => 'http://localhost/AgriSuppliers/api/v1',
    'api_key'         => 'PASTE_KEY_HERE',
    'timeout_seconds' => 8,
    'connect_timeout' => 3,
];
