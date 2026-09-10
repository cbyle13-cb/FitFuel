<?php
require_once 'session.php';
require_once 'oauth_lib.php';
start_fitfuel_session();

function oauth_text(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function oauth_stop(string $message, int $status = 400): void { http_response_code($status); echo '<!doctype html><meta name="viewport" content="width=device-width"><title>FitFuel connection</title><p>' . oauth_text($message) . '</p>'; exit; }

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$clientId = trim((string)($input['client_id'] ?? ''));
$redirectUri = trim((string)($input['redirect_uri'] ?? ''));
$responseType = (string)($input['response_type'] ?? '');
$challenge = trim((string)($input['code_challenge'] ?? ''));
$challengeMethod = (string)($input['code_challenge_method'] ?? '');
$resource = trim((string)($input['resource'] ?? ''));
$state = (string)($input['state'] ?? '');
try { $scope = fitfuel_validate_scope((string)($input['scope'] ?? '')); } catch (Throwable $error) { oauth_stop($error->getMessage()); }
if ($responseType !== 'code' || !fitfuel_valid_client($clientId) || $redirectUri !== FITFUEL_CHATGPT_REDIRECT || $challengeMethod !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) || $resource !== FITFUEL_MCP_RESOURCE || $state === '') oauth_stop('The ChatGPT connection request is invalid.');

$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authorize'])) {
    if ($userId < 1) {
        $email = strtolower(trim((string)($_POST['email'] ?? ''))); $password = (string)($_POST['password'] ?? '');
        $statement = $conn->prepare('SELECT id,password_hash,is_active FROM users WHERE email=? LIMIT 1');
        $statement->bind_param('s', $email); $statement->execute(); $user = $statement->get_result()->fetch_assoc(); $statement->close();
        if (!$user || !password_verify($password, $user['password_hash']) || (int)$user['is_active'] !== 1) $message = 'Email or password is incorrect.';
        else { $userId = (int)$user['id']; session_regenerate_id(true); $_SESSION['user_id'] = $userId; }
    }
    if ($userId > 0 && $message === '') {
        fitfuel_oauth_tables($conn);
        $code = fitfuel_random_token(); $hash = hash('sha256', $code);
        $statement = $conn->prepare("INSERT INTO oauth_authorization_codes(code_hash,user_id,client_id,redirect_uri,resource,scope,code_challenge,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
        $statement->bind_param('sisssss', $hash, $userId, $clientId, $redirectUri, $resource, $scope, $challenge);
        if (!$statement->execute()) oauth_stop('FitFuel could not create the connection.', 500);
        $statement->close();
        $query = http_build_query(['code'=>$code,'state'=>$state,'iss'=>FITFUEL_OAUTH_ISSUER], '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . FITFUEL_CHATGPT_REDIRECT . '?' . $query, true, 302); exit;
    }
}
$hidden = '';
foreach (['client_id'=>$clientId,'redirect_uri'=>$redirectUri,'response_type'=>$responseType,'code_challenge'=>$challenge,'code_challenge_method'=>$challengeMethod,'resource'=>$resource,'state'=>$state,'scope'=>$scope] as $name=>$value) $hidden .= '<input type="hidden" name="' . oauth_text($name) . '" value="' . oauth_text($value) . '">';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect FitFuel</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#10131a;color:#f7f8fb;margin:0}.card{max-width:480px;margin:8vh auto;background:#1b202b;padding:24px;border-radius:18px}label{display:block;margin:14px 0 5px}input{width:100%;box-sizing:border-box;padding:12px;border-radius:9px;border:1px solid #596273}button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;background:#56c271;font-weight:700}.muted{color:#b6c0cf;line-height:1.5}.error{color:#ffb4b4}</style></head><body><main class="card"><h1>Connect FitFuel</h1><p class="muted">ChatGPT is requesting permission to read your recent workout history and save your weekly strength plans.</p><?php if ($message): ?><p class="error"><?=oauth_text($message)?></p><?php endif; ?><form method="post"><?=$hidden?><?php if ($userId < 1): ?><label>Email</label><input name="email" type="email" autocomplete="email" required><label>Password</label><input name="password" type="password" autocomplete="current-password" required><?php else: ?><p>You are signed in to FitFuel.</p><?php endif; ?><button name="authorize" value="1">Allow ChatGPT</button></form><p class="muted">You can revoke this connection by removing its token from FitFuel. Your password is never sent to ChatGPT.</p></main></body></html>

