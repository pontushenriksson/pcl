<?php
session_start();
require 'config.php';

// Log out without using logout.php
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Fetch exchange rates and update if older than 6 hours
function fetchAndStoreConversionRates($conn) {
    $apiKey = EXCHANGE_API_KEY;
    $apiUrl = "https://v6.exchangerate-api.com/v6/$apiKey/latest/USD";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $rates = json_decode($response, true)['conversion_rates'];

    foreach (['USD', 'EUR', 'SEK', 'GBP'] as $cur) {
        $stmt = $conn->prepare("INSERT INTO currency_rates (currency_code, rate, last_updated) 
                                VALUES (:code, :rate, NOW()) 
                                ON DUPLICATE KEY UPDATE rate = :rate, last_updated = NOW()");
        $stmt->execute([
            ':code' => $cur,
            ':rate' => $rates[$cur]
        ]);
    }
}

// Get conversion rate
function getConversionRate($conn, $currency) {
    $stmt = $conn->prepare("SELECT rate, last_updated FROM currency_rates WHERE currency_code = :code");
    $stmt->execute([':code' => $currency]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && (time() - strtotime($result['last_updated'])) < 21600) { // 6 hours
        return $result['rate'];
    } else {
        fetchAndStoreConversionRates($conn);
        return getConversionRate($conn, $currency);
    }
}

// Fetch rarities from the API
function fetchRarities() {
    $apiUrl = "https://api.pokemontcg.io/v2/rarities";
    $apiKey = POKEMON_API_KEY;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-Api-Key: $apiKey"]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    return json_decode($response, true)['data'];
}

$rarities = fetchRarities();

// Fetch card info from the API by card name (search functionality)
function searchCardByName($name) {
    $apiUrl = "https://api.pokemontcg.io/v2/cards?q=name:$name";
    $apiKey = POKEMON_API_KEY;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-Api-Key: $apiKey"]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    return json_decode($response, true);
}

// Handle card search by name
$searchResults = [];
if (isset($_POST['search_card'])) {
    $cardName = $_POST['card_name'];
    $searchResults = searchCardByName($cardName)['data'];
}

// Handle card addition, update, delete
$message = '';

if (isset($_POST['add_card_from_search'])) {
    $cardId = $_POST['card_id'];
    $quantity = $_POST['quantity'];
    $cardData = searchCardByName($_POST['card_name'])['data'][0];

    if (isset($cardData)) {
        $cardName = $cardData['name'];
        $price = $cardData['cardmarket']['prices']['averageSellPrice'];
        $imageUrl = $cardData['images']['small'];
        $rarity = isset($cardData['rarity']) ? $cardData['rarity'] : 'Unknown Rarity';

        $stmt = $conn->prepare("SELECT * FROM pokemon_cards WHERE user_id = ? AND card_id = ?");
        $stmt->execute([$_SESSION['user_id'], $cardId]);
        $existingCard = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingCard) {
            $stmt = $conn->prepare("UPDATE pokemon_cards SET quantity = quantity + ? WHERE user_id = ? AND card_id = ?");
            $stmt->execute([$quantity, $_SESSION['user_id'], $cardId]);
            $message = "Card quantity updated successfully!";
        } else {
            $stmt = $conn->prepare("INSERT INTO pokemon_cards (user_id, card_id, name, price, quantity, image_url, rarity, date_added) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $cardId, $cardName, $price, $quantity, $imageUrl, $rarity]);
            $message = "Card added successfully!";
        }

        echo "<script>setTimeout(function() { document.getElementById('search-results').style.display = 'none'; }, 500);</script>";
    }
}

if (isset($_POST['update_quantity'])) {
    $cardId = $_POST['card_id'];
    $newQuantity = $_POST['new_quantity'];

    $stmt = $conn->prepare("UPDATE pokemon_cards SET quantity = ? WHERE user_id = ? AND card_id = ?");
    $stmt->execute([$newQuantity, $_SESSION['user_id'], $cardId]);
    $message = "Quantity updated successfully!";
}

if (isset($_POST['delete_card'])) {
    $cardId = $_POST['card_id'];

    $stmt = $conn->prepare("DELETE FROM pokemon_cards WHERE card_id = ? AND user_id = ?");
    $stmt->execute([$cardId, $_SESSION['user_id']]);
    $message = "Card deleted successfully!";
}

// Default sorting order
$orderBy = "name ASC";
if (isset($_GET['sort'])) {
    switch ($_GET['sort']) {
        case 'name_az': $orderBy = "name ASC"; break;
        case 'name_za': $orderBy = "name DESC"; break;
        case 'quantity_asc': $orderBy = "quantity ASC"; break;
        case 'quantity_desc': $orderBy = "quantity DESC"; break;
        case 'value_asc': $orderBy = "price ASC"; break;
        case 'value_desc': $orderBy = "price DESC"; break;
        case 'date_added_asc': $orderBy = "date_added ASC"; break;
        case 'date_added_desc': $orderBy = "date_added DESC"; break;
    }
}

$currency = isset($_POST['currency']) ? $_POST['currency'] : 'USD';
$conversionRate = getConversionRate($conn, $currency);

// Fetch user's cards with sorting
$stmt = $conn->prepare("SELECT * FROM pokemon_cards WHERE user_id = ? ORDER BY $orderBy");
$stmt->execute([$_SESSION['user_id']]);
$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalWorth = 0;
$totalCards = 0;
$mostValuableCard = null;

foreach ($cards as $card) {
    $totalWorth += $card['price'] * $card['quantity'];
    $totalCards += $card['quantity'];

    if ($mostValuableCard === null || ($card['price'] > $mostValuableCard['price'])) {
        $mostValuableCard = $card;
    }
}

$totalWorthConverted = $totalWorth * $conversionRate;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Pokémon Collection</title>
    <link rel="stylesheet" href="styles.css">
    <script>
        // Popup and Info Icon
        document.addEventListener("DOMContentLoaded", function() {
            const infoIcon = document.querySelector('.info-icon');
            const disclaimer = document.querySelector('.disclaimer');
            infoIcon.addEventListener('mouseover', function() {
                disclaimer.style.display = 'block';
            });
            infoIcon.addEventListener('mouseout', function() {
                disclaimer.style.display = 'none';
            });
        });

        // Close Search Results
        function closeSearchResults() {
            document.getElementById('search-results').style.display = 'none';
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>My Pokémon Card Collection</h1>

        <!-- Success Popup -->
        <?php if (!empty($message)): ?>
            <div id="notification" class="notification"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <script>
            setTimeout(function() {
                var notification = document.getElementById("notification");
                if (notification) {
                    notification.style.opacity = 0;
                    setTimeout(function() {
                        notification.style.display = 'none';
                    }, 1000);
                }
            }, 3000);
        </script>

        <!-- Stats Boxes -->
        <div class="stats-boxes">
            <div class="stat-box">Total Cards: <?php echo $totalCards; ?></div>
            <div class="stat-box">Total Worth: <?php echo number_format($totalWorthConverted, 2) . " " . $currency; ?></div>
            <?php if ($mostValuableCard): ?>
                <div class="stat-box">Most Valuable: <?php echo htmlspecialchars($mostValuableCard['name']) . " (" . number_format($mostValuableCard['price'] * $conversionRate, 2) . " " . $currency; ?>)</div>
            <?php else: ?>
                <div class="stat-box">Most Valuable: No cards available</div>
            <?php endif; ?>
        </div>

        <!-- Currency Selection -->
        <form method="POST" class="currency-form">
            <label for="currency">Currency:</label>
            <select name="currency" onchange="this.form.submit()">
                <option value="USD" <?php if ($currency == 'USD') echo 'selected'; ?>>USD</option>
                <option value="EUR" <?php if ($currency == 'EUR') echo 'selected'; ?>>EUR</option>
                <option value="SEK" <?php if ($currency == 'SEK') echo 'selected'; ?>>SEK</option>
                <option value="GBP" <?php if ($currency == 'GBP') echo 'selected'; ?>>GBP</option>
            </select>
            <span class="info-icon">ℹ️</span>
            <div class="disclaimer">Currencies updated every 6 hours.</div>
        </form>

        <!-- Sorting Options -->
        <div class="sort-options">
            <span>Sort by:</span>
            <a href="?sort=name_az">Name A-Z</a> |
            <a href="?sort=name_za">Name Z-A</a> |
            <a href="?sort=quantity_asc">Quantity (Asc)</a> |
            <a href="?sort=quantity_desc">Quantity (Desc)</a> |
            <a href="?sort=value_asc">Value (Asc)</a> |
            <a href="?sort=value_desc">Value (Desc)</a> |
            <a href="?sort=date_added_asc">Date Added (Asc)</a> |
            <a href="?sort=date_added_desc">Date Added (Desc)</a>
        </div>

        <!-- Search Form -->
        <div class="search-section">
            <form method="POST" action="">
                <input type="text" name="card_name" placeholder="Search for Pokémon cards by name..." required>
                <button type="submit" name="search_card" class="btn">Search</button>
            </form>
        </div>

        <!-- Search Results -->
        <?php if (!empty($searchResults)): ?>
            <div id="search-results" class="search-results">
                <h3>Search Results <button onclick="closeSearchResults()">Close</button></h3>
                <div class="search-grid">
                    <?php foreach ($searchResults as $result): ?>
                        <div class="card-result">
                            <img src="<?php echo $result['images']['small']; ?>" alt="<?php echo $result['name']; ?>">
                            <p><strong><?php echo $result['name']; ?></strong></p>
                            <p>Card ID: <?php echo $result['id']; ?></p>
                            <p>Rarity: <?php echo isset($result['rarity']) ? $result['rarity'] : 'Unknown Rarity'; ?></p>
                            <form method="POST" action="">
                                <input type="hidden" name="card_id" value="<?php echo $result['id']; ?>">
                                <input type="hidden" name="card_name" value="<?php echo $result['name']; ?>">
                                <input type="number" name="quantity" placeholder="Quantity" required>
                                <button type="submit" name="add_card_from_search" class="btn">Add Card</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- User's Cards -->
        <h2>Your Cards</h2>
        <?php if (!empty($cards)): ?>
            <div class="cards">
                <?php foreach ($cards as $card): ?>
                    <div class="card">
                        <img src="<?php echo htmlspecialchars($card['image_url']); ?>" alt="<?php echo htmlspecialchars($card['name']); ?>">
                        <div class="card-info">
                            <strong><?php echo htmlspecialchars($card['name']); ?></strong>
                            <p>ID: <?php echo htmlspecialchars($card['card_id']); ?></p>
                            <p>Price: <?php echo number_format($card['price'] * $conversionRate, 2) . " " . $currency; ?></p>
                            <p>Quantity: <?php echo (int)$card['quantity']; ?></p>
                            <p>Rarity: <?php echo htmlspecialchars($card['rarity']); ?></p>
                            <p>Date Added: <?php echo htmlspecialchars($card['date_added']); ?></p>

                            <form method="POST" class="inline-form">
                                <input type="hidden" name="card_id" value="<?php echo htmlspecialchars($card['card_id']); ?>">
                                <input type="number" name="new_quantity" value="<?php echo (int)$card['quantity']; ?>" min="1" required>
                                <button type="submit" name="update_quantity" class="btn">Update</button>
                                <button type="submit" name="delete_card" class="delete-btn">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>You have no cards yet.</p>
        <?php endif; ?>

        <!-- Logout Form -->
        <form method="POST" action="">
            <button type="submit" name="logout" class="logout-btn">Logout</button>
        </form>
    </div>
</body>
</html>
