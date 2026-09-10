<?php
require_once 'session.php';
require_once 'oauth_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fitfuel_token_json(['error'=>'invalid_request'], 405);
fitfuel_oauth_tables($conn);
$grant = (string)($_POST['grant_type'] ?? '');
$inTransaction = false;
try {
    if ($grant === 'authorization_code') {
        $code = (string)($_POST['code'] ?? ''); $verifier = (string)($_POST['code_verifier'] ?? '');
        $clientId = (string)($_POST['client_id'] ?? ''); $redirectUri = (string)($_POST['redirect_uri'] ?? ''); $resource = (string)($_POST['resource'] ?? '');
        if (!fitfuel_valid_client($clientId) || $redirectUri !== FITFUEL_CHATGPT_REDIRECT || $resource !== FITFUEL_MCP_RESOURCE || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) throw new InvalidArgumentException('invalid_grant');
        $hash = hash('sha256', $code);
        $conn->begin_transaction(); $inTransaction = true;
        $statement = $conn->prepare('SELECT * FROM oauth_authorization_codes WHERE code_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1 FOR UPDATE');
        $statement->bind_param('s', $hash); $statement->execute(); $row = $statement->get_result()->fetch_assoc(); $statement->close();
        if (!$row || !hash_equals($row['client_id'], $clientId) || !hash_equals($row['redirect_uri'], $redirectUri) || !hash_equals($row['resource'], $resource) || !hash_equals($row['code_challenge'], fitfuel_base64url(hash('sha256', $verifier, true)))) throw new InvalidArgumentException('invalid_grant');
        $codeId = (int)$row['id']; $statement = $conn->prepare('UPDATE oauth_authorization_codes SET used_at=NOW() WHERE id=?'); $statement->bind_param('i', $codeId); $statement->execute(); $statement->close();
        $access = fitfuel_random_token(); $refresh = fitfuel_random_token(); $accessHash = hash('sha256', $access); $refreshHash = hash('sha256', $refresh); $userId = (int)$row['user_id']; $scope = $row['scope'];
        $statement = $conn->prepare("INSERT INTO oauth_access_tokens(access_token_hash,refresh_token_hash,user_id,scope,expires_at,refresh_expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 365 DAY))");
        $statement->bind_param('ssis', $accessHash, $refreshHash, $userId, $scope); $statement->execute(); $statement->close(); $conn->commit(); $inTransaction = false;
        fitfuel_token_json(['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>3600,'refresh_token'=>$refresh,'scope'=>$scope]);
    }
    if ($grant === 'refresh_token') {
        $refresh = (string)($_POST['refresh_token'] ?? ''); $clientId = (string)($_POST['client_id'] ?? ''); $resource = (string)($_POST['resource'] ?? '');
        if (!fitfuel_valid_client($clientId) || $resource !== FITFUEL_MCP_RESOURCE) throw new InvalidArgumentException('invalid_grant');
        $refreshHash = hash('sha256', $refresh); $statement = $conn->prepare('SELECT id,scope FROM oauth_access_tokens WHERE refresh_token_hash=? AND revoked_at IS NULL AND refresh_expires_at>NOW() LIMIT 1');
        $statement->bind_param('s', $refreshHash); $statement->execute(); $row = $statement->get_result()->fetch_assoc(); $statement->close();
        if (!$row) throw new InvalidArgumentException('invalid_grant');
        $access = fitfuel_random_token(); $accessHash = hash('sha256', $access); $id = (int)$row['id'];
        $statement = $conn->prepare("UPDATE oauth_access_tokens SET access_token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL 1 HOUR),last_used_at=NOW() WHERE id=?"); $statement->bind_param('si', $accessHash, $id); $statement->execute(); $statement->close();
        fitfuel_token_json(['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>3600,'refresh_token'=>$refresh,'scope'=>$row['scope']]);
    }
    fitfuel_token_json(['error'=>'unsupported_grant_type'], 400);
} catch (InvalidArgumentException $error) { if ($inTransaction) $conn->rollback(); fitfuel_token_json(['error'=>$error->getMessage()], 400); }
catch (Throwable $error) { if ($inTransaction) $conn->rollback(); fitfuel_token_json(['error'=>'server_error'], 500); }
