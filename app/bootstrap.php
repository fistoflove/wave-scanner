<?php

declare(strict_types=1);

require_once __DIR__ . '/services.php';

$rootDir = dirname(__DIR__);

$mainDatabase = new MainDatabase();
$mainPdo = $mainDatabase->connection();
$projectRepository = new ProjectRepository($mainPdo);

$authUser = null;
$authPass = null;
$authConfigPath = dirname(__DIR__, 2) . '/auth.json';
if (!file_exists($authConfigPath)) {
    $authConfigPath = dirname(__DIR__) . '/auth.json';
}
if (file_exists($authConfigPath)) {
    $authRaw = file_get_contents($authConfigPath);
    if ($authRaw !== false) {
        $authData = json_decode($authRaw, true);
        if (is_array($authData)) {
            $authUser = isset($authData['username']) ? (string)$authData['username'] : null;
            $authPass = isset($authData['password']) ? (string)$authData['password'] : null;
        }
    }
}
$authUser = getenv('APP_USER') ?: ($authUser ?: 'admin');
$authPass = getenv('APP_PASS') ?: ($authPass ?: 'amada');

try {
    $config = MySqlPool::config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'] ?? '127.0.0.1',
        $config['port'] ?? 3306,
        $config['database'] ?? '',
        $config['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['user'] ?? 'root', $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO projects (name, slug) VALUES (?, ?)');
        $stmt->execute(['Default Project', 'default']);
    }
} catch (Throwable $e) {
    error_log('Bootstrap seed failed: ' . $e->getMessage());
}

return new MainState(
    $rootDir,
    $mainDatabase,
    $projectRepository,
    $authUser,
    $authPass
);
