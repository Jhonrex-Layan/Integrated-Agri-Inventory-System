<?php
// Supplier Management System - application settings.
// This folder is protected by .htaccess (Require all denied). Do not echo values from here.
declare(strict_types=1);

return [
    "app_name"   => "AgriSuppliers",
    "app_title"  => "Supplier Management System",
    "base_url"   => "/AgriSuppliers",
    // Branding shown in the sidebar and on the sign-in page
    "brand_name" => "Agri-Supplier",
    "brand_sub"  => "Management System",
    "login_headline" => "Suppliers, orders and deliveries in one place.",
    "login_lead"     => "Manage supplier records, purchase orders and deliveries, and share them securely with Agri-Supply Inventory.",
    "login_points"   => [
        ["clipboard2-check", "Purchase orders with an approval step"],
        ["truck",            "Delivery and refund tracking"],
        ["plug",             "A secure API that connects to Agri-Supply Inventory"],
    ],
    "timezone"   => "Asia/Manila",
    "debug"      => false,             // true shows details on screen; keep false after setup
    "session_name" => "agrisup_sess",
    "session_lifetime" => 3600,
    "max_failed_logins" => 5,
    "lockout_minutes"   => 15,
    "log_file"   => __DIR__ . "/../storage/logs/app.log",
    "db" => [
        "host"    => "127.0.0.1",
        "port"    => 3306,
        "name"    => "agri_supplier_db",
        // XAMPP default is root with an empty password: fine for a local test only.
        // Better: create a dedicated MySQL user (see README.md) and put it here.
        "user"    => "root",
        "pass"    => "",
        "charset" => "utf8mb4",
    ],
];
