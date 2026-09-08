<?php
require_once 'session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'POST requests only.'], 405);
}

function ensure_shortcut_tokens_table(mysqli $conn): void {
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
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 400);
}

$email = strtolower(trim((string)($in['email'] ?? '')));
$password = (string)($in['password'] ?? '');
$name = trim((string)($in['token_name'] ?? 'iPhone Shortcut'));
if ($name === '') $name = 'iPhone Shortcut';
if (strlen($name) > 100) $name = substr($name, 0, 100);

if ($email === '' || $password === '') {
    json_response(['success' => false, 'message' => 'Email and password are required.'], 400);
}

$st = $conn->prepare("SELECT id,password_hash,is_active FROM users WHERE email=? LIMIT 1");
$st->bind_param('s', $email);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_response(['success' => false, 'message' => 'Invalid email or password.'], 401);
}
if ((int)$user['is_active'] !== 1) {
    json_response(['success' => false, 'message' => 'This account is inactive.'], 403);
}

ensure_shortcut_tokens_table($conn);

$uid = (int)$user['id'];
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