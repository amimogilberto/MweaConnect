<?php
function ensure_application_document_columns(PDO $pdo): void
{
    $tables = ['farmer_applications', 'transporter_applications'];
    foreach ($tables as $table) {
        foreach (['id_front_photo', 'id_back_photo'] as $column) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            if ((int) $statement->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} VARCHAR(100) NULL");
            }
        }
    }
}

function private_id_document_directory(): string
{
    $configuredDirectory = getenv('MFC_PRIVATE_UPLOAD_DIR');
    if ($configuredDirectory !== false && trim($configuredDirectory) !== '') {
        return $configuredDirectory;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mfc-private-uploads';
}

function store_id_document(array $upload): string
{
    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new InvalidArgumentException('Each ID image must be 5 MB or smaller.');
        }
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Upload both the front and back of your ID.');
        }
        if (in_array($uploadError, [UPLOAD_ERR_PARTIAL], true)) {
            throw new InvalidArgumentException('An ID image did not finish uploading. Please try again.');
        }

        throw new RuntimeException('An ID image could not be uploaded.');
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    $fileSize = (int) ($upload['size'] ?? 0);
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Each ID image must be a valid image no larger than 5 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($temporaryPath);
    if (
        !is_string($mimeType)
        || !isset($extensions[$mimeType])
        || $imageInfo === false
        || ($imageInfo['mime'] ?? '') !== $mimeType
        || $imageInfo[0] > 10000
        || $imageInfo[1] > 10000
    ) {
        throw new InvalidArgumentException('Upload an ID photo as a JPEG, PNG, or WebP image.');
    }

    $directory = private_id_document_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private ID image directory could not be created.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('The ID image could not be stored securely.');
    }

    return $fileName;
}

function delete_private_id_documents(array $fileNames): void
{
    foreach ($fileNames as $fileName) {
        if (is_string($fileName) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $fileName)) {
            $path = private_id_document_directory() . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path) && !unlink($path)) {
                error_log('MFC could not remove an unused private ID image.');
            }
        }
    }
}

function private_id_document_path(string $fileName): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $fileName)) {
        return null;
    }

    $path = private_id_document_directory() . DIRECTORY_SEPARATOR . $fileName;
    return is_file($path) ? $path : null;
}
