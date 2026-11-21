<?php
require __DIR__ . '/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/application_documents.php';

$type = (string) ($_GET['type'] ?? '');
$applicationId = (string) ($_GET['application_id'] ?? '');
$side = (string) ($_GET['side'] ?? '');
$applicationTables = [
    'farmer' => 'farmer_applications',
    'transporter' => 'transporter_applications',
];
$photoColumns = [
    'front' => 'id_front_photo',
    'back' => 'id_back_photo',
];

if (
    !isset($applicationTables[$type])
    || !isset($photoColumns[$side])
    || !preg_match('/^[a-f0-9]{30}$/', $applicationId)
) {
    http_response_code(404);
    exit('ID image not found.');
}

try {
    ensure_application_document_columns($pdo);
    $table = $applicationTables[$type];
    $photoColumn = $photoColumns[$side];
    $statement = $pdo->prepare(
        "SELECT {$photoColumn} FROM {$table} WHERE application_id = :id LIMIT 1"
    );
    $statement->execute(['id' => $applicationId]);
    $fileName = $statement->fetchColumn();
} catch (PDOException $exception) {
    error_log('MFC admin ID image lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The ID image could not be loaded.');
}

$path = is_string($fileName) ? private_id_document_path($fileName) : null;
if ($path === null) {
    http_response_code(404);
    exit('ID image not found.');
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit('ID image not found.');
}

$extension = $mimeType === 'image/jpeg' ? 'jpg' : substr($mimeType, 6);
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="id-' . $side . '.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
