<?php
require __DIR__ . '/bootstrap.php';
require_role('buyer');

$buyerId = (string) $_SESSION['user_id'];

try {
    $summaryStatement = $pdo->prepare(
        "SELECT COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN produce_subtotal ELSE 0 END), 0) AS total_value,
                COALESCE(SUM(CASE WHEN order_status IN ('confirmed', 'assigned', 'in_transit') THEN 1 ELSE 0 END), 0) AS active_orders
         FROM orders
         WHERE buyer_id = :buyer_id"
    );
    $summaryStatement->execute(['buyer_id' => $buyerId]);
    $summary = $summaryStatement->fetch();

    $ordersStatement = $pdo->prepare(
        'SELECT o.order_id, o.quantity, o.produce_subtotal, o.order_status, o.created_at,
                p.title, p.unit_label,
                f.full_name AS farmer_name,
                t.full_name AS transporter_name
         FROM orders o
         JOIN products p ON p.product_id = o.product_id
         JOIN farmers_table f ON f.farmer_id = o.farmer_id
         LEFT JOIN transporters_table t ON t.transporter_id = o.transporter_id
         WHERE o.buyer_id = :buyer_id
         ORDER BY o.created_at DESC'
    );
    $ordersStatement->execute(['buyer_id' => $buyerId]);
    $orders = $ordersStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('MFC buyer dashboard load failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The buyer dashboard could not load. Check the database schema.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer dashboard | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
<main class="auth-main admin-main">
    <section class="auth-card">
        <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
        <h1>Buyer workspace</h1>
        <p>Welcome, <?= escape_html($_SESSION['user_name'] ?? '') ?>.</p>
        <p><a href="index.php#marketplace">Browse the marketplace</a></p>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <button class="form-submit" type="submit">Sign out</button>
        </form>

        <section class="admin-section" aria-label="Purchase summary">
            <h2>Purchase summary</h2>
            <div class="admin-stats">
                <article class="admin-stat"><span>Orders placed</span><strong><?= escape_html($summary['total_orders']) ?></strong></article>
                <article class="admin-stat"><span>Active orders</span><strong><?= escape_html($summary['active_orders']) ?></strong></article>
                <article class="admin-stat"><span>Order value (excluding cancelled)</span><strong>KES <?= number_format((float) $summary['total_value'], 2) ?></strong></article>
            </div>
        </section>

        <section class="admin-section">
            <h2>Your orders</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Produce</th><th>Farmer</th><th>Transporter</th><th>Quantity</th><th>Order value</th><th>Status</th><th>Placed</th><th>Order room</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= escape_html($order['title']) ?></td>
                            <td><?= escape_html($order['farmer_name']) ?></td>
                            <td><?= escape_html($order['transporter_name'] ?? 'Not assigned') ?></td>
                            <td><?= escape_html($order['quantity'] . ' ' . $order['unit_label']) ?></td>
                            <td>KES <?= number_format((float) $order['produce_subtotal'], 2) ?></td>
                            <td><?= escape_html(ucwords(str_replace('_', ' ', $order['order_status']))) ?></td>
                            <td><?= escape_html($order['created_at']) ?></td>
                            <td><a href="order_room.php?order_id=<?= rawurlencode($order['order_id']) ?>">Open room</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orders): ?>
                        <tr><td colspan="8">You have not placed any orders yet. <a href="index.php#marketplace">Browse produce</a>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
</body>
</html>
