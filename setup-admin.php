<?php
require __DIR__ . '/bootstrap.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Admin setup is available only from this computer.');
}

try {
    $roleType = $pdo->query(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_accounts' AND COLUMN_NAME = 'role'"
    )->fetchColumn();
    if (is_string($roleType) && strpos($roleType, "'admin'") === false) {
        $pdo->exec("ALTER TABLE user_accounts MODIFY role ENUM('buyer', 'farmer', 'transporter', 'admin') NOT NULL");
    }
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM user_accounts WHERE role = 'admin'")->fetchColumn();
} catch (PDOException $exception) {
    error_log('MFC admin schema migration failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Admin setup could not update the database. Check the database schema and permissions.');
}

if ($adminCount > 0) {
    http_response_code(404);
    exit('Admin setup is already complete.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter the email and password of your existing buyer account.';
    } else {
        $lockAcquired = false;
        try {
            $lockAcquired = (int) $pdo->query("SELECT GET_LOCK('mfc_initial_admin_setup', 5)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                $error = 'Admin setup is busy. Wait a moment and try again.';
            } elseif ((int) $pdo->query("SELECT COUNT(*) FROM user_accounts WHERE role = 'admin'")->fetchColumn() > 0) {
                $error = 'Admin setup is already complete.';
            } else {
                $statement = $pdo->prepare(
                    "SELECT user_id, password_hash
                     FROM user_accounts
                     WHERE email = :email AND role = 'buyer' AND account_status = 'active'
                     LIMIT 1"
                );
                $statement->execute(['email' => $email]);
                $buyer = $statement->fetch();

                if (!$buyer || !password_verify($password, $buyer['password_hash'])) {
                    $error = 'That buyer email or password is not correct.';
                } else {
                    $promote = $pdo->prepare(
                        "UPDATE user_accounts SET role = 'admin'
                         WHERE user_id = :id AND role = 'buyer'"
                    );
                    $promote->execute(['id' => $buyer['user_id']]);

                    if ($promote->rowCount() !== 1) {
                        $error = 'The admin account could not be created. Reload and try again.';
                    } else {
                        sign_in_user($buyer['user_id'], 'admin', $email);
                        redirect_to('admin_dashboard.php');
                    }
                }
            }
        } catch (PDOException $exception) {
            error_log('MFC initial admin setup failed: ' . $exception->getMessage());
            $error = 'Admin setup failed. Make sure the updated database schema is installed.';
        } finally {
            if ($lockAcquired) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('mfc_initial_admin_setup')");
                } catch (PDOException $releaseException) {
                    error_log('MFC initial admin setup lock release failed: ' . $releaseException->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set up administrator | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <main class="auth-main">
        <section class="auth-card">
            <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
            <h1>Set up the first administrator</h1>
            <p>This one-time setup works only on this computer. First create a buyer account, then enter its sign-in details here.</p>
            <?php if ($error !== ''): ?>
                <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <form class="auth-form" method="post" action="setup-admin.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="email">Buyer account email</label>
                <input id="email" name="email" type="email" autocomplete="email" required value="<?= escape_html($_POST['email'] ?? '') ?>">
                <label for="password">Buyer account password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="form-submit" type="submit">Set up admin account</button>
            </form>
            <p><a href="login.php">Back to sign in</a></p>
        </section>
    </main>
</body>
</html>
