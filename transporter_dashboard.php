<?php
require __DIR__ . '/bootstrap.php';
require_role('transporter');

$transporterId = (string) $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = (string) ($_POST['action'] ?? '');
    $orderId = (string) ($_POST['order_id'] ?? '');

    if ($action !== 'claim_job' || !preg_match('/^[a-f0-9]{30}$/', $orderId)) {
        $error = 'That job request is invalid.';
    } else {
        try {
            $claimStatement = $pdo->prepare(
                "UPDATE orders
                 SET transporter_id = :transporter_id, order_status = 'assigned'
                 WHERE order_id = :order_id AND order_status = 'confirmed'
                   AND transporter_id IS NULL"
            );
            $claimStatement->execute([
                'transporter_id' => $transporterId,
                'order_id' => $orderId,
            ]);

            if ($claimStatement->rowCount() !== 1) {
                $error = 'That job has already been claimed or is no longer available.';
            } else {
                log_activity($pdo, 'TRANSPORT_JOB_CLAIMED', "Transporter claimed order {$orderId}.");
                $success = 'Job claimed. You can now share your live location.';
            }
        } catch (PDOException $exception) {
            error_log('MFC transporter job claim failed: ' . $exception->getMessage());
            $error = 'We could not claim that job. Please try again.';
        }
    }
}

try {
    $availableJobs = $pdo->query(
        "SELECT o.order_id, o.quantity, o.produce_subtotal, o.created_at,
                p.title, p.unit_label, f.full_name AS farmer_name, f.location AS pickup_location
         FROM orders o
         JOIN products p ON p.product_id = o.product_id
         JOIN farmers_table f ON f.farmer_id = o.farmer_id
         WHERE o.order_status = 'confirmed' AND o.transporter_id IS NULL
         ORDER BY o.created_at"
    )->fetchAll();

    $assignedJobsStatement = $pdo->prepare(
        "SELECT o.order_id, o.quantity, o.produce_subtotal, o.order_status, o.created_at,
                p.title, p.unit_label, f.full_name AS farmer_name, f.location AS pickup_location,
                gps.latitude, gps.longitude, gps.updated_at AS gps_updated_at
         FROM orders o
         JOIN products p ON p.product_id = o.product_id
         JOIN farmers_table f ON f.farmer_id = o.farmer_id
         LEFT JOIN order_gps_tracking gps
           ON gps.order_id = o.order_id AND gps.transporter_id = o.transporter_id
         WHERE o.transporter_id = :transporter_id
         ORDER BY o.created_at DESC"
    );
    $assignedJobsStatement->execute(['transporter_id' => $transporterId]);
    $assignedJobs = $assignedJobsStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('MFC transporter dashboard load failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The transporter dashboard could not load. Check the database schema.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= escape_html(csrf_token()) ?>">
    <title>Transporter Job Board | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
<main class="auth-main admin-main">
    <section class="auth-card">
        <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
        <h1>Transporter Job Board</h1>
        <p>Welcome, <?= escape_html($_SESSION['user_name'] ?? '') ?>. Claim a pickup and share your live location while it is active.</p>
        <p><a href="index.php">Home</a></p>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <button class="form-submit" type="submit">Sign out</button>
        </form>

        <?php if ($success !== ''): ?>
            <p class="form-message form-success" role="status"><?= escape_html($success) ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="admin-section">
            <h2>Available pickup jobs</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Produce</th><th>Pickup location</th><th>Farmer</th><th>Quantity</th><th>Order value</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($availableJobs as $job): ?>
                        <tr>
                            <td><?= escape_html($job['title']) ?></td>
                            <td><?= escape_html($job['pickup_location'] ?? 'Not provided') ?></td>
                            <td><?= escape_html($job['farmer_name']) ?></td>
                            <td><?= escape_html($job['quantity'] . ' ' . $job['unit_label']) ?></td>
                            <td>KES <?= number_format((float) $job['produce_subtotal'], 2) ?></td>
                            <td>
                                <form method="post" action="transporter_dashboard.php">
                                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="claim_job">
                                    <input type="hidden" name="order_id" value="<?= escape_html($job['order_id']) ?>">
                                    <button class="form-submit" type="submit">Claim job</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$availableJobs): ?>
                        <tr><td colspan="6">There are no pickup jobs available right now.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-section">
            <h2>Your jobs and order rooms</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Produce</th><th>Pickup location</th><th>Quantity</th><th>Status</th><th>Latest location</th><th>Coordination</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignedJobs as $job): ?>
                        <tr>
                            <td><?= escape_html($job['title']) ?></td>
                            <td><?= escape_html($job['pickup_location'] ?? 'Not provided') ?></td>
                            <td><?= escape_html($job['quantity'] . ' ' . $job['unit_label']) ?></td>
                            <td><?= escape_html(ucwords(str_replace('_', ' ', $job['order_status']))) ?></td>
                            <td>
                                <?php if (in_array($job['order_status'], ['assigned', 'in_transit'], true) && $job['latitude'] !== null && $job['longitude'] !== null): ?>
                                    <span class="gps-location" data-order-id="<?= escape_html($job['order_id']) ?>"><?= escape_html($job['latitude']) ?>, <?= escape_html($job['longitude']) ?></span><br>
                                    <small class="gps-updated" data-order-id="<?= escape_html($job['order_id']) ?>">Updated <?= escape_html($job['gps_updated_at']) ?></small>
                                <?php elseif (in_array($job['order_status'], ['assigned', 'in_transit'], true)): ?>
                                    <span class="gps-location" data-order-id="<?= escape_html($job['order_id']) ?>">Waiting for GPS signal</span>
                                    <small class="gps-updated" data-order-id="<?= escape_html($job['order_id']) ?>"></small>
                                <?php else: ?>
                                    Tracking ended
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="order_room.php?order_id=<?= rawurlencode($job['order_id']) ?>">Open room</a>
                                <?php if (in_array($job['order_status'], ['assigned', 'in_transit'], true)): ?>
                                    <p><button class="form-submit gps-start" type="button" data-order-id="<?= escape_html($job['order_id']) ?>">Start sharing</button></p>
                                    <button class="gps-stop" type="button" data-order-id="<?= escape_html($job['order_id']) ?>" hidden>Stop sharing</button>
                                    <p class="gps-status" data-order-id="<?= escape_html($job['order_id']) ?>" aria-live="polite"></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$assignedJobs): ?>
                        <tr><td colspan="6">Claim an available job to start coordinating a pickup.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const activeWatches = new Map();
    const statusFor = (orderId) => document.querySelector('.gps-status[data-order-id="' + orderId + '"]');

    document.querySelectorAll('.gps-start').forEach((button) => {
        button.addEventListener('click', () => {
            const orderId = button.dataset.orderId;
            const status = statusFor(orderId);
            if (!navigator.geolocation) {
                status.textContent = 'This browser does not support location sharing.';
                return;
            }

            button.disabled = true;
            status.textContent = 'Waiting for location permission…';
            const watchId = navigator.geolocation.watchPosition(async (position) => {
                try {
                    const response = await fetch('api_gps.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': token
                        },
                        body: JSON.stringify({
                            order_id: orderId,
                            lat: position.coords.latitude,
                            lng: position.coords.longitude
                        })
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.error || 'Unable to send location.');
                    }
                    status.textContent = 'Sharing live location (accuracy ±' + Math.round(position.coords.accuracy) + ' m).';
                    document.querySelector('.gps-location[data-order-id="' + orderId + '"]').textContent =
                        position.coords.latitude.toFixed(7) + ', ' + position.coords.longitude.toFixed(7);
                    document.querySelector('.gps-updated[data-order-id="' + orderId + '"]').textContent =
                        'Updated ' + new Date(position.timestamp).toLocaleString();
                } catch (error) {
                    status.textContent = error.message || 'Unable to send location.';
                }
            }, (error) => {
                status.textContent = error.message || 'Location permission or signal is unavailable.';
                navigator.geolocation.clearWatch(watchId);
                button.disabled = false;
                button.hidden = false;
                document.querySelector('.gps-stop[data-order-id="' + orderId + '"]').hidden = true;
                activeWatches.delete(orderId);
            }, {
                enableHighAccuracy: true,
                maximumAge: 5000,
                timeout: 15000
            });
            activeWatches.set(orderId, watchId);
            button.hidden = true;
            document.querySelector('.gps-stop[data-order-id="' + orderId + '"]').hidden = false;
        });
    });

    document.querySelectorAll('.gps-stop').forEach((button) => {
        button.addEventListener('click', () => {
            const orderId = button.dataset.orderId;
            const watchId = activeWatches.get(orderId);
            if (watchId !== undefined) {
                navigator.geolocation.clearWatch(watchId);
                activeWatches.delete(orderId);
            }
            button.hidden = true;
            const startButton = document.querySelector('.gps-start[data-order-id="' + orderId + '"]');
            startButton.hidden = false;
            startButton.disabled = false;
            statusFor(orderId).textContent = 'Location sharing stopped. Last reported position remains visible for this active order.';
        });
    });
})();
</script>
</body>
</html>
