<?php
/**
 * Database Connection Manager
 * Handles PDO connections to main and Integram databases
 */

class Database {
    private static $instance = null;
    private $connection = null;
    private $integrамConnection = null;

    private function __construct() {
        // Private constructor to prevent direct instantiation
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get main database connection
     */
    public function getConnection(): PDO {
        if ($this->connection === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    DB_HOST,
                    DB_NAME,
                    DB_CHARSET
                );

                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                ];

                $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);

                Logger::info('Database connection established', ['database' => DB_NAME]);
            } catch (PDOException $e) {
                Logger::critical('Database connection failed', [
                    'error' => $e->getMessage(),
                    'database' => DB_NAME
                ]);
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }

        return $this->connection;
    }

    /**
     * Get Integram database connection
     */
    public function getIntegrамConnection(): ?PDO {
        if (!INTEGRAM_SYNC_ENABLED) {
            return null;
        }

        if ($this->integrамConnection === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    INTEGRAM_DB_HOST,
                    INTEGRAM_DB_NAME,
                    DB_CHARSET
                );

                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];

                $this->integrамConnection = new PDO($dsn, INTEGRAM_DB_USER, INTEGRAM_DB_PASS, $options);

                Logger::info('Integram database connection established');
            } catch (PDOException $e) {
                Logger::error('Integram database connection failed', ['error' => $e->getMessage()]);
                // Don't throw - allow system to work without Integram
                return null;
            }
        }

        return $this->integrамConnection;
    }

    /**
     * Execute a SELECT query
     */
    public function query(string $sql, array $params = []): array {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            Logger::error('Query failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Execute INSERT, UPDATE, DELETE
     */
    public function execute(string $sql, array $params = []): int {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('Execute failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId(): string {
        return $this->getConnection()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool {
        return $this->getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool {
        return $this->getConnection()->rollBack();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool {
        return $this->getConnection()->inTransaction();
    }

    /**
     * Close connections
     */
    public function close(): void {
        $this->connection = null;
        $this->integrамConnection = null;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
