<?php
require __DIR__ . '/bootstrap.php';

if (empty($_SESSION['user_id'])) {
    redirect_to('login.php');
}

$role = (string) ($_SESSION['user_role'] ?? '');
$routes = [
    'admin'       => 'admin_dashboard.php',
    'farmer'      => 'farmer_dashboard.php',
    'transporter' => 'transporter_dashboard.php',
    'buyer'       => 'buyer_dashboard.php',
];

if (!isset($routes[$role])) {
    http_response_code(403);
    exit('Your account role is not recognized.');
}

redirect_to($routes[$role]);
