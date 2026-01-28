<?php

declare(strict_types=1);

use PHAPI\HTTP\Response;
use PHAPI\PHAPI;

$main = PHAPI::app()?->container()->get('state');
if (!$main instanceof MainState) {
    throw new RuntimeException('App state not initialized.');
}

$dashboardTemplate = require __DIR__ . '/views/dashboard.php';
$loginTemplate = require __DIR__ . '/views/login.php';

$ensureSession = function (): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
};

$getProjectRow = function () use ($main, $ensureSession): array {
    $ensureSession();
    $requestedId = null;
    $queryId = PHAPI::request()?->query('project');
    if ($queryId !== null && $queryId !== '') {
        $requestedId = (int)$queryId;
    } elseif (!empty($_SESSION['project_id'])) {
        $requestedId = (int)$_SESSION['project_id'];
    }

    $project = $requestedId ? $main->projects->find($requestedId) : null;
    if (!$project) {
        $all = $main->projects->listAll();
        if (empty($all)) {
            $slug = 'default';
            $id = $main->projects->create('Default Project', $slug);
            $project = $main->projects->find($id);
        } else {
            $project = $all[0];
        }
    }
    $_SESSION['project_id'] = (int)$project['id'];
    return $project;
};

$getProject = function () use ($main, $getProjectRow): ProjectState {
    static $cached = null;
    static $cachedId = null;
    $projectRow = $getProjectRow();
    $currentId = (int)($projectRow['id'] ?? 0);
    if (!$cached instanceof ProjectState || $cachedId !== $currentId) {
        $cached = $main->projectState($projectRow);
        $cachedId = $currentId;
    }
    return $cached;
};

$renderDashboard = function () use ($getProject, $dashboardTemplate): Response {
    $state = $getProject();
    $labels = $state->viewports->listAllLabels();
    $viewports = $labels ?: ['default'];
    $urls = $state->urls->list(null, 'created_at', 'DESC', null, $viewports);
    $initialUrls = json_encode($urls ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $html = str_replace('__INITIAL_URLS__', $initialUrls ?: '[]', $dashboardTemplate);
    return Response::html($html);
};

$getReportType = function () use ($getProject): int {
    $state = $getProject();
    $value = $state->config->get('reporttype');
    $reportType = $value !== null ? (int)$value : (int)($state->waveDefaultParams['reporttype'] ?? 4);
    if ($reportType < 1 || $reportType > 4) {
        $reportType = 4;
    }
    return $reportType;
};

$getReportingViewports = function () use ($getProject): array {
    $state = $getProject();
    $available = $state->viewports->listAllLabels();
    if (empty($available)) {
        return ['default'];
    }
    $raw = PHAPI::request()?->query('viewports');
    if (!$raw) {
        return $available;
    }
    $requested = array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
    if (empty($requested)) {
        return $available;
    }
    $filtered = array_values(array_intersect($available, $requested));
    return $filtered ?: $available;
};

$clearMetricsCache = function (ProjectState $state): void {
    $cache = new MetricsCacheRepository($state->database->connection(), $state->projectId);
    $cache->clear();
    $state->config->set('metrics_dirty', '1');
};

$refreshLatestCounts = function (ProjectState $state): void {
    $pdo = $state->database->connection();
    $stmt = $pdo->prepare('SELECT url_id, viewport_label, tested_at FROM results WHERE project_id = :project_id');
    $stmt->execute(['project_id' => $state->projectId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $urlId = (int)$row['url_id'];
        $viewport = (string)($row['viewport_label'] ?? 'default');
        $testedAt = (string)$row['tested_at'];
        if ($testedAt === '') {
            continue;
        }
        $counts = $state->issues->countsForResult($urlId, $viewport, $testedAt);
        $state->results->updateCountsForResult($urlId, $viewport, $testedAt, $counts['errors'], $counts['contrast_errors'], $counts['alerts']);
    }

    $stmt = $pdo->prepare(
        'SELECT r.url_id, r.viewport_label, r.tested_at
         FROM results r
         INNER JOIN (
           SELECT url_id, viewport_label, MAX(tested_at) AS tested_at
           FROM results
           WHERE project_id = :project_id
           GROUP BY url_id, viewport_label
         ) latest ON latest.url_id = r.url_id
           AND latest.viewport_label = r.viewport_label
           AND latest.tested_at = r.tested_at
         WHERE r.project_id = :project_id'
    );
    $stmt->execute(['project_id' => $state->projectId]);
    $latestRows = $stmt->fetchAll();
    foreach ($latestRows as $row) {
        $urlId = (int)$row['url_id'];
        $viewport = (string)($row['viewport_label'] ?? 'default');
        $counts = $state->issues->countsForResult($urlId, $viewport, (string)$row['tested_at']);
        $state->urls->updateLastCountsFromIssueItems($urlId, $viewport, $counts['errors'], $counts['contrast_errors'], $counts['alerts']);
    }
};

$api->post('/api/maintenance/recount', function () use ($getProject, $refreshLatestCounts, $clearMetricsCache): Response {
    $state = $getProject();
    $refreshLatestCounts($state);
    $clearMetricsCache($state);
    return Response::json(['status' => 'ok']);
});

$api->post('/api/admin/reset-db', function () use ($api, $main): Response {
    if (getenv('APP_ALLOW_RESET') !== '1') {
        return Response::json(['error' => 'Reset disabled'], 403);
    }
    $user = $api->auth()->user('session');
    $roles = is_array($user) ? ($user['roles'] ?? []) : [];
    if (!is_array($roles) || !in_array('admin', $roles, true)) {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    $pdo = $main->database->connection();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $tables = [
        'issue_suppression_elements',
        'issue_suppressions',
        'issue_elements',
        'issue_items',
        'issue_docs',
        'url_tags',
        'tags',
        'queue',
        'errors',
        'results',
        'audit_runs',
        'viewports',
        'urls',
        'metrics_cache',
        'config',
        'selectors',
        'projects',
    ];
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    new Database();
    $main->projects->create('Default Project', 'default');
    RedisCache::deleteByPrefix('metrics_cache:');
    RedisCache::deleteByPrefix('queue_summary:');

    return Response::json(['status' => 'reset']);
});

$reloadSuppressed = function (ProjectState $state): void {
    $map = [];
    foreach ($state->suppressions->listAll() as $row) {
        $itemId = strtolower((string)($row['item_id'] ?? ''));
        $category = strtolower((string)($row['category'] ?? ''));
        if ($itemId === '' || $category === '') {
            continue;
        }
        $map[$category . '|' . $itemId] = true;
    }
    $state->suppressedIssueIds = $map;
    $state->issues->updateSuppressed($map);
};

$processQueueBatch = function () use ($api, $getProject): array {
    $state = $getProject();
    if ($state->waveApiKey === '') {
        $state->errors->record(null, null, null, 'queue.process', 'Missing WAVE API key. Add it in Project Configuration.');
        return ['error' => 'Missing WAVE API key. Add it in Project Configuration.'];
    }

    $maxConcurrent = (int)(getenv('QUEUE_MAX_CONCURRENT') ?: 2);
    $maxConcurrent = min(2, max(1, $maxConcurrent));
    $batchSize = (int)(getenv('QUEUE_BATCH_SIZE') ?: $maxConcurrent);
    $take = max(1, min($batchSize, $maxConcurrent));

    $jobs = $state->queue->fetchPending($take);
    if (empty($jobs)) {
        return ['processed' => 0, 'message' => 'No pending jobs'];
    }

    foreach ($jobs as $job) {
        $state->queue->markRunning((int)$job['id']);
    }

    $tasks = [];
    foreach ($jobs as $job) {
        $jobKey = (string)($job['id'] ?? uniqid('job_', true));
        $tasks[$jobKey] = function () use ($job, $state) {
            return processQueueJob($job, $state);
        };
    }

    $results = $api->tasks()->parallel($tasks);
    $processed = 0;
    $failed = 0;
    foreach ($results as $result) {
        if (($result['status'] ?? '') === 'completed') {
            $processed++;
        } else {
            $failed++;
        }
    }

    return ['processed' => $processed, 'failed' => $failed];
};

$api->get('/login', function () use ($api, $loginTemplate): Response {
    if ($api->auth()->check('session')) {
        return Response::redirect('/', 302);
    }
    return Response::html($loginTemplate);
});

$api->post('/login', function () use ($api, $main): Response {
    $data = (array)(PHAPI::request()?->body() ?? []);
    $user = trim((string)($data['username'] ?? ''));
    $pass = trim((string)($data['password'] ?? ''));

    if ($user === $main->authUser && $pass === $main->authPass) {
        $api->auth()->guard('session')->setUser([
            'id' => 1,
            'name' => $user,
            'roles' => ['admin'],
        ]);
        return Response::redirect('/', 302);
    }

    return Response::text('Invalid credentials', 401);
});

$api->get('/logout', function () use ($api): Response {
    $api->auth()->guard('session')->clear();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    return Response::redirect('/login', 302);
});

$api->get('/health', function (): Response {
    return Response::json(['status' => 'ok']);
});

$api->get('/api/projects', function () use ($main, $getProjectRow): Response {
    $projects = $main->projects->listAll();
    $current = $getProjectRow();
    return Response::json([
        'data' => $projects,
        'current_project_id' => $current['id'] ?? null,
    ]);
});

$api->post('/api/projects', function () use ($main): Response {
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
        return Response::json(['error' => 'Project name is required'], 422);
    }
    $baseSlug = slugifyProjectName($name);
    $slug = $baseSlug;
    $suffix = 1;
    while ($main->projects->findBySlug($slug)) {
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
    $id = $main->projects->create($name, $slug);
    return Response::json(['id' => $id, 'slug' => $slug, 'name' => $name]);
});

$api->put('/api/projects/{id}', function () use ($main, $ensureSession): Response {
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $project = $main->projects->find($id);
    if (!$project) {
        return Response::json(['error' => 'Project not found'], 404);
    }
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $name = trim((string)($payload['name'] ?? $project['name']));
    if ($name === '') {
        return Response::json(['error' => 'Project name is required'], 422);
    }
    $requestedSlug = trim((string)($payload['slug'] ?? $project['slug']));
    $baseSlug = slugifyProjectName($requestedSlug !== '' ? $requestedSlug : $name);
    $slug = $baseSlug;
    $suffix = 1;
    while (($existing = $main->projects->findBySlug($slug)) && (int)$existing['id'] !== $id) {
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }

    $main->projects->update($id, $name, $slug);
    $ensureSession();
    if (!empty($_SESSION['project_id']) && (int)$_SESSION['project_id'] === $id) {
        $_SESSION['project_id'] = $id;
    }
    return Response::json(['status' => 'updated', 'slug' => $slug, 'name' => $name]);
});

$api->delete('/api/projects/{id}', function () use ($main, $ensureSession): Response {
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $project = $main->projects->find($id);
    if (!$project) {
        return Response::json(['error' => 'Project not found'], 404);
    }
    $all = $main->projects->listAll();
    if (count($all) <= 1) {
        return Response::json(['error' => 'Cannot delete the last project'], 422);
    }

    $main->projects->delete($id);
    $ensureSession();
    if (!empty($_SESSION['project_id']) && (int)$_SESSION['project_id'] === $id) {
        $fallback = $main->projects->listAll();
        $_SESSION['project_id'] = $fallback[0]['id'] ?? null;
    }
    return Response::json(['status' => 'deleted']);
});

$api->post('/api/projects/select', function () use ($main, $ensureSession): Response {
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $id = (int)($payload['project_id'] ?? 0);
    if ($id <= 0 || !$main->projects->find($id)) {
        return Response::json(['error' => 'Invalid project'], 422);
    }
    $ensureSession();
    $_SESSION['project_id'] = $id;
    return Response::json(['status' => 'selected']);
});

$api->get('/', function () use ($renderDashboard): Response {
    return $renderDashboard();
});

$api->get('/api/urls', function () use ($getProject, $getReportingViewports): Response {
    $state = $getProject();
    $params = PHAPI::request()?->queryAll() ?? [];
    $search = $params['search'] ?? null;
    $sort = $params['sort'] ?? 'created_at';
    $direction = $params['direction'] ?? 'DESC';
    $tag = $params['tag'] ?? null;
    $urls = $state->urls->list($search, $sort, $direction, $tag ?: null, $getReportingViewports());
    return Response::json(['data' => $urls]);
});

$api->post('/api/urls', function () use ($getProject): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $url = trim((string)($payload['url'] ?? ''));
    $active = ($payload['active'] ?? true) ? true : false;

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $state->errors->record(null, $url !== '' ? $url : null, null, 'urls.create', 'Invalid URL submitted');
        return Response::json(['error' => 'Invalid URL'], 422);
    }

    $id = $state->urls->create($url, $active);
    return Response::json(['id' => $id]);
});

$api->put('/api/urls/{id}', function () use ($getProject): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $url = trim((string)($payload['url'] ?? ''));
    $active = ($payload['active'] ?? true) ? true : false;

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $state->errors->record($id ?: null, $url !== '' ? $url : null, null, 'urls.update', 'Invalid URL submitted');
        return Response::json(['error' => 'Invalid URL'], 422);
    }

    $state->urls->update($id, $url, $active);
    return Response::json(['status' => 'updated']);
});

$api->delete('/api/urls/{id}', function () use ($getProject, $clearMetricsCache): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $state->urls->delete($id);
    $clearMetricsCache($state);
    return Response::json(['status' => 'deleted']);
});

$api->post('/api/urls/{id}/toggle', function () use ($getProject): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $active = ($payload['active'] ?? true) ? true : false;
    $state->urls->setActive($id, $active);
    return Response::json(['status' => 'updated']);
});

$api->post('/api/urls/bulk', function () use ($getProject, $clearMetricsCache): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return Response::json(['error' => 'No URLs selected'], 422);
    }
    $action = (string)($payload['action'] ?? '');

    if ($action === 'delete') {
        foreach ($ids as $id) {
            $state->urls->delete($id);
        }
        $clearMetricsCache($state);
        return Response::json(['status' => 'deleted', 'count' => count($ids)]);
    }

    if ($action === 'activate' || $action === 'deactivate') {
        $active = $action === 'activate';
        foreach ($ids as $id) {
            $state->urls->setActive($id, $active);
        }
        return Response::json(['status' => 'updated', 'count' => count($ids)]);
    }

    if ($action === 'set_tags') {
        $tags = $payload['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }
        $tags = array_values(array_filter(array_map('trim', $tags), fn($tag) => $tag !== ''));
        foreach ($ids as $id) {
            $state->tags->setTagsForUrl($id, $tags);
        }
        return Response::json(['status' => 'updated', 'count' => count($ids)]);
    }

    return Response::json(['error' => 'Invalid action'], 422);
});

$api->get('/api/viewports', function () use ($getProject): Response {
    $state = $getProject();
    $rows = $state->viewports->listAll();
    return Response::json(['data' => $rows]);
});

$api->post('/api/viewports', function () use ($getProject, $clearMetricsCache): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    try {
        $state->viewports->upsert($payload);
        $clearMetricsCache($state);
        return Response::json(['status' => 'saved']);
    } catch (Throwable $e) {
        $state->errors->record(null, null, null, 'viewports.save', $e->getMessage());
        return Response::json(['error' => $e->getMessage()], 422);
    }
});

$api->put('/api/viewports/{label}', function () use ($getProject, $clearMetricsCache): Response {
    $state = $getProject();
    $label = (string)(PHAPI::request()?->param('label') ?? '');
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $payload['label'] = $label;
    try {
        $state->viewports->upsert($payload);
        $clearMetricsCache($state);
        return Response::json(['status' => 'saved']);
    } catch (Throwable $e) {
        $state->errors->record(null, null, null, 'viewports.save', $e->getMessage());
        return Response::json(['error' => $e->getMessage()], 422);
    }
});

$api->delete('/api/viewports/{label}', function () use ($getProject, $clearMetricsCache): Response {
    $state = $getProject();
    $label = (string)(PHAPI::request()?->param('label') ?? '');
    if ($label === '') {
        return Response::json(['error' => 'Missing label'], 422);
    }
    $state->viewports->delete($label);
    $clearMetricsCache($state);
    return Response::json(['status' => 'deleted']);
});

$api->get('/api/urls/{id}/tags', function () use ($getProject): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $tags = $state->tags->tagsForUrl($id);
    return Response::json(['data' => $tags]);
});

$api->post('/api/urls/{id}/tags', function () use ($getProject): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $tags = $payload['tags'] ?? [];
    if (!is_array($tags)) {
        $tags = [];
    }
    $state->tags->setTagsForUrl($id, $tags);
    return Response::json(['status' => 'updated']);
});

$api->get('/api/tags', function () use ($getProject): Response {
    $state = $getProject();
    $tags = $state->tags->listAll();
    return Response::json(['data' => $tags]);
});
$api->post('/api/urls/import', function () use ($getProject): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $csv = (string)($payload['csv'] ?? '');
    $results = $state->urls->importCsv($csv);
    return Response::json(['results' => $results]);
});

$api->get('/api/urls/{id}/history', function () use ($getProject, $getReportingViewports): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $history = $state->results->historyForUrl($id, 200, $getReportingViewports());
    return Response::json(['data' => $history]);
});

$api->get('/api/urls/{id}/issues', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $reportType = $getReportType();
    if ($reportType < 2) {
        return Response::json(['data' => []]);
    }
    $stmt = $state->issues->summaryForUrl($id, $getReportingViewports());
    return Response::json(['data' => $stmt]);
});

$api->get('/api/urls/{id}/issues/details', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $id = (int)(PHAPI::request()?->param('id') ?? 0);
    $reportType = $getReportType();
    if ($reportType < 3) {
        return Response::json(['data' => []]);
    }
    $params = PHAPI::request()?->queryAll() ?? [];
    $itemId = $params['item_id'] ?? null;
    $category = $params['category'] ?? null;
    if (!$itemId || !$category) {
        return Response::json(['error' => 'Missing item_id or category'], 422);
    }
    $rows = $state->issues->detailsForUrlIssue($id, (string)$itemId, (string)$category, $getReportingViewports());
    return Response::json(['data' => $rows]);
});

$api->get('/api/trends', function () use ($getProject, $getReportingViewports, $getReportType): Response {
    try {
        $state = $getProject();
        $reportType = $getReportType();
        $viewports = $getReportingViewports();
        $pdo = $state->database->connection();
        $metrics = null;
        if ($reportType >= 3) {
            $keyViewports = $viewports;
            sort($keyViewports);
            $cacheKey = 'unique|' . implode(',', $keyViewports);
            $cache = new MetricsCacheRepository($state->database->connection(), $state->projectId);
            $cached = $cache->get($cacheKey);
            if ($cached) {
                $metrics = [
                    'errors' => (int)($cached['errors'] ?? 0),
                    'contrast' => (int)($cached['contrast'] ?? 0),
                    'alerts' => (int)($cached['alerts'] ?? 0),
                ];
            }
        }
        $vpParams = ['project_id' => $state->projectId];
        $vpPlaceholders = [];
        foreach ($viewports as $idx => $vp) {
            $key = 'vp' . $idx;
            $vpPlaceholders[] = ':' . $key;
            $vpParams[$key] = $vp;
        }
        $vpList = implode(',', $vpPlaceholders);
        $runCountStmt = $pdo->prepare('SELECT COUNT(*) FROM results WHERE project_id = :project_id AND run_id IS NOT NULL');
        $runCountStmt->execute(['project_id' => $state->projectId]);
        $runRows = (int)$runCountStmt->fetchColumn();
        if ($runRows > 0) {
            $sql = "
                SELECT
                    ar.id AS run_id,
                    ar.initiated_at AS run_started,
                    AVG(r.aim_score) AS aim_score,
                    SUM(r.errors) AS errors,
                    (
                        SELECT COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector))
                        FROM issue_elements ie
                        JOIN results r2 ON r2.url_id = ie.url_id
                          AND r2.tested_at = ie.tested_at
                          AND r2.viewport_label = ie.viewport_label
                          AND r2.project_id = ie.project_id
                        WHERE r2.project_id = :project_id
                          AND r2.run_id = ar.id
                          AND r2.viewport_label IN ($vpList)
                          AND ie.project_id = :project_id
                          AND ie.category = 'error'
                          AND NOT EXISTS (
                            SELECT 1 FROM issue_suppressions s
                            WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                          )
                          AND NOT EXISTS (
                            SELECT 1 FROM issue_suppression_elements se
                            WHERE se.project_id = :project_id
                              AND se.item_id = ie.item_id
                              AND se.category = ie.category
                              AND se.selector = ie.selector
                              AND (se.viewport_label IS NULL OR se.viewport_label = ie.viewport_label)
                          )
                    ) AS unique_errors,
                    (
                        SELECT COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector))
                        FROM issue_elements ie
                        JOIN results r2 ON r2.url_id = ie.url_id
                          AND r2.tested_at = ie.tested_at
                          AND r2.viewport_label = ie.viewport_label
                          AND r2.project_id = ie.project_id
                        WHERE r2.project_id = :project_id
                          AND r2.run_id = ar.id
                          AND r2.viewport_label IN ($vpList)
                          AND ie.project_id = :project_id
                          AND ie.category = 'contrast'
                          AND NOT EXISTS (
                            SELECT 1 FROM issue_suppressions s
                            WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                          )
                          AND NOT EXISTS (
                            SELECT 1 FROM issue_suppression_elements se
                            WHERE se.project_id = :project_id
                              AND se.item_id = ie.item_id
                              AND se.category = ie.category
                              AND se.selector = ie.selector
                              AND (se.viewport_label IS NULL OR se.viewport_label = ie.viewport_label)
                          )
                    ) AS unique_contrast_errors,
                    (
                        SELECT COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector))
                        FROM issue_elements ie
                        JOIN results r2 ON r2.url_id = ie.url_id
                          AND r2.tested_at = ie.tested_at
                          AND r2.viewport_label = ie.viewport_label
                          AND r2.project_id = ie.project_id
                        WHERE r2.project_id = :project_id
                          AND r2.run_id = ar.id
                          AND r2.viewport_label IN ($vpList)
                          AND ie.project_id = :project_id
                          AND ie.category = 'alert'
                          AND NOT EXISTS (
                            SELECT 1 FROM issue_suppressions s
                            WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                          )
                          AND NOT EXISTS (
                            SELECT 1 FROM issue_suppression_elements se
                            WHERE se.project_id = :project_id
                              AND se.item_id = ie.item_id
                              AND se.category = ie.category
                              AND se.selector = ie.selector
                              AND (se.viewport_label IS NULL OR se.viewport_label = ie.viewport_label)
                          )
                    ) AS unique_alerts,
                    SUM(r.contrast_errors) AS contrast_errors,
                    SUM(r.alerts) AS alerts
                FROM audit_runs ar
                JOIN results r ON r.run_id = ar.id AND r.project_id = ar.project_id
                WHERE ar.project_id = :project_id
                  AND r.viewport_label IN ($vpList)
                GROUP BY ar.id, ar.initiated_at
                ORDER BY ar.initiated_at ASC
                LIMIT 200
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vpParams);
            $rows = $stmt->fetchAll();
            return Response::json(['data' => $rows, 'metrics' => $metrics]);
        }

        if ($reportType >= 3) {
            $vpParams = [];
            $vpPlaceholders = [];
            foreach ($viewports as $idx => $vp) {
                $key = 'vp' . $idx;
                $vpPlaceholders[] = ':' . $key;
                $vpParams[$key] = $vp;
            }
            $vpList = implode(',', $vpPlaceholders);
            $sql = "
                SELECT substr(r.tested_at, 1, 10) AS day,
                       AVG(r.aim_score) AS aim_score,
                       SUM(r.errors) AS errors,
                       (
                           SELECT COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector))
                           FROM issue_elements ie
                           JOIN results r2 ON r2.url_id = ie.url_id
                             AND r2.tested_at = ie.tested_at
                             AND r2.viewport_label = ie.viewport_label
                             AND r2.project_id = ie.project_id
                           WHERE r2.project_id = :project_id
                             AND substr(r2.tested_at, 1, 10) = substr(r.tested_at, 1, 10)
                             AND r2.viewport_label IN ($vpList)
                             AND ie.project_id = :project_id
                             AND ie.category = 'error'
                             AND NOT EXISTS (
                               SELECT 1 FROM issue_suppressions s
                               WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                             )
                             AND NOT EXISTS (
                               SELECT 1 FROM issue_suppression_elements se
                               WHERE se.project_id = :project_id
                                 AND se.item_id = ie.item_id
                                 AND se.category = ie.category
                                 AND se.selector = ie.selector
                                 AND (se.viewport_label IS NULL OR se.viewport_label = ie.viewport_label)
                             )
                       ) AS unique_errors,
                       (
                           SELECT COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector))
                           FROM issue_elements ie
                           JOIN results r2 ON r2.url_id = ie.url_id
                             AND r2.tested_at = ie.tested_at
                             AND r2.viewport_label = ie.viewport_label
                             AND r2.project_id = ie.project_id
                           WHERE r2.project_id = :project_id
                             AND substr(r2.tested_at, 1, 10) = substr(r.tested_at, 1, 10)
                             AND r2.viewport_label IN ($vpList)
                             AND ie.project_id = :project_id
                             AND ie.category = 'contrast'
                             AND NOT EXISTS (
                               SELECT 1 FROM issue_suppressions s
                               WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                             )
                             AND NOT EXISTS (
                               SELECT 1 FROM issue_suppression_elements se
                               WHERE se.project_id = :project_id
                                 AND se.item_id = ie.item_id
                                 AND se.category = ie.category
                                 AND se.selector = ie.selector
                                 AND (se.viewport_label IS NULL OR se.viewport_label = ie.viewport_label)
                             )
                       ) AS unique_contrast_errors,
                       (
                           SELECT COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector))
                           FROM issue_elements ie
                           JOIN results r2 ON r2.url_id = ie.url_id
                             AND r2.tested_at = ie.tested_at
                             AND r2.viewport_label = ie.viewport_label
                             AND r2.project_id = ie.project_id
                           WHERE r2.project_id = :project_id
                             AND substr(r2.tested_at, 1, 10) = substr(r.tested_at, 1, 10)
                             AND r2.viewport_label IN ($vpList)
                             AND ie.project_id = :project_id
                             AND ie.category = 'alert'
                             AND NOT EXISTS (
                               SELECT 1 FROM issue_suppressions s
                               WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                             )
                             AND NOT EXISTS (
                               SELECT 1 FROM issue_suppression_elements se
                               WHERE se.project_id = :project_id
                                 AND se.item_id = ie.item_id
                                 AND se.category = ie.category
                                 AND se.selector = ie.selector
                                 AND (se.viewport_label IS NULL OR se.viewport_label = ie.viewport_label)
                             )
                       ) AS unique_alerts,
                       SUM(r.contrast_errors) AS contrast_errors,
                       SUM(r.alerts) AS alerts
                FROM results r
                WHERE r.project_id = :project_id
                  AND r.viewport_label IN ($vpList)
                GROUP BY substr(r.tested_at, 1, 10)
                ORDER BY day ASC
                LIMIT 180
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vpParams);
            $rows = $stmt->fetchAll();
            return Response::json(['data' => $rows, 'metrics' => $metrics]);
        }

        $sql = "
            SELECT substr(tested_at, 1, 10) AS day,
                   AVG(aim_score) AS aim_score,
                   SUM(errors) AS errors,
                   MAX(unique_errors) AS unique_errors,
                   MAX(unique_contrast_errors) AS unique_contrast_errors,
                   MAX(unique_alerts) AS unique_alerts,
                   SUM(contrast_errors) AS contrast_errors,
                   SUM(alerts) AS alerts
            FROM results
            WHERE project_id = :project_id
              AND viewport_label IN ($vpList)
            GROUP BY day
            ORDER BY day ASC
            LIMIT 180
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vpParams);
        $rows = $stmt->fetchAll();
        return Response::json(['data' => $rows, 'metrics' => $metrics]);
    } catch (Throwable $e) {
        error_log('Trends error: ' . $e->getMessage());
        return Response::json(['error' => 'Trends query failed', 'detail' => $e->getMessage()], 500);
    }
});

$api->get('/api/metrics/unique-errors', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $reportType = $getReportType();
    if ($reportType < 3) {
        return Response::json(['data' => ['errors' => null, 'contrast' => null, 'alerts' => null]]);
    }
    $viewports = $getReportingViewports();
    $keyViewports = $viewports;
    sort($keyViewports);
    $cacheKey = 'unique|' . implode(',', $keyViewports);
    $cache = new MetricsCacheRepository($state->database->connection(), $state->projectId);
    $cached = $cache->get($cacheKey);
    if ($cached) {
        return Response::json([
            'data' => [
                'errors' => (int)($cached['errors'] ?? 0),
                'contrast' => (int)($cached['contrast'] ?? 0),
                'alerts' => (int)($cached['alerts'] ?? 0),
            ],
        ]);
    }
    return Response::json(['data' => ['errors' => null, 'contrast' => null, 'alerts' => null]]);
});

$api->get('/api/issues/summary', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $reportType = $getReportType();
    if ($reportType < 2) {
        return Response::json(['data' => []]);
    }
    $params = PHAPI::request()?->queryAll() ?? [];
    $category = $params['category'] ?? null;
    $includeSuppressed = !empty($params['include_suppressed']);
    $data = $state->issues->summary($category ?: null, $getReportingViewports(), $includeSuppressed);
    $includeGuidelines = !empty($params['include_guidelines']);
    if ($includeGuidelines) {
        $data = array_map(function ($row) use ($state) {
            $doc = $state->issues->getDoc((string)$row['item_id'], $state->waveClient);
            $guidelines = [];
            if (is_array($doc) && !empty($doc['guidelines']) && is_array($doc['guidelines'])) {
                foreach ($doc['guidelines'] as $g) {
                    if (!empty($g['name'])) {
                        $guidelines[] = (string)$g['name'];
                    }
                }
            }
            $row['guidelines'] = $guidelines;
            return $row;
        }, $data);
    }
    return Response::json(['data' => $data]);
});

$api->get('/api/issues/pages', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $reportType = $getReportType();
    if ($reportType < 2) {
        return Response::json(['data' => []]);
    }
    $params = PHAPI::request()?->queryAll() ?? [];
    $itemId = $params['item_id'] ?? null;
    $category = $params['category'] ?? null;
    if (!$itemId || !$category) {
        return Response::json(['error' => 'Missing item_id or category'], 422);
    }
    $rows = $state->issues->pagesForIssue((string)$itemId, (string)$category, $getReportingViewports());
    return Response::json(['data' => $rows]);
});

$api->get('/api/issues/suppressions', function () use ($getProject): Response {
    $state = $getProject();
    $rows = $state->suppressions->listAll();
    return Response::json(['data' => $rows]);
});

$api->post('/api/issues/suppressions', function () use ($getProject, $refreshLatestCounts, $reloadSuppressed, $clearMetricsCache): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $itemId = trim((string)($payload['item_id'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    $reason = isset($payload['reason']) ? trim((string)$payload['reason']) : null;
    if ($itemId === '' || $category === '') {
        return Response::json(['error' => 'Missing item_id or category'], 422);
    }
    $state->suppressions->upsert($itemId, $category, $reason);
    $reloadSuppressed($state);
    $refreshLatestCounts($state);
    $clearMetricsCache($state);
    return Response::json(['status' => 'suppressed']);
});

$api->delete('/api/issues/suppressions', function () use ($getProject, $refreshLatestCounts, $reloadSuppressed, $clearMetricsCache): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $itemId = trim((string)($payload['item_id'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    if ($itemId === '' || $category === '') {
        return Response::json(['error' => 'Missing item_id or category'], 422);
    }
    $state->suppressions->delete($itemId, $category);
    $reloadSuppressed($state);
    $refreshLatestCounts($state);
    $clearMetricsCache($state);
    return Response::json(['status' => 'restored']);
});

$api->post('/api/issues/suppressions/element', function () use ($getProject, $refreshLatestCounts, $clearMetricsCache): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $itemId = trim((string)($payload['item_id'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    $selector = trim((string)($payload['selector'] ?? ''));
    $urlId = (int)($payload['url_id'] ?? 0);
    $viewportLabel = isset($payload['viewport_label']) && $payload['viewport_label'] !== '' ? (string)$payload['viewport_label'] : null;
    $reason = isset($payload['reason']) ? trim((string)$payload['reason']) : null;
    if ($itemId === '' || $category === '' || $selector === '') {
        return Response::json(['error' => 'Missing item_id, category, or selector'], 422);
    }
    $repo = new IssueElementSuppressionRepository($state->database->connection());
    $repo->upsert(max(1, $urlId), $itemId, $category, $selector, $viewportLabel, $reason);
    $refreshLatestCounts($state);
    $clearMetricsCache($state);
    return Response::json(['status' => 'suppressed']);
});

$api->delete('/api/issues/suppressions/element', function () use ($getProject, $refreshLatestCounts, $clearMetricsCache): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $itemId = trim((string)($payload['item_id'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    $selector = trim((string)($payload['selector'] ?? ''));
    $viewportLabel = isset($payload['viewport_label']) && $payload['viewport_label'] !== '' ? (string)$payload['viewport_label'] : null;
    if ($itemId === '' || $category === '' || $selector === '') {
        return Response::json(['error' => 'Missing item_id, category, or selector'], 422);
    }
    $repo = new IssueElementSuppressionRepository($state->database->connection());
    $repo->deleteBySelector($itemId, $category, $selector, $viewportLabel);
    $refreshLatestCounts($state);
    $clearMetricsCache($state);
    return Response::json(['status' => 'restored']);
});

$api->get('/api/issues/suppressions/element', function () use ($getProject): Response {
    $state = $getProject();
    $pdo = $state->database->connection();
    $stmt = $pdo->query(
        'SELECT
            item_id,
            category,
            selector,
            viewport_label,
            MIN(reason) AS reason,
            MAX(created_at) AS created_at
         FROM issue_suppression_elements
         GROUP BY item_id, category, selector, viewport_label
         ORDER BY created_at DESC'
    );
    $rows = $stmt->fetchAll();
    return Response::json(['data' => $rows]);
});

$api->get('/api/issues/doc', function () use ($getProject, $getReportType): Response {
    $state = $getProject();
    $reportType = $getReportType();
    if ($reportType < 2) {
        return Response::json(['error' => 'Report type does not include item documentation'], 422);
    }
    $params = PHAPI::request()?->queryAll() ?? [];
    $itemId = $params['item_id'] ?? null;
    if (!$itemId) {
        return Response::json(['error' => 'Missing item_id'], 422);
    }
    $doc = $state->issues->getDoc((string)$itemId, $state->waveClient);
    if (!$doc) {
        return Response::json(['error' => 'Unable to fetch documentation'], 502);
    }
    return Response::json(['data' => $doc]);
});

$api->get('/api/issues/export', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $reportType = $getReportType();
    $params = PHAPI::request()?->queryAll() ?? [];
    $format = strtolower((string)($params['format'] ?? 'csv'));
    $scope = strtolower((string)($params['scope'] ?? 'summary'));
    $category = trim((string)($params['category'] ?? ''));
    if ($category === '' || $category === 'all') {
        $category = null;
    }
    $itemId = trim((string)($params['item_id'] ?? ''));
    if ($itemId === '') {
        $itemId = null;
    }
    $urlFilter = trim((string)($params['url'] ?? ''));
    $selectorFilter = trim((string)($params['selector'] ?? ''));
    if ($urlFilter === '') {
        $urlFilter = null;
    }
    if ($selectorFilter === '') {
        $selectorFilter = null;
    }
    $includeSuppressed = !empty($params['include_suppressed']);
    $urlIds = null;
    if ($urlFilter !== null) {
        $stmt = $state->database->connection()->prepare('SELECT id FROM urls WHERE url LIKE :search');
        $stmt->execute(['search' => '%' . $urlFilter . '%']);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $urlIds = $ids;
    }
    if ($reportType < 2) {
        if ($format === 'json') {
            return Response::json(['data' => []]);
        }
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['item_id', 'category', 'description', 'url_count', 'total_count', 'unique_selectors']);
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="issues.csv"');
    }
    if ($scope === 'pages') {
        $data = $state->issues->pagesForAllIssuesFiltered($category, $itemId, $getReportingViewports(), $includeSuppressed, $urlIds, $selectorFilter);
        if ($format === 'json') {
            return Response::json(['data' => $data]);
        }
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['item_id', 'category', 'description', 'url', 'count', 'report_url']);
        foreach ($data as $row) {
            fputcsv($fh, [
                $row['item_id'] ?? '',
                $row['category'] ?? '',
                $row['description'] ?? '',
                $row['url'] ?? '',
                $row['count'] ?? 0,
                $row['last_report_url'] ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="issue-pages.csv"');
    }

    if ($scope === 'selectors') {
        if ($reportType < 3) {
            if ($format === 'json') {
                return Response::json(['data' => []]);
            }
            $fh = fopen('php://temp', 'r+');
            fputcsv($fh, ['item_id', 'category', 'url', 'viewport', 'selector', 'contrast_ratio', 'foreground', 'background', 'large_text']);
            rewind($fh);
            $csv = stream_get_contents($fh) ?: '';
            fclose($fh);
            return Response::text($csv)
                ->withHeader('Content-Type', 'text/csv')
                ->withHeader('Content-Disposition', 'attachment; filename="issue-selectors.csv"');
        }
        $data = $state->issues->selectorsForAllIssuesFiltered($category, $itemId, $getReportingViewports(), $includeSuppressed, $urlIds, $selectorFilter);
        if ($format === 'json') {
            return Response::json(['data' => $data]);
        }
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['item_id', 'category', 'url', 'viewport', 'selector', 'contrast_ratio', 'foreground', 'background', 'large_text']);
        foreach ($data as $row) {
            fputcsv($fh, [
                $row['item_id'] ?? '',
                $row['category'] ?? '',
                $row['url'] ?? '',
                $row['viewport_label'] ?? '',
                $row['selector'] ?? '',
                $row['contrast_ratio'] ?? '',
                $row['foreground_color'] ?? '',
                $row['background_color'] ?? '',
                $row['large_text'] ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="issue-selectors.csv"');
    }

    if ($selectorFilter !== null && $reportType < 3) {
        if ($format === 'json') {
            return Response::json(['data' => []]);
        }
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['item_id', 'category', 'description', 'url_count', 'total_count', 'unique_selectors']);
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="issues.csv"');
    }
    $data = $state->issues->summaryFiltered($category, $itemId, $getReportingViewports(), $includeSuppressed, $urlIds, $selectorFilter);

    if ($format === 'json') {
        return Response::json(['data' => $data]);
    }

    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['item_id', 'category', 'description', 'url_count', 'total_count', 'unique_selectors']);
    foreach ($data as $row) {
        fputcsv($fh, [
            $row['item_id'],
            $row['category'],
            $row['description'],
            $row['url_count'],
            $row['total_count'],
            $row['unique_selectors'] ?? 0,
        ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh) ?: '';
    fclose($fh);

    return Response::text($csv)
        ->withHeader('Content-Type', 'text/csv')
        ->withHeader('Content-Disposition', 'attachment; filename="issues.csv"');
});

$api->get('/api/issues/details', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $reportType = $getReportType();
    if ($reportType < 3) {
        return Response::json(['data' => []]);
    }
    $params = PHAPI::request()?->queryAll() ?? [];
    $itemId = $params['item_id'] ?? null;
    $category = $params['category'] ?? null;

    if (!$itemId || !$category) {
        return Response::json(['error' => 'Missing item_id or category'], 422);
    }

    $details = $state->issues->details((string)$itemId, (string)$category, $getReportingViewports());
    return Response::json(['data' => $details]);
});

$api->post('/api/tests/run', function () use ($getProject): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $mode = $payload['mode'] ?? 'all';
    $params = $payload['params'] ?? [];
    if (!is_array($params)) {
        $params = [];
    }

    $urlIds = [];
    if ($mode === 'single' && !empty($payload['url_id'])) {
        $urlIds[] = (int)$payload['url_id'];
    } elseif ($mode === 'selected' && !empty($payload['url_ids']) && is_array($payload['url_ids'])) {
        $urlIds = array_map('intval', $payload['url_ids']);
    } else {
        foreach ($state->urls->list() as $row) {
            if (!empty($row['active'])) {
                $urlIds[] = (int)$row['id'];
            }
        }
    }

    $viewports = $state->viewports->listAllForScan();
    if (empty($viewports)) {
        return Response::json(['error' => 'No viewports configured'], 422);
    }

    $selectedViewports = [];
    if (!empty($payload['viewports']) && is_array($payload['viewports'])) {
        foreach ($payload['viewports'] as $label) {
            $label = trim((string)$label);
            if ($label !== '') {
                $selectedViewports[$label] = true;
            }
        }
    }
    if (!empty($selectedViewports)) {
        $viewports = array_values(array_filter($viewports, function ($viewport) use ($selectedViewports) {
            $label = $viewport['label'] ?? '';
            return $label !== '' && isset($selectedViewports[$label]);
        }));
    }

    $runId = null;
    if ($mode === 'all') {
        $auditRuns = new AuditRunRepository($state->database->connection(), $state->projectId);
        $runId = $auditRuns->create($mode, array_column($viewports, 'label'), count($urlIds));
    }

    $queued = [];
    foreach ($urlIds as $urlId) {
        foreach ($viewports as $viewport) {
            $jobParams = $params;
            $jobParams['viewport_label'] = $viewport['label'] ?? 'default';
            if (isset($viewport['viewport_width']) && $viewport['viewport_width'] !== null) {
                $jobParams['viewportwidth'] = (int)$viewport['viewport_width'];
            }
            if (isset($viewport['eval_delay']) && $viewport['eval_delay'] !== null) {
                $jobParams['evaldelay'] = (int)$viewport['eval_delay'];
            }
            if (isset($viewport['user_agent']) && $viewport['user_agent'] !== '') {
                $jobParams['useragent'] = (string)$viewport['user_agent'];
            }
            if ($runId !== null) {
                $jobParams['run_id'] = $runId;
            }
            $queued[] = $state->queue->enqueue($urlId, $jobParams);
        }
    }

    return Response::json(['queued' => $queued, 'count' => count($queued), 'run_id' => $runId]);
});

$api->get('/api/queue', function () use ($getProject): Response {
    $state = $getProject();
    $queue = $state->queue->all();
    $summary = $state->queue->summary();
    return Response::json(['data' => $queue, 'summary' => $summary]);
});

$api->post('/api/queue/clear', function () use ($getProject): Response {
    $state = $getProject();
    $state->queue->clearAll();
    return Response::json(['status' => 'cleared']);
});

$api->get('/api/errors', function () use ($getProject): Response {
    $state = $getProject();
    $limit = (int)(PHAPI::request()?->query('limit') ?? 50);
    $rows = $state->errors->recent(max(1, $limit));
    return Response::json(['data' => $rows]);
});

$api->post('/api/errors/clear', function () use ($getProject): Response {
    $state = $getProject();
    $state->errors->clearAll();
    return Response::json(['status' => 'cleared']);
});

$api->get('/api/remediation/export', function () use ($getProject, $getReportType, $getReportingViewports): Response {
    $state = $getProject();
    $reportType = $getReportType();
    $params = PHAPI::request()?->queryAll() ?? [];
    $format = strtolower((string)($params['format'] ?? 'csv'));
    if ($reportType < 2) {
        if ($format === 'json') {
            return Response::json(['data' => []]);
        }
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['guideline', 'guideline_link', 'item_id', 'description', 'category', 'total_count', 'url_count']);
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="remediation-plan.csv"');
    }

    $items = $state->issues->summary(null, $getReportingViewports());
    $groups = [];
    foreach ($items as $item) {
        $doc = $state->issues->getDoc((string)$item['item_id'], $state->waveClient);
        $guidelines = [];
        if (is_array($doc) && !empty($doc['guidelines']) && is_array($doc['guidelines'])) {
            foreach ($doc['guidelines'] as $g) {
                $name = $g['name'] ?? null;
                $link = $g['link'] ?? null;
                if ($name) {
                    $guidelines[] = ['name' => (string)$name, 'link' => $link ? (string)$link : null];
                }
            }
        }
        if (empty($guidelines)) {
            $guidelines[] = ['name' => 'Unmapped', 'link' => null];
        }

        foreach ($guidelines as $g) {
            $key = $g['name'] . '|' . ($g['link'] ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'guideline' => $g['name'],
                    'guideline_link' => $g['link'],
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = [
                'item_id' => $item['item_id'],
                'description' => $item['description'],
                'category' => $item['category'],
                'total_count' => $item['total_count'],
                'url_count' => $item['url_count'],
            ];
        }
    }

    $data = array_values($groups);
    if ($format === 'json') {
        return Response::json(['data' => $data]);
    }

    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['guideline', 'guideline_link', 'item_id', 'description', 'category', 'total_count', 'url_count']);
    foreach ($data as $group) {
        foreach ($group['items'] as $item) {
            fputcsv($fh, [
                $group['guideline'],
                $group['guideline_link'] ?? '',
                $item['item_id'],
                $item['description'],
                $item['category'],
                $item['total_count'],
                $item['url_count'],
            ]);
        }
    }
    rewind($fh);
    $csv = stream_get_contents($fh) ?: '';
    fclose($fh);

    return Response::text($csv)
        ->withHeader('Content-Type', 'text/csv')
        ->withHeader('Content-Disposition', 'attachment; filename="remediation-plan.csv"');
});

$api->get('/api/config', function () use ($getProject, $getReportType): Response {
    $state = $getProject();
    return Response::json([
        'data' => [
            'reporttype' => $getReportType(),
            'viewportwidth' => $state->config->get('viewportwidth'),
            'evaldelay' => $state->config->get('evaldelay'),
            'useragent' => $state->config->get('useragent'),
            'retry_attempts' => $state->config->get('retry_attempts'),
            'retry_delay_ms' => $state->config->get('retry_delay_ms'),
            'api_key_configured' => $state->config->get('api_key') ? 1 : 0,
        ],
    ]);
});

$api->post('/api/config', function () use ($getProject, $getReportType): Response {
    $state = $getProject();
    $payload = (array)(PHAPI::request()?->body() ?? []);
    $reportType = isset($payload['reporttype']) ? (int)$payload['reporttype'] : $getReportType();
    if ($reportType < 1 || $reportType > 4) {
        return Response::json(['error' => 'Invalid reporttype'], 422);
    }
    $state->config->set('reporttype', (string)$reportType);
    $state->waveDefaultParams['reporttype'] = $reportType;
    if (isset($payload['viewportwidth']) && $payload['viewportwidth'] !== '') {
        $state->config->set('viewportwidth', (string)(int)$payload['viewportwidth']);
        $state->waveDefaultParams['viewportwidth'] = (int)$payload['viewportwidth'];
    }
    if (isset($payload['evaldelay']) && $payload['evaldelay'] !== '') {
        $state->config->set('evaldelay', (string)(int)$payload['evaldelay']);
        $state->waveDefaultParams['evaldelay'] = (int)$payload['evaldelay'];
    }
    if (isset($payload['useragent'])) {
        $userAgent = trim((string)$payload['useragent']);
        if ($userAgent === '') {
            $state->config->set('useragent', '');
            unset($state->waveDefaultParams['useragent']);
        } else {
            $state->config->set('useragent', $userAgent);
            $state->waveDefaultParams['useragent'] = $userAgent;
        }
    }
    if (isset($payload['retry_attempts']) && $payload['retry_attempts'] !== '') {
        $attempts = max(0, (int)$payload['retry_attempts']);
        $state->config->set('retry_attempts', (string)$attempts);
    }
    if (isset($payload['retry_delay_ms']) && $payload['retry_delay_ms'] !== '') {
        $delay = max(0, (int)$payload['retry_delay_ms']);
        $state->config->set('retry_delay_ms', (string)$delay);
    }
    if (isset($payload['api_key'])) {
        $apiKey = trim((string)$payload['api_key']);
        $state->config->set('api_key', $apiKey);
        $state->waveApiKey = $apiKey;
        $state->runner->updateApiKey($apiKey);
    }
    $state->runner->updateDefaults($state->waveDefaultParams);
    $attemptsVal = (int)($state->config->get('retry_attempts') ?? 2);
    $delayVal = (int)($state->config->get('retry_delay_ms') ?? 500);
    $state->runner->updateRetry($attemptsVal, $delayVal);
    return Response::json(['data' => ['reporttype' => $reportType]]);
});

$api->post('/api/queue/process', function () use ($processQueueBatch): Response {
    $result = $processQueueBatch();
    if (isset($result['error'])) {
        return Response::json($result, 422);
    }
    return Response::json($result);
});

$api->get('/{routes:.+}', function () use ($renderDashboard): Response {
    $path = PHAPI::request()?->path() ?? '/';
    if (str_starts_with($path, '/api/')) {
        return Response::json(['error' => 'Not found'], 404);
    }
    return $renderDashboard();
});

$api->post('/{routes:.+}', function (): Response {
    return Response::json(['error' => 'Not found'], 404);
});

$api->put('/{routes:.+}', function (): Response {
    return Response::json(['error' => 'Not found'], 404);
});

$api->delete('/{routes:.+}', function (): Response {
    return Response::json(['error' => 'Not found'], 404);
});

$api->patch('/{routes:.+}', function (): Response {
    return Response::json(['error' => 'Not found'], 404);
});
