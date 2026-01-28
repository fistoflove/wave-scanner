<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/services.php';

use PHAPI\PHAPI;

$config = [];
$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

$getConfig = function (string $key, mixed $default = null) use ($config): mixed {
    return array_key_exists($key, $config) ? $config[$key] : $default;
};

$debug = $getConfig('APP_DEBUG', getenv('APP_DEBUG') === '1');
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/storage';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
ini_set('error_log', $logDir . '/php-error.log');

MySqlPool::configure([
    'host' => $getConfig('MYSQL_HOST', getenv('MYSQL_HOST') ?: '127.0.0.1'),
    'port' => (int)$getConfig('MYSQL_PORT', getenv('MYSQL_PORT') ?: 3306),
    'user' => $getConfig('MYSQL_USER', getenv('MYSQL_USER') ?: 'root'),
    'password' => $getConfig('MYSQL_PASSWORD', getenv('MYSQL_PASSWORD') ?: ''),
    'database' => $getConfig('MYSQL_DATABASE', getenv('MYSQL_DATABASE') ?: ''),
    'charset' => $getConfig('MYSQL_CHARSET', getenv('MYSQL_CHARSET') ?: 'utf8mb4'),
    'timeout' => (float)$getConfig('MYSQL_TIMEOUT', getenv('MYSQL_TIMEOUT') ?: 1.0),
]);
RedisPool::configure([
    'host' => $getConfig('REDIS_HOST', getenv('REDIS_HOST') ?: ''),
    'port' => (int)$getConfig('REDIS_PORT', getenv('REDIS_PORT') ?: 6379),
    'auth' => $getConfig('REDIS_AUTH', getenv('REDIS_AUTH') ?: null),
    'db' => $getConfig('REDIS_DB', getenv('REDIS_DB') !== false ? getenv('REDIS_DB') : null),
    'timeout' => (float)$getConfig('REDIS_TIMEOUT', getenv('REDIS_TIMEOUT') ?: 1.0),
]);

$state = require __DIR__ . '/app/bootstrap.php';

$runtime = $getConfig('APP_RUNTIME', getenv('APP_RUNTIME') ?: getenv('PHP_RUNTIME'));
if (!$runtime) {
    $runtime = extension_loaded('swoole') ? 'swoole' : 'fpm';
}

$api = new PHAPI([
    'runtime' => $runtime,
    'host' => $getConfig('APP_HOST', getenv('APP_HOST') ?: '0.0.0.0'),
    'port' => (int)$getConfig('APP_PORT', getenv('APP_PORT') ?: 9503),
    'debug' => (bool)$getConfig('APP_DEBUG', getenv('APP_DEBUG') === '1'),
    'max_body_bytes' => (int)$getConfig('APP_MAX_BODY', getenv('APP_MAX_BODY') ?: 1024 * 1024 * 5),
    'enable_websockets' => true,
    'auth' => [
        'default' => 'session',
        'session_key' => 'auth_user',
        'session_allow_in_swoole' => true,
    ],
]);

$api->enableSecurityHeaders([
    'X-Frame-Options' => 'DENY',
]);

$api->container()->set('state', $state);
if ($state instanceof MainState) {
    $state->httpResolver = fn() => $api->http();
    if ($api->runtime() instanceof \PHAPI\Runtime\SwooleDriver) {
        $worker = new BackgroundWorker($state->baseDir, function (array $message) use ($api) {
            $event = (string)($message['event'] ?? '');
            if ($event === 'metrics.updated') {
                $api->realtime()->broadcast('queue', [
                    'event' => 'metrics.updated',
                    'project_id' => (int)($message['project_id'] ?? 0),
                ]);
            }
            if ($event === 'queue.job') {
                $api->realtime()->broadcast('queue', [
                    'event' => 'queue.job',
                    'status' => $message['status'] ?? null,
                    'job_id' => $message['job_id'] ?? null,
                    'url_id' => $message['url_id'] ?? null,
                    'viewport_label' => $message['viewport_label'] ?? null,
                    'error' => $message['error'] ?? null,
                ]);
            }
            if ($event === 'metrics.error' || $event === 'selectors.error') {
                $detail = $message['error'] ?? 'Unknown error';
                error_log('Background worker error: ' . $detail);
            }
        });
        $api->spawnProcess(
            function () use ($worker) {
                return $worker->createProcess();
            },
            function (\Swoole\Process $process) use ($worker): void {
                $worker->attachProcess($process);
            }
        );
        $state->backgroundWorker = $worker;
    }
}

$api->setWebSocketHandler(function ($server, $frame, $driver) {
    $data = json_decode($frame->data ?? '', true);
    if (!is_array($data)) {
        return;
    }

    if (($data['action'] ?? '') === 'subscribe' && !empty($data['channel'])) {
        $driver->subscribe($frame->fd, (string)$data['channel']);
    }

    if (($data['action'] ?? '') === 'unsubscribe' && !empty($data['channel'])) {
        $driver->unsubscribe($frame->fd, (string)$data['channel']);
    }
});

$api->loadApp(__DIR__);

$api->run();
