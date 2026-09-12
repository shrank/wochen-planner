<?php
declare(strict_types=1);

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $config = [];
    $configPath = __DIR__ . '/config.php';
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    $host    = ($config['host']    ?? getenv('DB_HOST')) ?: 'localhost';
    $dbname  = ($config['dbname']  ?? getenv('DB_NAME')) ?: 'wochenplaner';
    $user    = ($config['user']    ?? getenv('DB_USER')) ?: 'db_user';
    $pass    = ($config['pass']    ?? getenv('DB_PASS')) ?: 'db_password';
    $charset = $config['charset'] ?? 'utf8mb4';

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $host,
        $dbname,
        $charset
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
