<?php
// Zonas de entrega: geofences automáticas das rotas (página comum em geo/zonas-pagina.php, lógica em geo/zonas.js)
require_once dirname(__DIR__) . '/auth/auth_check.php';
naval_pagina_exigir('geo-zonas', '../../');
$modo = 'zonas';
$base = '../';
require dirname(__DIR__) . '/zonas-pagina.php';
