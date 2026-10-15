<?php 
function handleChatImageUpload(string $fieldName = 'attachment'): ?array
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error (code ' . $file['error'] . ')');
    }

    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        throw new Exception('Image too large (max 5MB)');
    }

    // Validate the actual file content's MIME type, never trust the
    // client-supplied extension or Content-Type header.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG, GIF, or WEBP images are allowed');
    }

    $ext = $allowed[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    // __DIR__ = .../includes, go up one level to project root, then into uploads/chat
    $destDir = __DIR__ . '/../uploads/chat/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
        throw new Exception('Failed to save uploaded image');
    }

    return ['path' => 'uploads/chat/' . $filename, 'type' => 'image'];
}