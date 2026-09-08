<?php
require_once 'session.php';
$uid = require_login();
current_user($conn, $uid);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'POST requests only.'], 405);
}

$sql = "CREATE TABLE IF NOT EXISTS shortcut_import_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_name VARCHAR(100) NOT NULL DEFAULT 'iPhone Shortcut',
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shortcut_token_hash (token_hash),
    KEY idx_shortcut_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($sql)) {
    json_response(['success' => false, 'message' => 'Unable to initialize Shortcut access.'], 500);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$name = trim((string)($in['token_name'] ?? 'iPhone Shortcut'));
if ($name === '') $name = 'iPhone Shortcut';
if (strlen($name) > 100) $name = substr($name, 0, 100);

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$st = $conn->prepare("INSERT INTO shortcut_import_tokens(user_id,token_hash,token_name) VALUES(?,?,?)");
$st->bind_param('iss', $uid, $tokenHash, $name);
if (!$st->execute()) {
    $st->close();
    json_response(['success' => false, 'message' => 'Unable to create Shortcut access key.'], 500);
}
$st->close();

json_response([
    'success' => true,
    'message' => 'Shortcut access key created.',
    'import_token' => $rawToken
]);
?>