<?php

function ensure_recipe_image_column(mysqli $conn): void {
    $result = $conn->query("SHOW COLUMNS FROM recipes LIKE 'image_url'");
    if ($result && $result->num_rows > 0) { $result->free(); return; }
    if ($result) $result->free();
    if (!$conn->query("ALTER TABLE recipes ADD COLUMN image_url VARCHAR(2048) NULL AFTER source_url")) {
        throw new RuntimeException('Unable to prepare recipe photo storage.');
    }
}

function clean_recipe_image_url($value): string {
    $url = trim((string)$value);
    if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    $parts = parse_url($url);
    return in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) ? $url : '';
}

// Social CDN photo links are signed and expire within days, so imports from them break later. Copy the photo into the
// database when the recipe is saved and serve it from recipe_image.php instead.
function ff_is_expiring_image_url(string $url): bool {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    foreach (['cdninstagram.com', 'fbcdn.net', 'tiktokcdn.com', 'tiktokcdn-us.com', 'tiktokcdn-eu.com', 'ibyteimg.com', 'pinimg.com'] as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) return true;
    }
    return false;
}
function ff_cache_recipe_image(mysqli $conn, int $recipeId, string $url): ?string {
    if ($recipeId < 1 || !ff_is_expiring_image_url($url) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') return null;
    $bytes = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 FitFuel recipe photo',
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$bytes) { $bytes .= $chunk; return strlen($bytes) > 5000000 ? 0 : strlen($chunk); }]);
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if (!$ok || $status !== 200 || $bytes === '') return null;
    $info = @getimagesizefromstring($bytes);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
    $st = $conn->prepare('INSERT INTO recipe_image_cache(recipe_id,mime,bytes,source_url) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),bytes=VALUES(bytes),source_url=VALUES(source_url),fetched_at=CURRENT_TIMESTAMP');
    $null = null; $st->bind_param('isbs', $recipeId, $mime, $null, $url); $st->send_long_data(2, $bytes); $ok = $st->execute(); $st->close();
    if (!$ok) return null;
    $local = 'API/recipe_image.php?id=' . $recipeId;
    $st = $conn->prepare('UPDATE recipes SET image_url=? WHERE id=?'); $st->bind_param('si', $local, $recipeId); $st->execute(); $st->close();
    return $local;
}
