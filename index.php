<?php
require __DIR__ . '/bootstrap.php';
$isSignedIn = !empty($_SESSION['user_id']);
$isBuyer = $isSignedIn && ($_SESSION['user_role'] ?? '') === 'buyer';
$browseHref = $isBuyer ? 'buyer_dashboard.php' : '#marketplace';
$browseLabel = $isBuyer ? 'My Orders' : 'Browse';
$accountHref = ($isSignedIn && ($_SESSION['user_role'] ?? '') === 'admin')
    ? 'admin_dashboard.php'
    : 'account.php';
$orderStatus = (string) ($_SESSION['order_status'] ?? '');
$orderMessage = (string) ($_SESSION['order_message'] ?? '');
$orderRoomId = (string) ($_SESSION['order_room_id'] ?? '');
$searchTerm = trim((string) ($_GET['q'] ?? ''));
unset($_SESSION['order_status'], $_SESSION['order_message'], $_SESSION['order_room_id']);

try {
    $listingsSql =
        "SELECT p.*, f.full_name AS farmer_name, f.location AS farm_location,
                COALESCE((SELECT ROUND(AVG(stars), 1)
                          FROM user_ratings WHERE ratee_id = f.farmer_id), 0) AS farmer_rating,
                COALESCE((SELECT COUNT(*)
                          FROM user_ratings WHERE ratee_id = f.farmer_id), 0) AS farmer_rating_count,
                COALESCE((SELECT SUM(s.quantity_available)
                          FROM products s
                          WHERE s.status = 'active' AND s.quantity_available > 0
                            AND s.category = p.category AND s.title = p.title
                            AND s.unit_label = p.unit_label), 0) AS total_market_supply
         FROM products p
         JOIN farmers_table f ON f.farmer_id = p.farmer_id
         WHERE p.status = 'active' AND p.quantity_available > 0";
    $listingParameters = [];
    if ($searchTerm !== '') {
        $listingsSql .= ' AND (p.title LIKE :search_title OR p.category LIKE :search_category)';
        $listingParameters = [
            'search_title' => '%' . $searchTerm . '%',
            'search_category' => '%' . $searchTerm . '%',
        ];
    }
    $listingsSql .= ' ORDER BY farmer_rating DESC, p.created_at DESC';
    $listingsStatement = $pdo->prepare($listingsSql);
    $listingsStatement->execute($listingParameters);
    $listings = $listingsStatement->fetchAll();
    $marketPrices = $pdo->query(
        'SELECT crop_type, market_name, current_price, date_updated
         FROM market_price_table
         ORDER BY date_updated DESC
         LIMIT 20'
    )->fetchAll();
    $myOrders = [];
    if ($isBuyer) {
        $ordersStatement = $pdo->prepare(
            'SELECT o.order_id, o.quantity, o.order_status, o.created_at, p.title, p.unit_label
             FROM orders o
             JOIN products p ON p.product_id = o.product_id
             WHERE o.buyer_id = :buyer_id
             ORDER BY o.created_at DESC'
        );
        $ordersStatement->execute(['buyer_id' => (string) $_SESSION['user_id']]);
        $myOrders = $ordersStatement->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('MFC marketplace listings load failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The marketplace is temporarily unavailable.');
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mwea Fresh Connect- Farmer to Buyer Marketplace</title>
        <link rel="stylesheet" href="styles.css">
        <link rel="icon" href="assets/mwea.png">
    </head>

    <body>
        <header class="site-header">
            <div class="container header-main">
                <a class="logo" href="index.php" aria-label="Mwea Fresh Connect home">
                    <img src="assets/mwea.png" alt="Mwea Fresh Connect Logo">
                    <h1>Mwea Fresh Connect</h1>
                </a>
                <form class="search-bar" role="search" method="get" action="index.php#marketplace">
                    <input type="search" name="q" value="<?= escape_html($searchTerm) ?>" aria-label="Search produce" placeholder="Search tomatoes, rice, onions, etc.">
                    <button class="search-button" type="submit">Search</button>
                </form>
                <div class="header-actions">
                    <a class="cart-button" href="<?= escape_html($browseHref) ?>" aria-label="<?= escape_html($browseLabel) ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 1.9-1.4L22 8H6"></path>
                            <circle cx="10" cy="20" r="1"></circle>
                            <circle cx="18" cy="20" r="1"></circle>
                        </svg>
                        <span><?= escape_html($browseLabel) ?></span>
                    </a>
                    <a class="account-button" href="<?= $isSignedIn ? escape_html($accountHref) : 'login.php' ?>"><?= $isSignedIn ? 'My Account' : 'Login / Sign Up' ?></a>
                    <button class="menu-toggle" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="primary-navigation">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="nav-bar">
                <nav id="primary-navigation" class="container primary-navigation" aria-label="Main navigation">
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="#marketplace">Browse Produce</a></li>
                        <li><a href="#market-prices">Market Prices</a></li>
                        <li><a href="apply.php?type=transporter">Become a transporter</a></li>
                        <li><a href="#about">About Us</a></li>
                    </ul>
                </nav>
            </div>
        </header>
        <main class="container marketplace-main">
            <section id="marketplace" class="marketplace-section" aria-labelledby="marketplace-title">
                <div class="marketplace-heading">
                    <div>
                        <h2 id="marketplace-title">Fresh from local farmers</h2>
                        <p>Compare available produce, farmer ratings, and market supply.<?= $searchTerm !== '' ? ' Search: ' . escape_html($searchTerm) : '' ?></p>
                    </div>
                </div>
                <?php if ($orderMessage !== ''): ?>
                    <p class="form-message <?= $orderStatus === 'success' ? 'form-success' : 'form-error' ?>" role="<?= $orderStatus === 'success' ? 'status' : 'alert' ?>"><?= escape_html($orderMessage) ?></p>
                    <?php if ($orderStatus === 'success' && preg_match('/^[a-f0-9]{30}$/', $orderRoomId)): ?>
                        <p><a href="order_room.php?order_id=<?= escape_html($orderRoomId) ?>">Open the shared order room</a></p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$listings): ?>
                    <p class="marketplace-empty">
                        <?= $searchTerm !== '' ? 'No active produce listings match your search.' : 'There are no active produce listings at the moment. Please check back soon.' ?>
                        <?php if ($searchTerm !== ''): ?><a href="index.php#marketplace">Clear search</a><?php endif; ?>
                    </p>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($listings as $listing): ?>
                            <article class="product-card">
                                <p class="product-category"><?= escape_html($listing['category']) ?></p>
                                <h3><?= escape_html($listing['title']) ?></h3>
                                <p class="product-condition"><?= escape_html($listing['produce_condition']) ?></p>
                                <p class="product-price">KES <?= number_format((float) $listing['price_per_unit'], 2) ?> <span>/ <?= escape_html($listing['unit_label']) ?></span></p>
                                <dl class="product-details">
                                    <div><dt>Available from this farmer</dt><dd><?= escape_html($listing['quantity_available']) ?> <?= escape_html($listing['unit_label']) ?></dd></div>
                                    <div><dt>Total market supply</dt><dd><?= escape_html($listing['total_market_supply']) ?> <?= escape_html($listing['unit_label']) ?></dd></div>
                                    <div><dt>Farmer</dt><dd><?= escape_html($listing['farmer_name']) ?><?= !empty($listing['farm_location']) ? ', ' . escape_html($listing['farm_location']) : '' ?></dd></div>
                                    <div><dt>Farmer rating</dt><dd><?= escape_html($listing['farmer_rating']) ?> ★ (<?= escape_html($listing['farmer_rating_count']) ?> reviews)</dd></div>
                                    <div><dt>Preferred payment</dt><dd><?= escape_html($listing['preferred_payment_method']) ?></dd></div>
                                </dl>
                                <?php if ($isBuyer): ?>
                                    <form class="product-order-form" method="post" action="place_order.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                        <input type="hidden" name="product_id" value="<?= escape_html($listing['product_id']) ?>">
                                        <label for="quantity-<?= escape_html($listing['product_id']) ?>">Order quantity (<?= escape_html($listing['unit_label']) ?>)</label>
                                        <input id="quantity-<?= escape_html($listing['product_id']) ?>" name="quantity" type="number" min="0.01" step="0.01" max="<?= escape_html($listing['quantity_available']) ?>" required>
                                        <button class="account-button" type="submit">Place order</button>
                                    </form>
                                <?php elseif (!$isSignedIn): ?>
                                    <p><a class="account-button" href="login.php">Sign in to order</a></p>
                                <?php else: ?>
                                    <p>Sign in with a buyer account to place an order.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isBuyer): ?>
                    <section class="admin-section" aria-label="Your orders">
                        <h2>Your orders</h2>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Produce</th><th>Quantity</th><th>Status</th><th>Placed</th><th>Order room</th></tr></thead>
                                <tbody>
                                <?php foreach ($myOrders as $order): ?>
                                    <tr>
                                        <td><?= escape_html($order['title']) ?></td>
                                        <td><?= escape_html($order['quantity'] . ' ' . $order['unit_label']) ?></td>
                                        <td><?= escape_html(ucwords(str_replace('_', ' ', $order['order_status']))) ?></td>
                                        <td><?= escape_html($order['created_at']) ?></td>
                                        <td><a href="order_room.php?order_id=<?= rawurlencode($order['order_id']) ?>">Open room</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$myOrders): ?>
                                    <tr><td colspan="5">You have not placed any orders yet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            </section>
            <section id="market-prices" class="admin-section" aria-label="Recent market prices">
                <h2>Recent market prices</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Produce</th><th>Market</th><th>Current price</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($marketPrices as $marketPrice): ?>
                            <tr>
                                <td><?= escape_html($marketPrice['crop_type'] ?? 'Not specified') ?></td>
                                <td><?= escape_html($marketPrice['market_name'] ?? 'Not specified') ?></td>
                                <td><?= $marketPrice['current_price'] === null ? 'Not available' : 'KES ' . number_format((float) $marketPrice['current_price'], 2) ?></td>
                                <td><?= escape_html($marketPrice['date_updated'] ?? 'Not available') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$marketPrices): ?>
                            <tr><td colspan="4">No market price updates are available yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section id="about" class="admin-section" aria-label="About Mwea Fresh Connect">
                <h2>About Mwea Fresh Connect</h2>
                <p>We connect local farmers publishing produce with buyers placing orders and transporters coordinating pickup and delivery.</p>
            </section>
        </main>
        <script src="script.js"></script>
    </body>
</html>