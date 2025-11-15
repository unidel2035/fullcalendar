<?php
/**
 * Configuration file for Booking System
 * Daily Rental Management System
 */

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'booking_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Integram Database Configuration
define('INTEGRAM_DB_HOST', getenv('INTEGRAM_DB_HOST') ?: 'localhost');
define('INTEGRAM_DB_NAME', getenv('INTEGRAM_DB_NAME') ?: 'integram');
define('INTEGRAM_DB_USER', getenv('INTEGRAM_DB_USER') ?: 'root');
define('INTEGRAM_DB_PASS', getenv('INTEGRAM_DB_PASS') ?: '');

// Application Settings
define('APP_TIMEZONE', 'Europe/Moscow');
define('APP_LOCALE', 'ru_RU');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);

// Session Settings
define('SESSION_LIFETIME', 3600 * 24); // 24 hours
define('SESSION_NAME', 'BOOKING_SESSION');

// API Settings
define('API_VERSION', 'v1');
define('API_BASE_PATH', '/api/' . API_VERSION);

// File Upload Settings
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_ALLOWED_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);
define('UPLOAD_DIR', __DIR__ . '/../uploads');

// Logging Settings
define('LOG_DIR', __DIR__ . '/../logs');
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'info'); // debug, info, warning, error, critical
define('LOG_TO_FILE', true);
define('LOG_TO_DATABASE', true);

// Security Settings
define('BCRYPT_COST', 12);
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'CHANGE_THIS_SECRET_KEY_IN_PRODUCTION');
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRATION', 3600 * 24); // 24 hours

// CORS Settings
define('CORS_ALLOWED_ORIGINS', explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '*'));
define('CORS_ALLOWED_METHODS', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
define('CORS_ALLOWED_HEADERS', ['Content-Type', 'Authorization', 'X-Requested-With']);

// Pagination
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// Booking Rules Defaults
define('DEFAULT_MIN_STAY_NIGHTS', 1);
define('DEFAULT_MAX_STAY_NIGHTS', 30);
define('DEFAULT_MAX_ADVANCE_DAYS', 365);
define('DEFAULT_MIN_ADVANCE_HOURS', 2);

// Currency
define('DEFAULT_CURRENCY', 'RUB');
define('CURRENCY_SYMBOL', '₽');

// Telegram Bot (optional)
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_ENABLED', !empty(TELEGRAM_BOT_TOKEN));

// Integram API Settings
define('INTEGRAM_API_URL', getenv('INTEGRAM_API_URL') ?: 'https://dev.drondoc.ru/integram');
define('INTEGRAM_API_TOKEN', getenv('INTEGRAM_API_TOKEN') ?: '');
define('INTEGRAM_SYNC_ENABLED', filter_var(getenv('INTEGRAM_SYNC_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);

// Error Reporting
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// Timezone
date_default_timezone_set(APP_TIMEZONE);

// Create required directories
$directories = [LOG_DIR, UPLOAD_DIR];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
