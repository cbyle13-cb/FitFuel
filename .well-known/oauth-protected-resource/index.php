<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'resource'=>'https://fitfuel.bylerfinancial.com/API/mcp.php',
    'authorization_servers'=>['https://fitfuel.bylerfinancial.com'],
    'scopes_supported'=>['workouts:read','workouts:write'],
    'resource_documentation'=>'https://fitfuel.bylerfinancial.com/fitfuel-connector-setup.html',
], JSON_UNESCAPED_SLASHES);

