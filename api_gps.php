<?php
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$sendJson = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (empty($_SESSION['user_id'])) {
    $sendJson(401, ['error' => 'Unauthorized']);
}

$userId = (string) $_SESSION['user_id'];
$role = (string) ($_SESSION['user_role'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

if ($method === 'POST') {
    if ($role !== 'transporter') {
        $sendJson(403, ['error' => 'Only transporters can share GPS coordinates.']);
    }

    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $sendJson(400, ['error' => 'The request token is invalid. Reload the page and try again.']);
    }

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $sendJson(400, ['error' => 'Invalid JSON request.']);
    }

    $orderId = (string) ($data['order_id'] ?? '');
    $latitude = filter_var($data['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($data['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if (
        !preg_match('/^[a-f0-9]{30}$/', $orderId) ||
        $latitude === false || $longitude === false ||
        !is_finite((float) $latitude) || !is_finite((float) $longitude) ||
        $latitude < -90 || $latitude > 90 ||
        $longitude < -180 || $longitude > 180
    ) {
        $sendJson(400, ['error' => 'Invalid coordinates or order reference.']);
    }

    try {
        $orderStatement = $pdo->prepare(
            "SELECT order_id FROM orders
             WHERE order_id = :order_id AND transporter_id = :transporter_id
               AND order_status IN ('assigned', 'in_transit')"
        );
        $orderStatement->execute(['order_id' => $orderId, 'transporter_id' => $userId]);
        if (!$orderStatement->fetch()) {
            $sendJson(404, ['error' => 'That active job was not found.']);
        }

        $pdo->beginTransaction();
        $trackingStatement = $pdo->prepare(
            'INSERT INTO order_gps_tracking (order_id, transporter_id, latitude, longitude)
             VALUES (:order_id, :transporter_id, :latitude, :longitude)
             ON DUPLICATE KEY UPDATE transporter_id = VALUES(transporter_id),
                                     latitude = VALUES(latitude),
                                     longitude = VALUES(longitude),
                                     updated_at = CURRENT_TIMESTAMP'
        );
        $trackingStatement->execute([
            'order_id' => $orderId,
            'transporter_id' => $userId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $statusStatement = $pdo->prepare(
            "UPDATE orders SET order_status = 'in_transit'
             WHERE order_id = :order_id AND transporter_id = :transporter_id
               AND order_status = 'assigned'"
        );
        $statusStatement->execute(['order_id' => $orderId, 'transporter_id' => $userId]);
        $pdo->commit();
        $sendJson(200, ['status' => 'updated']);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('MFC GPS update failed: ' . $exception->getMessage());
        $sendJson(500, ['error' => 'The location could not be saved.']);
    }
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    $sendJson(405, ['error' => 'Method not allowed.']);
}

if (!in_array($role, ['buyer', 'farmer', 'transporter', 'admin'], true)) {
    $sendJson(403, ['error' => 'You are not allowed to view order locations.']);
}

$orderId = (string) ($_GET['order_id'] ?? '');
if (!preg_match('/^[a-f0-9]{30}$/', $orderId)) {
    $sendJson(400, ['error' => 'Invalid order reference.']);
}

try {
    $orderStatement = $pdo->prepare(
        "SELECT order_id, buyer_id, farmer_id, transporter_id
         FROM orders
         WHERE order_id = :order_id
          AND order_status NOT IN ('cancelled', 'completed', 'delivered')"
    );
    $orderStatement->execute(['order_id' => $orderId]);
    $order = $orderStatement->fetch();
    if (
        !$order ||
        ($role !== 'admin' && (
        ($role === 'buyer' && $order['buyer_id'] !== $userId) ||
        ($role === 'farmer' && $order['farmer_id'] !== $userId) ||
        ($role === 'transporter' && $order['transporter_id'] !== $userId)
        ))
    ) {
        $sendJson(404, ['error' => 'The active order was not found.']);
    }

    $trackingStatement = $pdo->prepare(
        'SELECT latitude, longitude, updated_at
         FROM order_gps_tracking
         WHERE order_id = :order_id AND transporter_id = :transporter_id'
    );
    $trackingStatement->execute([
        'order_id' => $orderId,
        'transporter_id' => $order['transporter_id'],
    ]);
    $location = $trackingStatement->fetch();
    $sendJson(200, $location ?: ['status' => 'waiting_for_signal']);
} catch (PDOException $exception) {
    error_log('MFC GPS lookup failed: ' . $exception->getMessage());
    $sendJson(500, ['error' => 'The location could not be loaded.']);
}
