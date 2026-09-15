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
