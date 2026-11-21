<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$dbHost = getenv('MFC_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('MFC_DB_NAME') ?: 'mfc_system';
$dbUser = getenv('MFC_DB_USER') ?: 'root';
$dbPassword = getenv('MFC_DB_PASS');
if ($dbPassword === false) {
    $dbPassword = '';
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('MFC database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The database is unavailable. Start MySQL in XAMPP and check MFC_DB_HOST, MFC_DB_NAME, MFC_DB_USER, and MFC_DB_PASS.');
}

function escape_html($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(400);
        exit('The form expired. Reload the page and try again.');
    }
}

function sign_in_user(string $userId, string $role, string $name): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $name;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function require_role(string $role): void
{
    if (empty($_SESSION['user_id'])) {
        redirect_to('login.php');
    }

    if (($_SESSION['user_role'] ?? '') !== $role) {
        http_response_code(403);
        exit('You are not allowed to use this feature.');
    }
}

function normalize_phone(string $phone): string
{
    return preg_replace('/[\s()-]/', '', trim($phone)) ?? '';
}


// Add to the bottom of bootstrap.php

function log_activity(PDO $pdo, string $actionType, string $description, ?string $userId = null, ?string $role = null): void
{
    try {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $urole = $role ?? ($_SESSION['user_role'] ?? 'guest');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, role, action_type, description, ip_address)
             VALUES (:uid, :role, :action, :desc, :ip)'
        );
        $stmt->execute([
            'uid' => $uid,
            'role' => $urole,
            'action' => $actionType,
            'desc' => $description,
            'ip' => $ip,
        ]);
    } catch (PDOException $e) {
        error_log('MFC activity log failed: ' . $e->getMessage());
    }
}

function get_user_rating(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT ROUND(AVG(stars), 1) AS avg_stars, COUNT(*) AS total FROM user_ratings WHERE ratee_id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    return [
        'avg' => $row['avg_stars'] ? (float) $row['avg_stars'] : 0.0,
        'count' => (int) ($row['total'] ?? 0),
    ];
}