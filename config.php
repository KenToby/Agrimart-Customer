<?php
// Database settings for the CUSTOMER site (XAMPP defaults).
// If you set a MySQL root password in XAMPP, put it in DB_PASS.
const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';

// This site's OWN database: it stores the customers' orders.
const CUSTOMER_DB = 'agrimart_customer';

// The farmer/admin site's database. The customer site only uses it to
//   1. read the products that approved farmers are selling, and
//   2. send each customer order to the right farmer (and read its status back).
const ADMIN_DB = 'agrimart_admin';

function connect(string $dbname): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $dbname . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

function db(): PDO {            // agrimart_customer
    static $pdo = null;
    return $pdo ??= connect(CUSTOMER_DB);
}

function admin_db(): PDO {      // agrimart_admin
    static $pdo = null;
    return $pdo ??= connect(ADMIN_DB);
}
