<?php
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect_to('index.php');
}

log_activity($pdo, 'USER_LOGIN', "User {$email} signed in.", $user['user_id'], $user['role']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT user_id, email, password_hash, role, account_status
                 FROM user_accounts WHERE email = :email LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user && $user['account_status'] === 'active' && password_verify($password, $user['password_hash'])) {
                $name = $user['email'];
                if ($user['role'] === 'buyer') {
                    $nameStatement = $pdo->prepare('SELECT full_name_or_company_name FROM buyers_table WHERE buyer_id = :id');
                    $nameStatement->execute(['id' => $user['user_id']]);
                    $name = (string) ($nameStatement->fetchColumn() ?: $name);
                } elseif ($user['role'] === 'farmer') {
                    $nameStatement = $pdo->prepare('SELECT full_name FROM farmers_table WHERE farmer_id = :id');
                    $nameStatement->execute(['id' => $user['user_id']]);
                    $name = (string) ($nameStatement->fetchColumn() ?: $name);
                } elseif ($user['role'] === 'transporter') {
                    $nameStatement = $pdo->prepare('SELECT full_name FROM transporters_table WHERE transporter_id = :id');
                    $nameStatement->execute(['id' => $user['user_id']]);
                    $name = (string) ($nameStatement->fetchColumn() ?: $name);
                }

                sign_in_user($user['user_id'], $user['role'], $name);
                redirect_to('index.php');
            }

            $error = 'Email or password is incorrect.';
        } catch (PDOException $exception) {
            error_log('MFC login failed: ' . $exception->getMessage());
            $error = 'We could not sign you in right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <main class="auth-main">
        <section class="auth-card">
            <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
            <h1>Sign in</h1>
            <p>Access your Mwea Fresh Connect account.</p>
            <?php if ($error !== ''): ?>
                <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <form class="auth-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email" required value="<?= escape_html($_POST['email'] ?? '') ?>">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="form-submit" type="submit">Sign in</button>
            </form>
            <p>New buyer? <a href="register.php">Create an account</a>.</p>
            <p>Farmer or transporter? <a href="apply.php">Apply for approval</a>.</p>
            <p>Already approved? <a href="activate.php">Create your account</a>.</p>
        </section>
    </main>
</body>
</html>
