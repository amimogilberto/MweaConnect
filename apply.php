<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/application_documents.php';

try {
    ensure_application_document_columns($pdo);
} catch (PDOException $exception) {
    error_log('MFC application document schema update failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The application form is temporarily unavailable because the database could not be updated.');
}

$type = (string) ($_POST['type'] ?? $_GET['type'] ?? '');
$error = '';
$applicationReference = '';
$validType = in_array($type, ['farmer', 'transporter'], true);

if (isset($_GET['submitted']) && isset($_SESSION['application_reference'])) {
    $applicationReference = (string) $_SESSION['application_reference'];
    unset($_SESSION['application_reference']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = normalize_phone((string) ($_POST['phone_number'] ?? ''));
    $idNumber = trim((string) ($_POST['id_number'] ?? ''));
    $applicationId = bin2hex(random_bytes(15));

    if (!$validType) {
        $error = 'Choose whether you are applying as a farmer or transporter.';
    } elseif ($fullName === '' || strlen($fullName) > 100) {
        $error = 'Enter your name (up to 100 characters).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!preg_match('/^\+?[0-9]{7,14}$/', $phone)) {
        $error = 'Enter a valid phone number, including country code if needed.';
    } elseif ($idNumber === '' || strlen($idNumber) > 20) {
        $error = 'Enter a valid ID number (up to 20 characters).';
    } else {
        $storedDocuments = [];
        try {
            if ($type === 'farmer') {
                $location = trim((string) ($_POST['location'] ?? ''));
                $farmSize = filter_var($_POST['farm_size'] ?? null, FILTER_VALIDATE_FLOAT);

                if ($location === '' || strlen($location) > 50 || $farmSize === false || $farmSize <= 0) {
                    $error = 'Enter your location and a farm size greater than zero.';
                } else {
                    $frontPhoto = store_id_document($_FILES['id_front_photo'] ?? []);
                    $storedDocuments[] = $frontPhoto;
                    $backPhoto = store_id_document($_FILES['id_back_photo'] ?? []);
                    $storedDocuments[] = $backPhoto;
                    $statement = $pdo->prepare(
                        'INSERT INTO farmer_applications
                            (application_id, full_name, email, phone_number, location, farm_size, id_number, id_front_photo, id_back_photo)
                         VALUES (:id, :name, :email, :phone, :location, :farm_size, :id_number, :id_front_photo, :id_back_photo)'
                    );
                    $statement->execute([
                        'id' => $applicationId,
                        'name' => $fullName,
                        'email' => $email,
                        'phone' => $phone,
                        'location' => $location,
                        'farm_size' => $farmSize,
                        'id_number' => $idNumber,
                        'id_front_photo' => $frontPhoto,
                        'id_back_photo' => $backPhoto,
                    ]);
                }
            } else {
                $vehicleNumber = strtoupper(trim((string) ($_POST['vehicle_number'] ?? '')));
                $ratePerKm = filter_var($_POST['rate_per_km'] ?? null, FILTER_VALIDATE_FLOAT);

                if ($vehicleNumber === '' || strlen($vehicleNumber) > 20 || $ratePerKm === false || $ratePerKm <= 0) {
                    $error = 'Enter your vehicle registration and a rate per kilometre greater than zero.';
                } else {
                    $frontPhoto = store_id_document($_FILES['id_front_photo'] ?? []);
                    $storedDocuments[] = $frontPhoto;
                    $backPhoto = store_id_document($_FILES['id_back_photo'] ?? []);
                    $storedDocuments[] = $backPhoto;
                    $statement = $pdo->prepare(
                        'INSERT INTO transporter_applications
                            (application_id, full_name, email, phone_number, vehicle_number, rate_per_km, id_number, id_front_photo, id_back_photo)
                         VALUES (:id, :name, :email, :phone, :vehicle, :rate, :id_number, :id_front_photo, :id_back_photo)'
                    );
                    $statement->execute([
                        'id' => $applicationId,
                        'name' => $fullName,
                        'email' => $email,
                        'phone' => $phone,
                        'vehicle' => $vehicleNumber,
                        'rate' => $ratePerKm,
                        'id_number' => $idNumber,
                        'id_front_photo' => $frontPhoto,
                        'id_back_photo' => $backPhoto,
                    ]);
                }
            }

            if ($error === '') {
                $_SESSION['application_reference'] = $applicationId;
                redirect_to('apply.php?type=' . rawurlencode($type) . '&submitted=1');
            }
        } catch (InvalidArgumentException $exception) {
            delete_private_id_documents($storedDocuments);
            $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            delete_private_id_documents($storedDocuments);
            error_log('MFC ID document storage failed: ' . $exception->getMessage());
            $error = 'We could not securely save the ID images. Please try again.';
        } catch (PDOException $exception) {
            delete_private_id_documents($storedDocuments);
            error_log('MFC application submission failed: ' . $exception->getMessage());
            $error = $exception->getCode() === '23000'
                ? 'An application with that email, phone, or vehicle registration already exists.'
                : 'We could not submit your application right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for approval | Mwea Fresh Connect</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <main class="auth-main">
        <section class="auth-card">
            <a class="auth-brand" href="index.php">Mwea Fresh Connect</a>
            <?php if ($applicationReference !== ''): ?>
                <h1>Application submitted</h1>
                <p>Your application is waiting for review. You do not have an account yet.</p>
                <p>Save this application reference. You will need it after approval:</p>
                <p class="application-reference"><?= escape_html($applicationReference) ?></p>
                <p>After an administrator approves you, <a href="activate.php">create your account here</a>.</p>
            <?php elseif (!$validType): ?>
                <h1>Apply to join</h1>
                <p>Farmers and transporters need approval before they can use their marketplace features.</p>
                <p><a href="apply.php?type=farmer">Apply as a farmer</a></p>
                <p><a href="apply.php?type=transporter">Apply as a transporter</a></p>
            <?php else: ?>
                <h1>Apply as a <?= escape_html($type) ?></h1>
                <p>An administrator will review your details before you can create an account.</p>
                <p class="upload-privacy-note">Your ID images are stored privately and can only be viewed by administrators.</p>
                <?php if ($error !== ''): ?>
                    <p class="form-message form-error" role="alert"><?= escape_html($error) ?></p>
                <?php endif; ?>
                <form class="auth-form" method="post" enctype="multipart/form-data" action="apply.php?type=<?= escape_html($type) ?>">
                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                    <input type="hidden" name="type" value="<?= escape_html($type) ?>">
                    <label for="full_name">Full name</label>
                    <input id="full_name" name="full_name" type="text" maxlength="100" autocomplete="name" required value="<?= escape_html($_POST['full_name'] ?? '') ?>">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= escape_html($_POST['email'] ?? '') ?>">
                    <label for="phone_number">Phone number</label>
                    <input id="phone_number" name="phone_number" type="tel" maxlength="20" autocomplete="tel" required value="<?= escape_html($_POST['phone_number'] ?? '') ?>">
                    <label for="id_number">National ID number</label>
                    <input id="id_number" name="id_number" type="text" maxlength="20" required>
                    <label for="id_front_photo">ID photo - front</label>
                    <input id="id_front_photo" name="id_front_photo" type="file" accept="image/jpeg,image/png,image/webp" required>
                    <small>JPEG, PNG, or WebP; maximum 5 MB per image.</small>
                    <label for="id_back_photo">ID photo - back</label>
                    <input id="id_back_photo" name="id_back_photo" type="file" accept="image/jpeg,image/png,image/webp" required>
                    <small>JPEG, PNG, or WebP; maximum 5 MB per image.</small>
                    <?php if ($type === 'farmer'): ?>
                        <label for="location">Farm location</label>
                        <input id="location" name="location" type="text" maxlength="50" required value="<?= escape_html($_POST['location'] ?? '') ?>">
                        <label for="farm_size">Farm size (acres)</label>
                        <input id="farm_size" name="farm_size" type="number" min="0.01" step="0.01" required value="<?= escape_html($_POST['farm_size'] ?? '') ?>">
                    <?php else: ?>
                        <label for="vehicle_number">Vehicle registration</label>
                        <input id="vehicle_number" name="vehicle_number" type="text" maxlength="20" required value="<?= escape_html($_POST['vehicle_number'] ?? '') ?>">
                        <label for="rate_per_km">Rate per kilometre</label>
                        <input id="rate_per_km" name="rate_per_km" type="number" min="0.01" step="0.01" required value="<?= escape_html($_POST['rate_per_km'] ?? '') ?>">
                    <?php endif; ?>
                    <button class="form-submit" type="submit">Submit application</button>
                </form>
            <?php endif; ?>
            <p><a href="login.php">Back to sign in</a></p>
        </section>
    </main>
</body>
</html>
