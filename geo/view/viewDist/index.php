<?php
// Distribuidores: zona automática de cada distribuidor (página comum em geo/zonas-pagina.php, lógica em geo/zonas.js)
require_once dirname(dirname(__DIR__)) . '/auth/auth_check.php';
naval_pagina_exigir('geo-distribuidores', '../../../');
$modo = 'distribuidores';
$base = '../../';
require dirname(dirname(__DIR__)) . '/zonas-pagina.php';
