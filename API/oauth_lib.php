<?php

const FITFUEL_OAUTH_ISSUER = 'https://fitfuel.bylerfinancial.com';
const FITFUEL_MCP_RESOURCE = 'https://fitfuel.bylerfinancial.com/API/mcp.php';
const FITFUEL_CHATGPT_REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';
const FITFUEL_OAUTH_SCOPES = 'workouts:read workouts:write';

function fitfuel_oauth_tables(mysqli $conn): void {
    $codes = "CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code_hash CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        client_id VARCHAR(512) NOT NULL,
        redirect_uri VARCHAR(512) NOT NULL,
        resource VARCHAR(512) NOT NULL,
        scope VARCHAR(255) NOT NULL,
        code_challenge VARCHAR(128) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_oauth_code_hash (code_hash), KEY idx_oauth_code_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $tokens = "CREATE TABLE IF NOT EXISTS oauth_access_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        access_token_hash CHAR(64) NOT NULL,
        refresh_token_hash CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        scope VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        refresh_expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_oauth_access_hash (access_token_hash), UNIQUE KEY uq_oauth_refresh_hash (refresh_token_hash), KEY idx_oauth_token_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($codes) || !$conn->query($tokens)) throw new RuntimeException('Unable to initialize secure connection storage.');
}

function fitfuel_base64url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function fitfuel_random_token(): string {
    return fitfuel_base64url(random_bytes(32));
}

function fitfuel_valid_client(string $clientId): bool {
    return $clientId === 'https://chatgpt.com/oauth/client.json' || (bool)preg_match('#^https://chatgpt\.com/oauth/[A-Za-z0-9_-]+/client\.json$#', $clientId);
}

function fitfuel_validate_scope(string $scope): string {
    $requested = array_values(array_unique(array_filter(preg_split('/\s+/', trim($scope)))));
    if (!$requested) $requested = explode(' ', FITFUEL_OAUTH_SCOPES);
    $allowed = explode(' ', FITFUEL_OAUTH_SCOPES);
    foreach ($requested as $item) if (!in_array($item, $allowed, true)) throw new InvalidArgumentException('Unsupported authorization scope.');
    return implode(' ', $requested);
}

function fitfuel_bearer_token(): string {
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) return trim($match[1]);
    return '';
}

function fitfuel_oauth_challenge(): void {
    $metadata = FITFUEL_OAUTH_ISSUER . '/.well-known/oauth-protected-resource/';
    header('WWW-Authenticate: Bearer resource_metadata="' . $metadata . '", scope="' . FITFUEL_OAUTH_SCOPES . '"');
    http_response_code(401);
}

function fitfuel_oauth_user(mysqli $conn, string $requiredScope): int {
    fitfuel_oauth_tables($conn);
    $token = fitfuel_bearer_token();
    if ($token === '') { fitfuel_oauth_challenge(); throw new DomainException('Connect FitFuel to continue.', 401); }
    $hash = hash('sha256', $token);
    $statement = $conn->prepare("SELECT id,user_id,scope FROM oauth_access_tokens WHERE access_token_hash=? AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1");
    $statement->bind_param('s', $hash);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$row || !in_array($requiredScope, preg_split('/\s+/', $row['scope']), true)) { fitfuel_oauth_challenge(); throw new DomainException('FitFuel authorization is invalid, expired, or missing permission.', 401); }
    $id = (int)$row['id'];
    $statement = $conn->prepare('UPDATE oauth_access_tokens SET last_used_at=NOW() WHERE id=?');
    $statement->bind_param('i', $id);
    $statement->execute();
    $statement->close();
    return (int)$row['user_id'];
}

function fitfuel_token_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

