<?php
// Database connection configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'pokemon_collection');
define('DB_USER', 'root');
define('DB_PASS', '');

// Pokémon TCG API Key
define('POKEMON_API_KEY', 'XXX');

// ExchangeRate API Key
define('EXCHANGE_API_KEY', 'XXX');

// Database connection setup
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}
?>
