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

$projects = $projectRepository->listAll();
if (empty($projects)) {
    $projectRepository->create('Default Project', 'default');
}

return new MainState(
    $rootDir,
    $mainDatabase,
    $projectRepository,
    $authUser,
    $authPass
);
