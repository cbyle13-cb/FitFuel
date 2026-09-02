<?php

$configFile = dirname(__DIR__, 2) . '/fitfuel_private.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    die('Server configuration missing.');
}

$config = require $configFile;

$conn = new mysqli(
    $config['db_host'],
    $config['db_user'],
    $config['db_password'],
    $config['db_name']
);

if ($conn->connect_error) {
    http_response_code(500);
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
