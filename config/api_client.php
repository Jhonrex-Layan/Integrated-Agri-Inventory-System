<?php
// Copy to api_client.php (same folder), or better, to a folder OUTSIDE htdocs such as
// C:/xampp/agri_secrets/inventory_api.php - the client loads that path first if it exists.
// Create the key in AgriSuppliers > Administration > API Clients (scopes: read, receive, refund).
return [
    'base_url'        => 'http://localhost/AgriSuppliers/api/v1',
    'api_key'         => 'ags_878e6f7ff591abe29a32e7076aa24b186fc2a7924706a75ac12cad373a8f8dbe',
    'timeout_seconds' => 8,
    'connect_timeout' => 3,
];
