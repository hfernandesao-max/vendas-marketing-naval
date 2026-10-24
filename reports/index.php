<?php
declare(strict_types=1);

require_once __DIR__ . '/../geo/auth/auth_check.php';

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
