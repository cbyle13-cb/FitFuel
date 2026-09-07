<?php
require_once 'session.php';
$uid = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'POST required.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$url = trim((string)($input['url'] ?? ''));

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_response(['success' => false, 'message' => 'Enter a valid recipe URL.'], 400);
}

$parts = parse_url($url);
$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = strtolower((string)($parts['host'] ?? ''));
if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    json_response(['success' => false, 'message' => 'Only http/https recipe URLs are supported.'], 400);
}

// Basic SSRF protection: reject local/private hosts and private IP destinations.
if ($host === 'localhost' || str_ends_with($host, '.local')) {
    json_response(['success' => false, 'message' => 'That host is not allowed.'], 400);
}
$ips = @gethostbynamel($host) ?: [];
foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        json_response(['success' => false, 'message' => 'That host is not allowed.'], 400);
    }
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 4,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => 'FitFuel Recipe Importer/1.0 (+https://fitfuel.bylerfinancial.com)',
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    CURLOPT_ENCODING => '',
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
]);
$html = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
curl_close($ch);

if ($html === false || $status < 200 || $status >= 400) {
    json_response(['success' => false, 'message' => 'FitFuel could not load that webpage.' . ($error ? ' ' . $error : '')], 422);
}
if ($ctype && stripos($ctype, 'text/html') === false && stripos($ctype, 'application/xhtml') === false) {
    json_response(['success' => false, 'message' => 'The shared URL is not an HTML webpage.'], 422);
}
if (strlen($html) > 5000000) {
    json_response(['success' => false, 'message' => 'That webpage is too large to import safely.'], 413);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
$xpath = new DOMXPath($dom);

function type_has_recipe($type): bool {
    if (is_string($type)) return strtolower($type) === 'recipe' || str_ends_with(strtolower($type), '/recipe');
    if (is_array($type)) {
        foreach ($type as $t) if (type_has_recipe($t)) return true;
    }
    return false;
}

function find_recipe_node($node) {
    if (is_array($node)) {
        if (isset($node['@type']) && type_has_recipe($node['@type'])) return $node;
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                $found = find_recipe_node($child);
                if ($found) return $found;
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = find_recipe_node($child);
                if ($found) return $found;
            }
        }
    }
    return null;
}

function instruction_text($value): string {
    if (is_string($value)) return trim(strip_tags($value));
    if (!is_array($value)) return '';
    $lines = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $t = trim(strip_tags($item));
            if ($t !== '') $lines[] = $t;
        } elseif (is_array($item)) {
            if (isset($item['text'])) {
                $t = trim(strip_tags((string)$item['text']));
                if ($t !== '') $lines[] = $t;
            } elseif (isset($item['itemListElement'])) {
                $t = instruction_text($item['itemListElement']);
                if ($t !== '') $lines[] = $t;
            }
        }
    }
    return implode("\n", $lines);
}

function iso_minutes($value): ?int {
    if (!is_string($value) || $value === '') return null;
    try {
        $i = new DateInterval($value);
        return ($i->d * 1440) + ($i->h * 60) + $i->i;
    } catch (Throwable $e) {
        return null;
    }
}

function numeric_value($value): float {
    if ($value === null || $value === '') return 0;
    if (is_numeric($value)) return (float)$value;
    if (preg_match('/-?\d+(?:\.\d+)?/', (string)$value, $m)) return (float)$m[0];
    return 0;
}

$recipe = null;
foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
    $raw = trim($script->textContent);
    if ($raw === '') continue;
    $decoded = json_decode($raw, true);
    if ($decoded === null) continue;
    $recipe = find_recipe_node($decoded);
    if ($recipe) break;
}

if (!$recipe) {
    json_response([
        'success' => false,
        'message' => 'FitFuel could not find structured recipe data on this page. You can still copy the ingredients and instructions into FitFuel manually.'
    ], 422);
}

$name = trim((string)($recipe['name'] ?? ''));
$description = trim(strip_tags((string)($recipe['description'] ?? '')));
$ingredients = [];
foreach (($recipe['recipeIngredient'] ?? []) as $ingredient) {
    $ingredient = trim(strip_tags((string)$ingredient));
    if ($ingredient !== '') $ingredients[] = $ingredient;
}
$instructions = instruction_text($recipe['recipeInstructions'] ?? []);
$servings = numeric_value($recipe['recipeYield'] ?? 1);
if ($servings <= 0) $servings = 1;
$nutrition = is_array($recipe['nutrition'] ?? null) ? $recipe['nutrition'] : [];

$image = '';
$img = $recipe['image'] ?? '';
if (is_string($img)) $image = $img;
elseif (is_array($img)) {
    if (isset($img['url'])) $image = (string)$img['url'];
    elseif (isset($img[0])) {
        if (is_string($img[0])) $image = $img[0];
        elseif (is_array($img[0]) && isset($img[0]['url'])) $image = (string)$img[0]['url'];
    }
}

json_response([
    'success' => true,
    'recipe' => [
        'recipe_name' => $name ?: 'Imported Recipe',
        'description' => $description,
        'source_url' => $url,
        'source_type' => 'web',
        'ingredients' => $ingredients,
        'instructions' => $instructions,
        'servings' => $servings,
        'prep_time_minutes' => iso_minutes($recipe['prepTime'] ?? null),
        'cook_time_minutes' => iso_minutes($recipe['cookTime'] ?? null),
        'calories_per_serving' => numeric_value($nutrition['calories'] ?? 0),
        'protein_per_serving' => numeric_value($nutrition['proteinContent'] ?? 0),
        'carbs_per_serving' => numeric_value($nutrition['carbohydrateContent'] ?? 0),
        'fat_per_serving' => numeric_value($nutrition['fatContent'] ?? 0),
        'fiber_per_serving' => numeric_value($nutrition['fiberContent'] ?? 0),
        'image_url' => $image
    ]
]);
