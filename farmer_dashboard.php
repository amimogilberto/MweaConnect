<?php
require __DIR__ . '/bootstrap.php';
require_role('farmer');

$farmerId = (string) $_SESSION['user_id'];
$error = '';
$success = '';
$allowedConditions = ['Freshly Harvested', 'Grade A', 'Grade B', 'Organic', 'Dried/Cured'];
$allowedCategories = ['Grains & Cereals', 'Vegetables', 'Fruits', 'Legumes'];
$allowedPayments = ['M-Pesa', 'Bank Transfer', 'Platform Escrow'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $title = trim((string) ($_POST['title'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $condition = (string) ($_POST['produce_condition'] ?? '');
    $unit = trim((string) ($_POST['unit_label'] ?? ''));
    $price = filter_var($_POST['price_per_unit'] ?? null, FILTER_VALIDATE_FLOAT);
    $quantity = filter_var($_POST['quantity_available'] ?? null, FILTER_VALIDATE_FLOAT);
    $payment = (string) ($_POST['preferred_payment_method'] ?? '');

    if (
        $title === '' || strlen($title) > 100 ||
        !in_array($category, $allowedCategories, true) ||
        !in_array($condition, $allowedConditions, true) ||
        $unit === '' || strlen($unit) > 30 ||
        $price === false || $price <= 0 ||
        $quantity === false || $quantity <= 0 ||
        !in_array($payment, $allowedPayments, true)
    ) {
        $error = 'Complete all product fields with valid values.';
    } else {
        try {
            $productId = bin2hex(random_bytes(15));
            $statement = $pdo->prepare(
                'INSERT INTO products
                    (product_id, farmer_id, title, category, produce_condition, unit_label,
                     price_per_unit, quantity_available, preferred_payment_method)
                 VALUES (:id, :farmer_id, :title, :category, :condition, :unit, :price, :quantity, :payment)'
            );
            $statement->execute([
                'id' => $productId,
                'farmer_id' => $farmerId,
                'title' => $title,
                'category' => $category,
                'condition' => $condition,
                'unit' => $unit,
                'price' => $price,
                'quantity' => $quantity,
                'payment' => $payment,
            ]);

            log_activity($pdo, 'PRODUCT_POSTED', "Farmer posted {$quantity} {$unit} of {$title} at KES {$price}/{$unit}.");
            $success = 'Product listed in the marketplace.';
        } catch (PDOException $exception) {
            error_log('MFC farmer product listing failed: ' . $exception->getMessage());
            $error = 'We could not list your product. Please try again.';
        }
    }
}

try {
    $statsStatement = $pdo->prepare(
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(produce_subtotal), 0) AS gross_sales,
                COALESCE(SUM(platform_commission_5pct), 0) AS platform_fees,
                COALESCE(SUM(farmer_net_payout), 0) AS net_earnings
         FROM orders
         WHERE farmer_id = :farmer_id AND order_status <> 'cancelled'"
    );
    $statsStatement->execute(['farmer_id' => $farmerId]);
    $stats = $statsStatement->fetch();
    $rating = get_user_rating($pdo, $farmerId);

    $productsStatement = $pdo->prepare(
        'SELECT * FROM products WHERE farmer_id = :farmer_id ORDER BY created_at DESC'
    );
    $productsStatement->execute(['farmer_id' => $farmerId]);
    $myProducts = $productsStatement->fetchAll();

    $ordersStatement = $pdo->prepare(
        "SELECT o.*, p.title, p.unit_label, b.full_name_or_company_name AS buyer_name
         FROM orders o
         JOIN products p ON p.product_id = o.product_id
         JOIN buyers_table b ON b.buyer_id = o.buyer_id
         WHERE o.farmer_id = :farmer_id
         ORDER BY o.created_at DESC"
    );
    $ordersStatement->execute(['farmer_id' => $farmerId]);
    $myOrders = $ordersStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('MFC farmer dashboard load failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The farmer dashboard could not load. Check the database schema.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Portal | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
<main class="auth-main admin-main">
    <section class="auth-card">
        <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
        <h1>Farmer Business Hub — <?= escape_html($_SESSION['user_name'] ?? '') ?></h1>
        <p><a href="index.php">Home</a></p>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <button class="form-submit" type="submit">Sign out</button>
        </form>
        <p>Reputation score: <strong><?= escape_html($rating['avg']) ?> ★</strong> (<?= escape_html($rating['count']) ?> reviews)</p>

        <?php if ($success !== ''): ?>
            <p class="form-message form-success" role="status"><?= escape_html($success) ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="admin-section" aria-label="Business analytics">
            <h2>Business analytics</h2>
            <div class="admin-stats">
                <article class="admin-stat"><span>Completed/active orders</span><strong><?= escape_html($stats['total_orders']) ?></strong></article>
                <article class="admin-stat"><span>Gross market sales</span><strong>KES <?= number_format((float) $stats['gross_sales'], 2) ?></strong></article>
                <article class="admin-stat"><span>Platform fee (5%)</span><strong>KES <?= number_format((float) $stats['platform_fees'], 2) ?></strong></article>
                <article class="admin-stat"><span>Net earnings (95%)</span><strong>KES <?= number_format((float) $stats['net_earnings'], 2) ?></strong></article>
            </div>
        </section>

        <section class="admin-section">
            <h2>Post produce to market</h2>
            <p>A 5% platform commission applies to completed sales.</p>
            <form class="auth-form" method="post" action="farmer_dashboard.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="title">Produce name</label>
                <input id="title" name="title" type="text" maxlength="100" required>
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <?php foreach ($allowedCategories as $categoryOption): ?>
                        <option value="<?= escape_html($categoryOption) ?>"><?= escape_html($categoryOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="produce_condition">Product condition</label>
                <select id="produce_condition" name="produce_condition" required>
                    <?php foreach ($allowedConditions as $conditionOption): ?>
                        <option value="<?= escape_html($conditionOption) ?>"><?= escape_html($conditionOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="unit_label">Unit (e.g., kg, 90kg bag, crate)</label>
                <input id="unit_label" name="unit_label" type="text" maxlength="30" required>
                <label for="price_per_unit">Price per unit (KES)</label>
                <input id="price_per_unit" name="price_per_unit" type="number" step="0.01" min="0.01" required>
                <label for="quantity_available">Available quantity</label>
                <input id="quantity_available" name="quantity_available" type="number" step="0.01" min="0.01" required>
                <label for="preferred_payment_method">Preferred payment method</label>
                <select id="preferred_payment_method" name="preferred_payment_method" required>
                    <?php foreach ($allowedPayments as $paymentOption): ?>
                        <option value="<?= escape_html($paymentOption) ?>"><?= escape_html($paymentOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="form-submit" type="submit">Publish produce</button>
            </form>
        </section>

        <section class="admin-section">
            <h2>Your marketplace listings</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Produce</th><th>Category</th><th>Condition</th><th>Price</th><th>Available</th><th>Preferred payment</th></tr></thead>
                    <tbody>
                    <?php foreach ($myProducts as $product): ?>
                        <tr>
                            <td><?= escape_html($product['title']) ?></td>
                            <td><?= escape_html($product['category']) ?></td>
                            <td><?= escape_html($product['produce_condition']) ?></td>
                            <td>KES <?= number_format((float) $product['price_per_unit'], 2) ?> / <?= escape_html($product['unit_label']) ?></td>
                            <td><?= escape_html($product['quantity_available'] . ' ' . $product['unit_label']) ?></td>
                            <td><?= escape_html($product['preferred_payment_method']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$myProducts): ?>
                        <tr><td colspan="6">You have not published any products yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-section">
            <h2>Your orders and coordination</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Product</th><th>Buyer</th><th>Quantity</th><th>Net payout (95%)</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($myOrders as $order): ?>
                        <tr>
                            <td><?= escape_html($order['title']) ?></td>
                            <td><?= escape_html($order['buyer_name']) ?></td>
                            <td><?= escape_html($order['quantity'] . ' ' . $order['unit_label']) ?></td>
                            <td>KES <?= number_format((float) $order['farmer_net_payout'], 2) ?></td>
                            <td><?= escape_html(ucwords(str_replace('_', ' ', $order['order_status']))) ?></td>
                            <td><a href="order_room.php?order_id=<?= rawurlencode($order['order_id']) ?>">Open chat</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$myOrders): ?>
                        <tr><td colspan="6">No active orders are waiting for coordination.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
</body>
</html>
