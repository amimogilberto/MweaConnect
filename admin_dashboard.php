<?php
require __DIR__ . '/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/application_documents.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'promote_buyer') {
        $userId = (string) ($_POST['user_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{30}$/', $userId)) {
            $error = 'That account selection is invalid.';
        } else {
            try {
                $statement = $pdo->prepare(
                    "UPDATE user_accounts SET role = 'admin'
                     WHERE user_id = :id AND role = 'buyer' AND account_status = 'active'"
                );
                $statement->execute(['id' => $userId]);

                if ($statement->rowCount() !== 1) {
                    $error = 'Only active buyer accounts can be promoted.';
                } else {
                    $_SESSION['admin_message'] = 'Buyer account promoted to administrator.';
                    redirect_to('admin_dashboard.php');
                }
            } catch (PDOException $exception) {
                error_log('MFC admin promotion failed: ' . $exception->getMessage());
                $error = 'We could not promote that account. Please try again.';
            }
        }
    } else {
        $type = (string) ($_POST['type'] ?? '');
        $applicationId = (string) ($_POST['application_id'] ?? '');
        $decision = (string) ($_POST['decision'] ?? '');
        $applicationTables = [
            'farmer' => 'farmer_applications',
            'transporter' => 'transporter_applications',
        ];

        if (!isset($applicationTables[$type]) || !preg_match('/^[a-f0-9]{30}$/', $applicationId) || !in_array($decision, ['approved', 'rejected'], true)) {
            $error = 'That review request is invalid.';
        } else {
            try {
                $table = $applicationTables[$type];
                $statement = $pdo->prepare(
                    "UPDATE {$table}
                     SET status = :status, reviewed_at = NOW()
                     WHERE application_id = :id AND status = 'pending'"
                );
                $statement->execute(['status' => $decision, 'id' => $applicationId]);

                if ($statement->rowCount() !== 1) {
                    $error = 'That application is no longer pending.';
                } else {
                    log_activity($pdo, 'APPLICATION_' . strtoupper($decision), "Admin {$decision} {$type} application {$applicationId}.");
                    $_SESSION['admin_message'] = 'Application ' . $decision . '.';
                    redirect_to('admin_dashboard.php');
                }
            } catch (PDOException $exception) {
                error_log('MFC admin application review failed: ' . $exception->getMessage());
                $error = 'We could not update that application. Please try again.';
            }
        }
    }
}

try {
    ensure_application_document_columns($pdo);
    $accountCounts = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN role = 'buyer' THEN 1 ELSE 0 END), 0) AS buyers,
                COALESCE(SUM(CASE WHEN role = 'farmer' THEN 1 ELSE 0 END), 0) AS farmers,
                COALESCE(SUM(CASE WHEN role = 'transporter' THEN 1 ELSE 0 END), 0) AS transporters,
                COALESCE(SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END), 0) AS administrators
         FROM user_accounts"
    )->fetch();
    $accounts = $pdo->query(
        'SELECT ua.user_id, ua.email, ua.role, ua.account_status, ua.created_at,
                COALESCE(b.full_name_or_company_name, f.full_name, t.full_name, ua.email) AS display_name
         FROM user_accounts ua
         LEFT JOIN buyers_table b ON b.buyer_id = ua.user_id
         LEFT JOIN farmers_table f ON f.farmer_id = ua.user_id
         LEFT JOIN transporters_table t ON t.transporter_id = ua.user_id
         ORDER BY ua.created_at DESC'
    )->fetchAll();
    $farmers = $pdo->query(
        "SELECT application_id, full_name, email, phone_number, location, farm_size, id_number,
                id_front_photo, id_back_photo, submitted_at
         FROM farmer_applications WHERE status = 'pending' ORDER BY submitted_at"
    )->fetchAll();
    $transporters = $pdo->query(
        "SELECT application_id, full_name, email, phone_number, vehicle_number, rate_per_km, id_number,
                id_front_photo, id_back_photo, submitted_at
         FROM transporter_applications WHERE status = 'pending' ORDER BY submitted_at"
    )->fetchAll();
    $orders = $pdo->query(
        'SELECT o.order_id, o.order_status, o.created_at, p.title,
                b.full_name_or_company_name AS buyer_name,
                f.full_name AS farmer_name,
                t.full_name AS transporter_name
         FROM orders o
         JOIN products p ON p.product_id = o.product_id
         JOIN buyers_table b ON b.buyer_id = o.buyer_id
         JOIN farmers_table f ON f.farmer_id = o.farmer_id
         LEFT JOIN transporters_table t ON t.transporter_id = o.transporter_id
         ORDER BY o.created_at DESC
         LIMIT 30'
    )->fetchAll();
    $activityLogs = $pdo->query(
        'SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 30'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('MFC admin dashboard load failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The dashboard could not load. Check the database schema.');
}

$message = (string) ($_SESSION['admin_message'] ?? '');
unset($_SESSION['admin_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin dashboard | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <main class="auth-main admin-main">
        <section class="auth-card">
            <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
            <h1>Admin dashboard</h1>
            <p>Review buyer accounts and approve or reject farmer and transporter applications.</p>
            <p><a href="index.php">Home</a></p>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <button class="form-submit" type="submit">Sign out</button>
            </form>
            <?php if ($message !== ''): ?>
                <p class="form-message form-success" role="status"><?= escape_html($message) ?></p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>

            <section class="admin-section" aria-label="User totals">
                <h2>User totals</h2>
                <div class="admin-stats">
                    <article class="admin-stat">
                        <span>Total accounts</span>
                        <strong><?= escape_html($accountCounts['total']) ?></strong>
                    </article>
                    <article class="admin-stat">
                        <span>Buyers</span>
                        <strong><?= escape_html($accountCounts['buyers']) ?></strong>
                    </article>
                    <article class="admin-stat">
                        <span>Farmers</span>
                        <strong><?= escape_html($accountCounts['farmers']) ?></strong>
                    </article>
                    <article class="admin-stat">
                        <span>Transporters</span>
                        <strong><?= escape_html($accountCounts['transporters']) ?></strong>
                    </article>
                    <article class="admin-stat">
                        <span>Administrators</span>
                        <strong><?= escape_html($accountCounts['administrators']) ?></strong>
                    </article>
                </div>
            </section>

            <section class="admin-section">
                <h2>Accounts</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <tr>
                                    <td><?= escape_html($account['display_name']) ?></td>
                                    <td><?= escape_html($account['email']) ?></td>
                                    <td><?= escape_html(ucfirst($account['role'])) ?></td>
                                    <td><?= escape_html(ucfirst($account['account_status'])) ?></td>
                                    <td><?= escape_html($account['created_at']) ?></td>
                                    <td>
                                        <?php if ($account['role'] === 'buyer' && $account['account_status'] === 'active'): ?>
                                            <form method="post" action="admin_dashboard.php">
                                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="promote_buyer">
                                                <input type="hidden" name="user_id" value="<?= escape_html($account['user_id']) ?>">
                                                <button class="admin-promote" type="submit">Promote to admin</button>
                                            </form>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$accounts): ?>
                                <tr><td colspan="6">No accounts have been created yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="admin-section">
                <h2>Farmer applications awaiting review</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Applicant</th><th>Contact</th><th>Farm</th><th>ID number</th><th>ID photos</th><th>Submitted</th><th>Decision</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($farmers as $farmer): ?>
                                <tr>
                                    <td><?= escape_html($farmer['full_name']) ?></td>
                                    <td><?= escape_html($farmer['email']) ?><br><?= escape_html($farmer['phone_number']) ?></td>
                                    <td><?= escape_html($farmer['location']) ?>, <?= escape_html($farmer['farm_size']) ?> acres</td>
                                    <td><?= escape_html($farmer['id_number']) ?></td>
                                    <td>
                                        <?php if (!empty($farmer['id_front_photo'])): ?>
                                            <a href="admin_application_document.php?type=farmer&amp;application_id=<?= escape_html($farmer['application_id']) ?>&amp;side=front" target="_blank" rel="noopener">Front</a>
                                        <?php endif; ?>
                                        <?php if (!empty($farmer['id_back_photo'])): ?>
                                            <br>
                                            <a href="admin_application_document.php?type=farmer&amp;application_id=<?= escape_html($farmer['application_id']) ?>&amp;side=back" target="_blank" rel="noopener">Back</a>
                                        <?php endif; ?>
                                        <?php if (empty($farmer['id_front_photo']) && empty($farmer['id_back_photo'])): ?>
                                            Not available
                                        <?php endif; ?>
                                    </td>
                                    <td><?= escape_html($farmer['submitted_at']) ?></td>
                                    <td>
                                        <form class="review-actions" method="post" action="admin_dashboard.php">
                                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                            <input type="hidden" name="type" value="farmer">
                                            <input type="hidden" name="application_id" value="<?= escape_html($farmer['application_id']) ?>">
                                            <button class="review-approve" type="submit" name="decision" value="approved">Approve</button>
                                            <button class="review-reject" type="submit" name="decision" value="rejected">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$farmers): ?>
                                <tr><td colspan="7">No farmer applications are waiting for review.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="admin-section">
                <h2>Transporter applications awaiting review</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Applicant</th><th>Contact</th><th>Vehicle</th><th>Rate/km</th><th>ID number</th><th>ID photos</th><th>Submitted</th><th>Decision</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transporters as $transporter): ?>
                                <tr>
                                    <td><?= escape_html($transporter['full_name']) ?></td>
                                    <td><?= escape_html($transporter['email']) ?><br><?= escape_html($transporter['phone_number']) ?></td>
                                    <td><?= escape_html($transporter['vehicle_number']) ?></td>
                                    <td><?= escape_html($transporter['rate_per_km']) ?></td>
                                    <td><?= escape_html($transporter['id_number']) ?></td>
                                    <td>
                                        <?php if (!empty($transporter['id_front_photo'])): ?>
                                            <a href="admin_application_document.php?type=transporter&amp;application_id=<?= escape_html($transporter['application_id']) ?>&amp;side=front" target="_blank" rel="noopener">Front</a>
                                        <?php endif; ?>
                                        <?php if (!empty($transporter['id_back_photo'])): ?>
                                            <br>
                                            <a href="admin_application_document.php?type=transporter&amp;application_id=<?= escape_html($transporter['application_id']) ?>&amp;side=back" target="_blank" rel="noopener">Back</a>
                                        <?php endif; ?>
                                        <?php if (empty($transporter['id_front_photo']) && empty($transporter['id_back_photo'])): ?>
                                            Not available
                                        <?php endif; ?>
                                    </td>
                                    <td><?= escape_html($transporter['submitted_at']) ?></td>
                                    <td>
                                        <form class="review-actions" method="post" action="admin_dashboard.php">
                                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                            <input type="hidden" name="type" value="transporter">
                                            <input type="hidden" name="application_id" value="<?= escape_html($transporter['application_id']) ?>">
                                            <button class="review-approve" type="submit" name="decision" value="approved">Approve</button>
                                            <button class="review-reject" type="submit" name="decision" value="rejected">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$transporters): ?>
                                <tr><td colspan="8">No transporter applications are waiting for review.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="admin-section">
                <h2>Recent orders</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Order</th><th>Buyer</th><th>Farmer</th><th>Transporter</th><th>Status</th><th>Created</th><th>Room</th></tr></thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?= escape_html($order['title']) ?></td>
                                    <td><?= escape_html($order['buyer_name']) ?></td>
                                    <td><?= escape_html($order['farmer_name']) ?></td>
                                    <td><?= escape_html($order['transporter_name'] ?? 'Not assigned') ?></td>
                                    <td><?= escape_html(ucwords(str_replace('_', ' ', $order['order_status']))) ?></td>
                                    <td><?= escape_html($order['created_at']) ?></td>
                                    <td><a href="order_room.php?order_id=<?= rawurlencode($order['order_id']) ?>">Open room</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$orders): ?>
                                <tr><td colspan="7">No orders have been placed yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="admin-section">
                <h2>System Audit Log</h2>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>Created</th><th>User</th><th>Role</th><th>Action</th><th>Description</th><th>IP address</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityLogs as $log): ?>
                                <tr>
                                    <td><?= escape_html($log['created_at'] ?? '') ?></td>
                                    <td><?= escape_html($log['user_id'] ?? '-') ?></td>
                                    <td><?= escape_html($log['role'] ?? '-') ?></td>
                                    <td><?= escape_html($log['action_type'] ?? '-') ?></td>
                                    <td><?= escape_html($log['description'] ?? '') ?></td>
                                    <td><?= escape_html($log['ip_address'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$activityLogs): ?>
                                <tr><td colspan="6">No activity has been logged yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
