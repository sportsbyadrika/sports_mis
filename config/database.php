<?php
require_once __DIR__ . '/config.php';

/**
 * Returns a shared MySQLi connection instance.
 */
function get_db_connection(): mysqli
{
    static $connection = null;

    if ($connection === null) {
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($connection->connect_errno) {
            throw new RuntimeException('Failed to connect to MySQL: ' . $connection->connect_error);
        }
        $connection->set_charset('utf8mb4');
    }

    return $connection;
}
