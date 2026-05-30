<?php
/**
 * config/config.php
 * Configuración central del sistema
 */

define('APP_NAME',    'SistemaQR Control');
define('APP_VERSION', '1.0.0');
define('APP_ROOT',    dirname(__DIR__));
define('BASE_URL',    '');  // ej: '/attendance-system' si está en subcarpeta

// ── Zona horaria ──────────────────────────────────────────────
date_default_timezone_set('America/Lima');

// ── Base de datos ─────────────────────────────────────────────
define('DB_HOST',    getenv('DB_HOST')    ?: '192.168.0.230');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'filomena_100');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

// ── Horas de comedor (valores por defecto, sobreescribibles desde BD) ──
define('HORA_DESAYUNO_INI', '06:00');
define('HORA_DESAYUNO_FIN', '10:59');
define('HORA_ALMUERZO_INI', '12:00');
define('HORA_ALMUERZO_FIN', '15:59');
define('HORA_CENA_INI',     '16:00');
define('HORA_CENA_FIN',     '23:59');

// ── Jornada laboral ───────────────────────────────────────────
define('HORAS_PROGRAMADAS_DEFAULT', 11);

// ── Rutas ─────────────────────────────────────────────────────
define('VIEWS_PATH',   APP_ROOT . '/views');
define('EXPORTS_PATH', APP_ROOT . '/exports');
?>