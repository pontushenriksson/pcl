<?php
// Database connection configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'pokemon_collection');
define('DB_USER', 'root');
define('DB_PASS', '');

// Pokémon TCG API Key
define('POKEMON_API_KEY', '2419e9b2-55dc-414a-96c4-601aaa5feed9');

// ExchangeRate API Key
define('EXCHANGE_API_KEY', 'd6947a3008eb2a5699987cf1');

// Database connection setup
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}
?>
