<?php
require __DIR__ . '/bootstrap.php';

if (empty($_SESSION['user_id'])) {
    redirect_to('login.php');
}

$userId = (string) $_SESSION['user_id'];
$role = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($role, ['buyer', 'farmer', 'transporter', 'admin'], true)) {
    http_response_code(403);
    exit('You are not allowed to use this order room.');
}

$orderId = (string) ($_GET['order_id'] ?? $_POST['order_id'] ?? '');
if (!preg_match('/^[a-f0-9]{30}$/', $orderId)) {
    http_response_code(400);
    exit('That order reference is invalid.');
}

try {
    $orderStatement = $pdo->prepare(
        'SELECT o.*, p.title, p.unit_label,
                f.full_name AS farmer_name,
                b.full_name_or_company_name AS buyer_name,
                t.full_name AS transporter_name
         FROM orders o
         JOIN products p ON p.product_id = o.product_id
         JOIN farmers_table f ON f.farmer_id = o.farmer_id
         JOIN buyers_table b ON b.buyer_id = o.buyer_id
         LEFT JOIN transporters_table t ON t.transporter_id = o.transporter_id
         WHERE o.order_id = :order_id'
    );
    $orderStatement->execute(['order_id' => $orderId]);
    $order = $orderStatement->fetch();
} catch (PDOException $exception) {
    error_log('MFC order room lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The order room could not load.');
}

$isParticipant = $order && (
    $role === 'admin' ||
    ($role === 'buyer' && $order['buyer_id'] === $userId) ||
    ($role === 'farmer' && $order['farmer_id'] === $userId) ||
    ($role === 'transporter' && $order['transporter_id'] === $userId)
);
if (!$isParticipant) {
    http_response_code(404);
    exit('The order was not found.');
}

$error = '';
$trackingStatuses = ['assigned', 'in_transit'];
$closedStatuses = ['cancelled', 'completed', 'delivered'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'send_message') {
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '' || strlen($message) > 2000) {
            $error = 'Enter a message of up to 2,000 characters.';
        } else {
            try {
                $messageStatement = $pdo->prepare(
                    'INSERT INTO order_messages (order_id, sender_id, sender_role, message)
                     VALUES (:order_id, :sender_id, :sender_role, :message)'
                );
                $messageStatement->execute([
                    'order_id' => $orderId,
                    'sender_id' => $userId,
                    'sender_role' => $role,
                    'message' => $message,
                ]);
                redirect_to('order_room.php?order_id=' . rawurlencode($orderId));
            } catch (PDOException $exception) {
                error_log('MFC order room message failed: ' . $exception->getMessage());
                $error = 'We could not send your message. Please try again.';
            }
        }
    } elseif ($action === 'confirm_delivery') {
        if ($role !== 'buyer') {
            $error = 'Only the buyer can confirm delivery.';
        } elseif ($order['order_status'] !== 'in_transit' || $order['transporter_id'] === null) {
            $error = 'Delivery can be confirmed after the transporter has started the delivery.';
        } else {
            try {
                $deliveryStatement = $pdo->prepare(
                    "UPDATE orders
                     SET order_status = 'delivered'
                     WHERE order_id = :order_id AND buyer_id = :buyer_id
                       AND transporter_id IS NOT NULL AND order_status = 'in_transit'"
                );
                $deliveryStatement->execute(['order_id' => $orderId, 'buyer_id' => $userId]);

                if ($deliveryStatement->rowCount() !== 1) {
                    $error = 'The order status changed before delivery could be confirmed. Reload and try again.';
                } else {
                    log_activity($pdo, 'ORDER_DELIVERED', "Buyer confirmed delivery for order {$orderId}.");
                    redirect_to('order_room.php?order_id=' . rawurlencode($orderId));
                }
            } catch (PDOException $exception) {
                error_log('MFC order delivery confirmation failed: ' . $exception->getMessage());
                $error = 'We could not confirm delivery. Please try again.';
            }
        }
    } elseif ($action === 'submit_rating') {
        $ratingTarget = (string) ($_POST['rating_target'] ?? '');
        $stars = filter_var($_POST['stars'] ?? null, FILTER_VALIDATE_INT);
        $rateeId = null;
        if ($ratingTarget === 'farmer') {
            $rateeId = (string) $order['farmer_id'];
        } elseif ($ratingTarget === 'transporter' && $order['transporter_id'] !== null) {
            $rateeId = (string) $order['transporter_id'];
        }

        if ($role !== 'buyer' || $order['order_status'] !== 'delivered') {
            $error = 'Ratings are available to the buyer after delivery is confirmed.';
        } elseif ($rateeId === null || $stars === false || $stars < 1 || $stars > 5) {
            $error = 'Choose a valid participant and a rating from 1 to 5 stars.';
        } else {
            try {
                $ratingStatement = $pdo->prepare(
                    'INSERT INTO user_ratings (order_id, rater_id, ratee_id, stars)
                     VALUES (:order_id, :rater_id, :ratee_id, :stars)'
                );
                $ratingStatement->execute([
                    'order_id' => $orderId,
                    'rater_id' => $userId,
                    'ratee_id' => $rateeId,
                    'stars' => $stars,
                ]);
                log_activity($pdo, 'ORDER_PARTICIPANT_RATED', "Buyer rated the {$ratingTarget} for order {$orderId}.");
                redirect_to('order_room.php?order_id=' . rawurlencode($orderId));
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'You have already rated that participant for this order.';
                } else {
                    error_log('MFC order participant rating failed: ' . $exception->getMessage());
                    $error = 'We could not save your rating. Please try again.';
                }
            }
        }
    } else {
        $error = 'That order room action is invalid.';
    }
}

try {
    $messagesStatement = $pdo->prepare(
        'SELECT message, sender_id, sender_role, created_at
         FROM order_messages
         WHERE order_id = :order_id
         ORDER BY created_at, message_id'
    );
    $messagesStatement->execute(['order_id' => $orderId]);
    $messages = $messagesStatement->fetchAll();

    $myRatings = [];
    if ($role === 'buyer') {
        $ratingsStatement = $pdo->prepare(
            'SELECT ratee_id, stars FROM user_ratings
             WHERE order_id = :order_id AND rater_id = :rater_id'
        );
        $ratingsStatement->execute(['order_id' => $orderId, 'rater_id' => $userId]);
        foreach ($ratingsStatement->fetchAll() as $rating) {
            $myRatings[$rating['ratee_id']] = (int) $rating['stars'];
        }
    }
} catch (PDOException $exception) {
    error_log('MFC order room data load failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The order room data could not load.');
}

$trackingAvailable = in_array($order['order_status'], $trackingStatuses, true);
$trackingClosed = in_array($order['order_status'], $closedStatuses, true);
$backHref = [
    'buyer' => 'account.php',
    'farmer' => 'farmer_dashboard.php',
    'transporter' => 'transporter_dashboard.php',
    'admin' => 'admin_dashboard.php',
][$role];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= escape_html(csrf_token()) ?>">
    <meta name="referrer" content="no-referrer">
    <title>Order room | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
    <?php if ($trackingAvailable): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
</head>
<body class="auth-page">
<main class="auth-main admin-main">
    <section class="auth-card">
        <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
        <h1>Shared order room</h1>
        <p>Order <?= escape_html($order['order_id']) ?> · <?= escape_html(ucwords(str_replace('_', ' ', $order['order_status']))) ?></p>
        <p><a href="<?= escape_html($backHref) ?>">Back to workspace</a></p>

        <?php if ($error !== ''): ?>
            <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="admin-section" aria-label="Order details">
            <h2><?= escape_html($order['title']) ?></h2>
            <p>Buyer: <?= escape_html($order['buyer_name']) ?></p>
            <p>Farmer: <?= escape_html($order['farmer_name']) ?></p>
            <p>Transporter: <?= escape_html($order['transporter_name'] ?? 'Not assigned') ?></p>
            <p>Quantity: <?= escape_html($order['quantity'] . ' ' . $order['unit_label']) ?></p>
        </section>

        <section class="admin-section" aria-label="Live delivery map">
            <h2>Live delivery map</h2>
            <?php if ($trackingAvailable): ?>
                <div id="order-map" class="order-map" role="img" aria-label="Map showing the assigned transporter's latest location"></div>
                <p id="map-status" role="status">Waiting for the transporter’s location.</p>
                <?php if ($role === 'transporter' && $order['transporter_id'] === $userId): ?>
                    <p id="gps-share-status" role="status">Requesting location permission to share your live position…</p>
                    <button id="stop-gps-sharing" class="form-submit" type="button">Stop sharing location</button>
                <?php endif; ?>
            <?php elseif ($trackingClosed): ?>
                <p>Live tracking has ended for this order.</p>
            <?php else: ?>
                <p>Live tracking will be available after a transporter claims the order.</p>
            <?php endif; ?>
        </section>

        <?php if ($role === 'buyer' && $order['order_status'] === 'in_transit' && $order['transporter_id'] !== null): ?>
            <form class="auth-form" method="post" action="order_room.php?order_id=<?= rawurlencode($orderId) ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= escape_html($orderId) ?>">
                <input type="hidden" name="action" value="confirm_delivery">
                <button class="form-submit" type="submit">Confirm Delivery Received</button>
            </form>
        <?php endif; ?>

        <?php if ($role === 'buyer' && $order['order_status'] === 'delivered'): ?>
            <section class="admin-section" aria-label="Rate order participants">
                <h2>Rate your order</h2>
                <?php foreach ([
                    'farmer' => ['id' => $order['farmer_id'], 'name' => $order['farmer_name']],
                    'transporter' => ['id' => $order['transporter_id'], 'name' => $order['transporter_name']],
                ] as $target => $participant): ?>
                    <?php if ($participant['id'] !== null): ?>
                        <?php if (isset($myRatings[$participant['id']])): ?>
                            <p><?= escape_html(ucfirst($target)) ?> rating for <?= escape_html($participant['name']) ?>: <?= escape_html($myRatings[$participant['id']]) ?> ★</p>
                        <?php else: ?>
                            <form class="auth-form" method="post" action="order_room.php?order_id=<?= rawurlencode($orderId) ?>">
                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                <input type="hidden" name="order_id" value="<?= escape_html($orderId) ?>">
                                <input type="hidden" name="action" value="submit_rating">
                                <input type="hidden" name="rating_target" value="<?= escape_html($target) ?>">
                                <label for="stars-<?= escape_html($target) ?>">Rate <?= escape_html($target) ?> <?= escape_html($participant['name']) ?></label>
                                <select id="stars-<?= escape_html($target) ?>" name="stars" required>
                                    <option value="">Choose a rating</option>
                                    <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                                        <option value="<?= $stars ?>"><?= $stars ?> ★</option>
                                    <?php endfor; ?>
                                </select>
                                <button class="form-submit" type="submit">Submit rating</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="admin-section" aria-label="Three-way order chat">
            <h2>Order chat</h2>
            <div class="order-messages">
                <?php foreach ($messages as $chatMessage): ?>
                    <article class="order-message">
                        <p><strong class="role-badge role-<?= escape_html($chatMessage['sender_role']) ?>">[<?= escape_html(ucfirst($chatMessage['sender_role'])) ?>]</strong>
                            <?= $chatMessage['sender_id'] === $userId ? '(you)' : '' ?>
                            <time datetime="<?= escape_html($chatMessage['created_at']) ?>"><?= escape_html($chatMessage['created_at']) ?></time>
                        </p>
                        <div><?= nl2br(escape_html($chatMessage['message'])) ?></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <p class="marketplace-empty">No messages yet. Start the coordination here.</p>
                <?php endif; ?>
            </div>
            <form class="auth-form" method="post" action="order_room.php?order_id=<?= rawurlencode($orderId) ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= escape_html($orderId) ?>">
                <input type="hidden" name="action" value="send_message">
                <label for="message">Message</label>
                <textarea id="message" name="message" maxlength="2000" rows="4" required></textarea>
                <button class="form-submit" type="submit">Send message</button>
            </form>
        </section>
    </section>
</main>
<?php if ($trackingAvailable): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (() => {
        const orderId = <?= json_encode($orderId) ?>;
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const status = document.getElementById('map-status');
        const map = L.map('order-map').setView([-0.5, 37.2], 8);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        const truckIcon = L.divIcon({
            html: '🚚',
            className: 'truck-marker',
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        });
        const truckMarker = L.marker([-0.5, 37.2], {icon: truckIcon})
            .bindPopup('Assigned transporter')
            .addTo(map);
        let positioned = false;

        const refreshLocation = async () => {
            try {
                const response = await fetch('api_gps.php?order_id=' + encodeURIComponent(orderId), {
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store'
                });
                const location = await response.json();
                if (!response.ok) {
                    throw new Error(location.error || 'Could not load the latest location.');
                }
                if (location.latitude !== undefined && location.longitude !== undefined) {
                    const latitude = Number(location.latitude);
                    const longitude = Number(location.longitude);
                    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                        throw new Error('The latest location data is invalid.');
                    }
                    const coordinates = [latitude, longitude];
                    truckMarker.setLatLng(coordinates);
                    if (!positioned) {
                        map.setView(coordinates, 14);
                        positioned = true;
                    }
                    status.textContent = 'Latest position reported ' + location.updated_at + '.';
                } else {
                    status.textContent = 'Waiting for the transporter’s location.';
                }
            } catch (error) {
                status.textContent = error.message || 'Could not load the latest location.';
            }
        };

        refreshLocation();
        window.setInterval(refreshLocation, 5000);

        <?php if ($role === 'transporter' && $order['transporter_id'] === $userId): ?>
        const shareStatus = document.getElementById('gps-share-status');
        const stopSharingButton = document.getElementById('stop-gps-sharing');
        let watchId = null;
        const stopSharing = () => {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            stopSharingButton.hidden = true;
            shareStatus.textContent = 'Location sharing stopped.';
        };
        stopSharingButton.addEventListener('click', stopSharing);
        if (!navigator.geolocation) {
            shareStatus.textContent = 'This browser does not support location sharing.';
        } else {
            watchId = navigator.geolocation.watchPosition(async (position) => {
                try {
                    const response = await fetch('api_gps.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            order_id: orderId,
                            lat: position.coords.latitude,
                            lng: position.coords.longitude
                        })
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        if (response.status === 404) {
                            stopSharing();
                            return;
                        }
                        throw new Error(result.error || 'Could not share your location.');
                    }
                    shareStatus.textContent = 'Sharing live location (accuracy ±' + Math.round(position.coords.accuracy) + ' m).';
                } catch (error) {
                    shareStatus.textContent = error.message || 'Could not share your location.';
                }
            }, (error) => {
                if (watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                shareStatus.textContent = error.message || 'Location permission or signal is unavailable.';
            }, {
                enableHighAccuracy: true,
                maximumAge: 5000,
                timeout: 15000
            });
        }
        <?php endif; ?>
    })();
    </script>
<?php endif; ?>
</body>
</html>
