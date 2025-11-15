<?php
/**
 * Logging System
 * Logs to both file and database
 */

class Logger {
    private static $logLevels = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    ];

    /**
     * Log a message
     */
    private static function log(string $level, string $message, array $context = []): void {
        // Check if this level should be logged
        $currentLevelValue = self::$logLevels[LOG_LEVEL] ?? 1;
        $messageLevelValue = self::$logLevels[$level] ?? 1;

        if ($messageLevelValue < $currentLevelValue) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        // Format log message
        $logMessage = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextJson
        );

        // Log to file
        if (LOG_TO_FILE) {
            self::logToFile($logMessage, $level);
        }

        // Log to database
        if (LOG_TO_DATABASE) {
            self::logToDatabase($level, $message, $context);
        }

        // Echo to console in debug mode
        if (APP_DEBUG && php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }

    /**
     * Log to file
     */
    private static function logToFile(string $message, string $level): void {
        try {
            $logFile = LOG_DIR . '/' . date('Y-m-d') . '.log';
            file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Silent fail - don't break the application
            error_log('Failed to write to log file: ' . $e->getMessage());
        }
    }

    /**
     * Log to database
     */
    private static function logToDatabase(string $level, string $message, array $context): void {
        try {
            $db = Database::getInstance();

            // Determine category from context or backtrace
            $category = $context['category'] ?? self::determineCategory();

            // Get request info
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
            $ipAddress = self::getClientIp();

            // Get user ID from session if available
            $userId = $_SESSION['user_id'] ?? null;

            $sql = "INSERT INTO system_log
                    (log_level, category, message, context, user_id, ip_address, request_uri, request_method, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params = [
                $level,
                $category,
                $message,
                json_encode($context, JSON_UNESCAPED_UNICODE),
                $userId,
                $ipAddress,
                $requestUri,
                $requestMethod
            ];

            $db->execute($sql, $params);
        } catch (Exception $e) {
            // Silent fail - log to file instead
            error_log('Failed to log to database: ' . $e->getMessage());
        }
    }

    /**
     * Determine category from backtrace
     */
    private static function determineCategory(): string {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        if (isset($trace[2]['class'])) {
            return strtolower(str_replace(['Controller', 'Service'], '', $trace[2]['class']));
        }

        if (isset($trace[2]['file'])) {
            return basename($trace[2]['file'], '.php');
        }

        return 'general';
    }

    /**
     * Get client IP address
     */
    private static function getClientIp(): string {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
                   'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }

        return '0.0.0.0';
    }

    /**
     * Public logging methods
     */
    public static function debug(string $message, array $context = []): void {
        self::log('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void {
        self::log('critical', $message, $context);
    }

    /**
     * Log booking audit event
     */
    public static function auditBooking(
        int $bookingId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $changedFields = null,
        ?string $notes = null
    ): void {
        try {
            $db = Database::getInstance();

            $sql = "INSERT INTO booking_audit_log
                    (booking_id, action, entity_type, entity_id, old_values, new_values, changed_fields,
                     user_id, user_ip, user_agent, timestamp, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";

            $params = [
                $bookingId,
                $action,
                $entityType,
                $entityId,
                $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                $changedFields ? json_encode($changedFields, JSON_UNESCAPED_UNICODE) : null,
                $_SESSION['user_id'] ?? null,
                self::getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $notes
            ];

            $db->execute($sql, $params);

            self::info('Booking audit logged', [
                'booking_id' => $bookingId,
                'action' => $action,
                'entity_type' => $entityType
            ]);
        } catch (Exception $e) {
            self::error('Failed to log booking audit', [
                'error' => $e->getMessage(),
                'booking_id' => $bookingId
            ]);
        }
    }

    /**
     * Clean old logs (maintenance function)
     */
    public static function cleanOldLogs(int $daysToKeep = 90): int {
        try {
            $db = Database::getInstance();

            $sql = "DELETE FROM system_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $deleted = $db->execute($sql, [$daysToKeep]);

            self::info('Cleaned old system logs', ['deleted_count' => $deleted, 'days' => $daysToKeep]);

            return $deleted;
        } catch (Exception $e) {
            self::error('Failed to clean old logs', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get recent logs
     */
    public static function getRecentLogs(int $limit = 100, ?string $level = null): array {
        try {
            $db = Database::getInstance();

            $sql = "SELECT * FROM system_log";
            $params = [];

            if ($level) {
                $sql .= " WHERE log_level = ?";
                $params[] = $level;
            }

            $sql .= " ORDER BY timestamp DESC LIMIT ?";
            $params[] = $limit;

            return $db->query($sql, $params);
        } catch (Exception $e) {
            error_log('Failed to retrieve logs: ' . $e->getMessage());
            return [];
        }
    }
}
