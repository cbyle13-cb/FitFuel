<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'issuer'=>'https://fitfuel.bylerfinancial.com',
    'authorization_response_iss_parameter_supported'=>true,
    'authorization_endpoint'=>'https://fitfuel.bylerfinancial.com/API/oauth_authorize.php',
    'token_endpoint'=>'https://fitfuel.bylerfinancial.com/API/oauth_token.php',
    'client_id_metadata_document_supported'=>true,
    'token_endpoint_auth_methods_supported'=>['none'],
    'code_challenge_methods_supported'=>['S256'],
    'grant_types_supported'=>['authorization_code','refresh_token'],
    'response_types_supported'=>['code'],
    'scopes_supported'=>['workouts:read','workouts:write'],
], JSON_UNESCAPED_SLASHES);

