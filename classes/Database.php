<?php

declare(strict_types=1);

/**
 * Central database connection factory.
 */
final class Database
{
    private const HOST = 'localhost';
    private const DB_NAME = 'pms_db';
    private const USERNAME = 'root';
    private const PASSWORD = 'password';

    private static ?PDO $connection = null;

    /**
     * Returns a shared PDO connection configured for safe error handling.
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $dsn = 'mysql:host=' . self::HOST . ';dbname=' . self::DB_NAME . ';charset=utf8mb4';
            self::$connection = new PDO($dsn, self::USERNAME, self::PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$connection;
    }

    /**
     * Prevents direct construction of the singleton.
     *
     * @return void
     */
    private function __construct()
    {
    }
}
