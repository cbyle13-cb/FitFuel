<?php
require_once 'session.php';
require_once 'recipe_importer.php';

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

function shortcut_token_from_request(array $in): string {
    $token = trim((string)($in['import_key'] ?? $in['import_token'] ?? ''));
    if ($token !== '') return $token;
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return trim($m[1]);
    return '';
}

function clean_string($value, int $max = 10000): string {
    $s = trim((string)$value);
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s;
}

function shortcut_list($value): array {
    if (is_array($value)) return $value;
    if (!is_string($value)) return [];
    $text = trim($value);
    if ($text === '') return [];
    if (($text[0] ?? '') === '[') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;
    }
    $lines = preg_split('/\r\n|\r|\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (count($lines) > 1) return $lines;
    if (strpos($text, ',') !== false) {
        return preg_split('/\s*,\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }
    return [$text];
}

function shortcut_nutrition_from_text(string $text): array {
    $out = [];
    $patterns = [
        'servings' => '/(?:^|\n)\s*servings?\s*:\s*(\d+(?:\.\d+)?)/i',
        'calories_per_serving' => '/(?:^|\n)\s*calories(?:\s+per\s+serving)?\s*:\s*(\d+(?:\.\d+)?)/i',
        'protein_per_serving' => '/(?:^|\n)\s*protein(?:\s+per\s+serving)?\s*:\s*(\d+(?:\.\d+)?)/i',
        'carbs_per_serving' => '/(?:^|\n)\s*(?:carbs?|carbohydrates?)(?:\s+per\s+serving)?\s*:\s*(\d+(?:\.\d+)?)/i',
        'fat_per_serving' => '/(?:^|\n)\s*fat(?:\s+per\s+serving)?\s*:\s*(\d+(?:\.\d+)?)/i',
        'fiber_per_serving' => '/(?:^|\n)\s*fiber(?:\s+per\s+serving)?\s*:\s*(\d+(?:\.\d+)?)/i',
    ];
    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $text, $m)) $out[$key] = (float)$m[1];
    }
    return $out;
}

function shortcut_is_nutrition_line(string $line): bool {
    return (bool)preg_match('/^\s*(?:nutrition(?:\s+per\s+serving)?|servings?|calories(?:\s+per\s+serving)?|protein(?:\s+per\s+serving)?|carbs?(?:\s+per\s+serving)?|carbohydrates?(?:\s+per\s+serving)?|fat(?:\s+per\s+serving)?|fiber(?:\s+per\s+serving)?)\s*:?(?:\s|$)/i', trim($line));
}

function shortcut_is_source_link_line(string $line): bool {
    $line = trim($line);
    if (preg_match('/^link\s*:\s*https?:\/\//i', $line)) return true;
    return (bool)filter_var($line, FILTER_VALIDATE_URL);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['success' => false, 'message' => 'Invalid JSON request.'], 400);
}

ensure_shortcut_tokens_table($conn);
$rawToken = shortcut_token_from_request($in);
if ($rawToken === '' || strlen($rawToken) < 32) {
    json_response(['success' => false, 'message' => 'A valid FitFuel import key is required.'], 401);
}
$tokenHash = hash('sha256', $rawToken);
$st = $conn->prepare("SELECT t.id,t.user_id,u.is_active FROM shortcut_import_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.revoked_at IS NULL LIMIT 1");
$st->bind_param('s', $tokenHash);
$st->execute();
$tokenRow = $st->get_result()->fetch_assoc();
$st->close();
if (!$tokenRow || (int)$tokenRow['is_active'] !== 1) {
    json_response(['success' => false, 'message' => 'FitFuel import key is invalid or revoked.'], 401);
}
$uid = (int)$tokenRow['user_id'];
$tokenId = (int)$tokenRow['id'];

$sharedUrl = trim((string)($in['url'] ?? $in['recipe_url'] ?? ''));
$sharedText = trim((string)($in['text'] ?? $in['caption'] ?? $in['recipe_text'] ?? ''));
$nutritionFromText = shortcut_nutrition_from_text($sharedText);

if ($sharedUrl === '' && empty($in['recipe_name']) && !empty($in['source_url'])) {
    $sharedUrl = trim((string)$in['source_url']);
}
if ($sharedUrl !== '') {
    try {
        $extracted = recipe_import_from_url($sharedUrl);
        $in = array_merge($in, $extracted);
        $in['source_url'] = $extracted['source_url'];
        $in['source_type'] = 'web';
    } catch (RecipeImportException $e) {
        if ($sharedText === '') json_response(['success' => false, 'message' => $e->getMessage()], $e->httpStatus);
        try {
            $in = array_merge($in, recipe_import_from_text($sharedText, $sharedUrl));
        } catch (RecipeImportException $textError) {
            json_response(['success' => false, 'message' => $e->getMessage() . ' ' . $textError->getMessage()], 422);
        }
    }
} elseif ($sharedText !== '' && empty($in['recipe_name'])) {
    try {
        $in = array_merge($in, recipe_import_from_text($sharedText));
    } catch (RecipeImportException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], $e->httpStatus);
    }
}

$name = clean_string($in['recipe_name'] ?? $in['title'] ?? '', 255);
if ($name === '') {
    json_response(['success' => false, 'message' => 'Recipe name is required.'], 400);
}

$ingredientsIn = shortcut_list($in['ingredients'] ?? []);
$ingredients = [];
foreach ($ingredientsIn as $ingredient) {
    $ingredient = clean_string($ingredient, 1000);
    $ingredient = preg_replace('/^[\s\-•*]+/u', '', $ingredient);
    if ($ingredient !== '') $ingredients[] = $ingredient;
}
if (!$ingredients) {
    json_response(['success' => false, 'message' => 'At least one ingredient is required.'], 400);
}
if (count($ingredients) > 250) $ingredients = array_slice($ingredients, 0, 250);
$ingredientsJson = json_encode($ingredients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$instructionsRaw = $in['instructions'] ?? $in['steps'] ?? '';
$steps = shortcut_list($instructionsRaw);
$cleanSteps = [];
foreach ($steps as $step) {
    $step = clean_string($step, 3000);
    if ($step === '' || shortcut_is_nutrition_line($step) || shortcut_is_source_link_line($step)) continue;
    $step = preg_replace('/^\s*\d+[\.)]\s*/u', '', $step);
    if ($step !== '') $cleanSteps[] = $step;
}
$numberedSteps = [];
foreach ($cleanSteps as $i => $step) {
    $numberedSteps[] = ($i + 1) . '. ' . $step;
}
$instructions = implode("\n\n", $numberedSteps);
if ($instructions === '') {
    json_response(['success' => false, 'message' => 'Recipe instructions are required.'], 400);
}

$description = clean_string($in['description'] ?? '', 5000);
$sourceUrl = clean_string($in['source_url'] ?? '', 2000);
if ($sourceUrl !== '' && !filter_var($sourceUrl, FILTER_VALIDATE_URL)) $sourceUrl = '';
$sourceType = clean_string($in['source_type'] ?? 'shortcut', 50);
$servings = isset($nutritionFromText['servings']) ? $nutritionFromText['servings'] : (float)($in['servings'] ?? 1);
if ($servings <= 0) $servings = 1;
$prep = isset($in['prep_time_minutes']) && $in['prep_time_minutes'] !== '' ? (int)$in['prep_time_minutes'] : null;
$cook = isset($in['cook_time_minutes']) && $in['cook_time_minutes'] !== '' ? (int)$in['cook_time_minutes'] : null;
$cal = isset($nutritionFromText['calories_per_serving']) ? $nutritionFromText['calories_per_serving'] : (float)($in['calories_per_serving'] ?? 0);
$pro = isset($nutritionFromText['protein_per_serving']) ? $nutritionFromText['protein_per_serving'] : (float)($in['protein_per_serving'] ?? 0);
$carbs = isset($nutritionFromText['carbs_per_serving']) ? $nutritionFromText['carbs_per_serving'] : (float)($in['carbs_per_serving'] ?? 0);
$fat = isset($nutritionFromText['fat_per_serving']) ? $nutritionFromText['fat_per_serving'] : (float)($in['fat_per_serving'] ?? 0);
$fiber = isset($nutritionFromText['fiber_per_serving']) ? $nutritionFromText['fiber_per_serving'] : (float)($in['fiber_per_serving'] ?? 0);
$fav = (int)($in['is_favorite'] ?? 0) ? 1 : 0;

$st = $conn->prepare("INSERT INTO recipes(user_id,recipe_name,description,source_url,source_type,ingredients,instructions,servings,prep_time_minutes,cook_time_minutes,calories_per_serving,protein_per_serving,carbs_per_serving,fat_per_serving,fiber_per_serving,is_favorite) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$st->bind_param('issssssdiidddddi', $uid, $name, $description, $sourceUrl, $sourceType, $ingredientsJson, $instructions, $servings, $prep, $cook, $cal, $pro, $carbs, $fat, $fiber, $fav);
if (!$st->execute()) {
    $st->close();
    json_response(['success' => false, 'message' => 'Unable to save recipe to FitFuel.'], 500);
}
$recipeId = (int)$conn->insert_id;
$st->close();

$st = $conn->prepare("UPDATE shortcut_import_tokens SET last_used_at=NOW() WHERE id=?");
$st->bind_param('i', $tokenId);
$st->execute();
$st->close();

json_response([
    'success' => true,
    'message' => $name . ' was added to FitFuel.',
    'recipe_id' => $recipeId,
    'recipe_name' => $name
]);
?>