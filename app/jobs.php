<?php

declare(strict_types=1);

use PHAPI\PHAPI;

$main = PHAPI::app()?->container()->get('state');
if (!$main instanceof MainState) {
    throw new RuntimeException('App state not initialized.');
}

$api->schedule('queue-processor', (int)(getenv('QUEUE_POLL_SECONDS') ?: 1), function () use ($main) {
    $worker = $main->backgroundWorker;
    if ($worker instanceof BackgroundWorker) {
        $worker->enqueue(['type' => 'queue_tick']);
        return;
    }
}, [
    'log_file' => 'queue-processor.log',
    'log_enabled' => true,
    'lock_mode' => 'skip',
]);

$api->schedule('metrics-refresh', (int)(getenv('METRICS_REFRESH_SECONDS') ?: 5), function () use ($main) {
    $projects = $main->projects->listAll();
    foreach ($projects as $project) {
        $state = $main->projectState($project);
        $viewports = $state->viewports->listAllLabels();
        if (empty($viewports)) {
            $viewports = ['default'];
        }
        $sorted = $viewports;
        sort($sorted);
        $cacheKey = 'unique|' . implode(',', $sorted);
        $cache = new MetricsCacheRepository($state->database->connection(), $state->projectId);
        $hasCache = $cache->get($cacheKey) !== null;
        $backfillDone = (int)($state->config->get('selectors_backfill_done') ?? 0);
        $backfillRunning = (int)($state->config->get('selectors_backfill_running') ?? 0);
        if ($backfillDone !== 1 && $backfillRunning !== 1) {
            $state->config->set('selectors_backfill_running', '1');
            $worker = $main->backgroundWorker;
            $queued = $worker?->enqueue([
                'type' => 'selectors_backfill',
                'project_id' => (int)($project['id'] ?? 0),
            ]) ?? false;
            if (!$queued) {
                $state->config->set('selectors_backfill_running', '0');
            }
        }

        $dirty = (int)($state->config->get('metrics_dirty') ?? 0);
        $metricsRunning = (int)($state->config->get('metrics_refresh_running') ?? 0);
        if (($dirty === 1 || !$hasCache) && $metricsRunning !== 1) {
            $state->config->set('metrics_refresh_running', '1');
            $worker = $main->backgroundWorker;
            $queued = $worker?->enqueue([
                'type' => 'metrics_refresh',
                'project_id' => (int)($project['id'] ?? 0),
            ]) ?? false;
            if (!$queued) {
                $state->config->set('metrics_refresh_running', '0');
            }
        }
    }
}, [
    'log_file' => 'metrics-refresh.log',
    'log_enabled' => true,
    'lock_mode' => 'skip',
]);

$api->schedule('ws-heartbeat', 1, function () use ($api) {
    $api->realtime()->broadcast('queue', [
        'event' => 'ws.heartbeat',
        'ts' => time(),
    ]);
}, [
    'log_enabled' => false,
    'lock_mode' => 'skip',
]);
