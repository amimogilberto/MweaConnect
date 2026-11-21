<?php
require __DIR__ . '/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $applicationId = trim((string) ($_POST['application_id'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!preg_match('/^[a-f0-9]{30}$/', $applicationId)) {
        $error = 'Enter the application reference you received.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address used in your application.';
    } elseif (strlen($password) < 10) {
        $error = 'Use a password with at least 10 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();
            $farmerStatement = $pdo->prepare(
                "SELECT * FROM farmer_applications
                 WHERE application_id = :id AND email = :email AND status = 'approved'
                 FOR UPDATE"
            );
            $farmerStatement->execute(['id' => $applicationId, 'email' => $email]);
            $application = $farmerStatement->fetch();
            $role = 'farmer';

            if (!$application) {
                $transporterStatement = $pdo->prepare(
                    "SELECT * FROM transporter_applications
                     WHERE application_id = :id AND email = :email AND status = 'approved'
                     FOR UPDATE"
                );
                $transporterStatement->execute(['id' => $applicationId, 'email' => $email]);
                $application = $transporterStatement->fetch();
                $role = 'transporter';
            }

            if (!$application) {
                $pdo->rollBack();
                $error = 'We could not find an approved application matching that reference and email.';
            } else {
                $accountStatement = $pdo->prepare(
                    'INSERT INTO user_accounts (user_id, email, password_hash, role)
                     VALUES (:id, :email, :password_hash, :role)'
                );
                $accountStatement->execute([
                    'id' => $applicationId,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                ]);

                if ($role === 'farmer') {
                    $profileStatement = $pdo->prepare(
                        'INSERT INTO farmers_table
                            (farmer_id, full_name, phone_number, location, farm_size, id_number, id_front_photo, id_back_photo)
                         VALUES (:id, :name, :phone, :location, :farm_size, :id_number, :id_front_photo, :id_back_photo)'
                    );
                    $profileStatement->execute([
                        'id' => $applicationId,
                        'name' => $application['full_name'],
                        'phone' => $application['phone_number'],
                        'location' => $application['location'],
                        'farm_size' => $application['farm_size'],
                        'id_number' => $application['id_number'],
                        'id_front_photo' => $application['id_front_photo'],
                        'id_back_photo' => $application['id_back_photo'],
                    ]);
                    $updateStatement = $pdo->prepare(
                        "UPDATE farmer_applications SET status = 'account_created' WHERE application_id = :id"
                    );
                } else {
                    $profileStatement = $pdo->prepare(
                        'INSERT INTO transporters_table
                            (transporter_id, full_name, vehicle_number, phone_number, rate_per_km, id_number, id_front_photo, id_back_photo)
                         VALUES (:id, :name, :vehicle, :phone, :rate, :id_number, :id_front_photo, :id_back_photo)'
                    );
                    $profileStatement->execute([
                        'id' => $applicationId,
                        'name' => $application['full_name'],
                        'vehicle' => $application['vehicle_number'],
                        'phone' => $application['phone_number'],
                        'rate' => $application['rate_per_km'],
                        'id_number' => $application['id_number'],
                        'id_front_photo' => $application['id_front_photo'],
                        'id_back_photo' => $application['id_back_photo'],
                    ]);
                    $updateStatement = $pdo->prepare(
                        "UPDATE transporter_applications SET status = 'account_created' WHERE application_id = :id"
                    );
                }

                $updateStatement->execute(['id' => $applicationId]);
                $pdo->commit();
                sign_in_user($applicationId, $role, $application['full_name']);
                log_activity($pdo, 'ACCOUNT_ACTIVATED', "Activated {$role} account ({$email}).", $applicationId, $role);
                redirect_to('index.php');
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('MFC approved account creation failed: ' . $exception->getMessage());
            $error = $exception->getCode() === '23000'
                ? 'An account already exists for this application or email.'
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
    <title>Create approved account | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <main class="auth-main">
        <section class="auth-card">
            <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
            <h1>Create your approved account</h1>
            <p>Use the application reference and email address from your approved application.</p>
            <?php if ($error !== ''): ?>
                <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
            <?php endif; ?>
            <form class="auth-form" method="post" action="activate.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="application_id">Application reference</label>
                <input id="application_id" name="application_id" type="text" minlength="30" maxlength="30" pattern="[a-fA-F0-9]{30}" required value="<?= escape_html($_POST['application_id'] ?? '') ?>">
                <label for="email">Application email address</label>
                <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= escape_html($_POST['email'] ?? '') ?>">
                <label for="password">Password (at least 10 characters)</label>
                <input id="password" name="password" type="password" minlength="10" autocomplete="new-password" required>
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required>
                <button class="form-submit" type="submit">Create account</button>
            </form>
            <p><a href="login.php">Back to sign in</a></p>
        </section>
    </main>
</body>
</html>
