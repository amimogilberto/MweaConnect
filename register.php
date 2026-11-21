<?php
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect_to('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
    $buyerType = (string) ($_POST['buyer_type'] ?? 'individual');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($fullName === '' || strlen($fullName) > 100) {
        $error = 'Enter your name (up to 100 characters).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!preg_match('/^\+?[0-9]{7,14}$/', $phone)) {
        $error = 'Enter a valid phone number, including country code if needed.';
    } elseif (!in_array($buyerType, ['individual', 'business'], true)) {
        $error = 'Choose an account type.';
    } elseif (strlen($password) < 10) {
        $error = 'Use a password with at least 10 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        $userId = bin2hex(random_bytes(15));

        try {
            $pdo->beginTransaction();
            $buyerStatement = $pdo->prepare(
                'INSERT INTO buyers_table (buyer_id, full_name_or_company_name, phone_number, buyer_type)
                 VALUES (:id, :name, :phone, :type)'
            );
            $buyerStatement->execute([
                'id' => $userId,
                'name' => $fullName,
                'phone' => $phone,
                'type' => $buyerType,
            ]);

            $accountStatement = $pdo->prepare(
                'INSERT INTO user_accounts (user_id, email, password_hash, role)
                 VALUES (:id, :email, :password_hash, :role)'
            );
            $accountStatement->execute([
                'id' => $userId,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'buyer',
            ]);
            $pdo->commit();

            sign_in_user($userId, 'buyer', $fullName);
            redirect_to('index.php');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('MFC buyer registration failed: ' . $exception->getMessage());
            $error = $exception->getCode() === '23000'
                ? 'That email or phone number is already registered.'
                : 'We could not create your account right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create buyer account | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <main class="auth-main">
        <section class="auth-card">
            <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
            <h1>Create a buyer account</h1>
            <p>Buyers can sign up immediately. Farmers and transporters must apply for approval.</p>
            <?php if ($error !== ''): ?>
                <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <form class="auth-form" method="post" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="full_name">Full name or business name</label>
                <input id="full_name" name="full_name" type="text" maxlength="100" autocomplete="name" required value="<?= escape_html($_POST['full_name'] ?? '') ?>">
                <label for="buyer_type">Buyer type</label>
                <select id="buyer_type" name="buyer_type">
                    <option value="individual">Individual</option>
                    <option value="business">Business</option>
                </select>
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= escape_html($_POST['email'] ?? '') ?>">
                <label for="phone_number">Phone number</label>
                <input id="phone_number" name="phone_number" type="tel" maxlength="20" autocomplete="tel" required value="<?= escape_html($_POST['phone_number'] ?? '') ?>">
                <label for="password">Password (at least 10 characters)</label>
                <input id="password" name="password" type="password" minlength="10" autocomplete="new-password" required>
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required>
                <button class="form-submit" type="submit">Create account</button>
            </form>
            <p>Already have an account? <a href="login.php">Sign in</a>.</p>
        </section>
    </main>
</body>
</html>
