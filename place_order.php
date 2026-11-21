<?php
require __DIR__ . '/bootstrap.php';
require_role('buyer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

verify_csrf_token();

$finishOrder = static function (string $status, string $message): void {
    $_SESSION['order_status'] = $status;
    $_SESSION['order_message'] = $message;
    redirect_to('index.php#marketplace');
};

$productId = (string) ($_POST['product_id'] ?? '');
$quantityInput = trim((string) ($_POST['quantity'] ?? ''));
$orderQuantity = filter_var($quantityInput, FILTER_VALIDATE_FLOAT);

if (
    !preg_match('/^[a-f0-9]{30}$/', $productId) ||
    !preg_match('/^\d+(?:\.\d{1,2})?$/D', $quantityInput) ||
    $orderQuantity === false || $orderQuantity <= 0 ||
    $orderQuantity > 99999999.99
) {
    $finishOrder('error', 'Enter a valid product and order quantity.');
}

try {
    $pdo->beginTransaction();
    $productStatement = $pdo->prepare(
        "SELECT product_id, farmer_id, price_per_unit, quantity_available
         FROM products
         WHERE product_id = :product_id AND status = 'active'
           AND quantity_available > 0
         FOR UPDATE"
    );
    $productStatement->execute(['product_id' => $productId]);
    $product = $productStatement->fetch();

    if (!$product) {
        $pdo->rollBack();
        $finishOrder('error', 'That product is no longer available.');
    }

    $availableQuantity = (float) $product['quantity_available'];
    if ($orderQuantity > $availableQuantity) {
        $pdo->rollBack();
        $finishOrder('error', 'The requested quantity is no longer available. Please choose a smaller amount.');
    }

    $pricePerUnit = (float) $product['price_per_unit'];
    $subtotal = round($pricePerUnit * $orderQuantity, 2);
    $commission5Pct = round($subtotal * 0.05, 2);
    $farmerNet = round($subtotal - $commission5Pct, 2);
    $orderId = bin2hex(random_bytes(15));

    $stockStatement = $pdo->prepare(
        "UPDATE products
         SET quantity_available = :remaining_quantity
         WHERE product_id = :product_id AND status = 'active'"
    );
    $stockStatement->execute([
        'remaining_quantity' => round($availableQuantity - $orderQuantity, 2),
        'product_id' => $productId,
    ]);

    $orderStatement = $pdo->prepare(
        "INSERT INTO orders
            (order_id, buyer_id, farmer_id, product_id, quantity, produce_subtotal,
             platform_commission_5pct, farmer_net_payout, order_status)
         VALUES
            (:order_id, :buyer_id, :farmer_id, :product_id, :quantity, :subtotal,
             :commission, :farmer_net, 'confirmed')"
    );
    $orderStatement->execute([
        'order_id' => $orderId,
        'buyer_id' => (string) $_SESSION['user_id'],
        'farmer_id' => $product['farmer_id'],
        'product_id' => $productId,
        'quantity' => $orderQuantity,
        'subtotal' => $subtotal,
        'commission' => $commission5Pct,
        'farmer_net' => $farmerNet,
    ]);
    $pdo->commit();

    log_activity($pdo, 'ORDER_PLACED', "Buyer placed order {$orderId} for {$orderQuantity} units.");
    $_SESSION['order_room_id'] = $orderId;
    $finishOrder(
        'success',
        'Order placed successfully. Reference: ' . $orderId . '. Farmer net payout after the 5% platform commission: KES ' . number_format($farmerNet, 2) . '.'
    );
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('MFC order placement failed: ' . $exception->getMessage());
    $finishOrder('error', 'We could not place the order. Please try again.');
}
