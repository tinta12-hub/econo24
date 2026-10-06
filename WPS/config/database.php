<?php
/**
 * 24/7 - 24-Hour Business Operations Management System
 * Database Configuration & PDO Singleton Connection
 */

declare(strict_types=1);

class Database {
    private static ?PDO $instance = null;
    
    private const DB_HOST = 'localhost';
    private const DB_PORT = '3306';
    private const DB_NAME = '24hr';
    private const DB_USER = 'root';
    private const DB_PASS = '';
    private const DB_CHARSET = 'utf8mb4';

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                self::DB_HOST,
                self::DB_PORT,
                self::DB_NAME,
                self::DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
            } catch (PDOException $e) {
                // If database doesn't exist yet, attempt auto-create
                if ($e->getCode() === 1049) {
                    self::createDatabaseIfNotExists();
                    self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
                } else {
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Database connection failed: ' . $e->getMessage()
                    ]);
                    exit;
                }
            }
        }

        return self::$instance;
    }

    private static function createDatabaseIfNotExists(): void {
        $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', self::DB_HOST, self::DB_PORT, self::DB_CHARSET);
        $rootPdo = new PDO($rootDsn, self::DB_USER, self::DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . self::DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
