<?php

declare(strict_types=1);

// Shared data and service layer for the app.

use Swoole\Coroutine;
use Swoole\Coroutine\MySQL;
use Swoole\Coroutine\Redis;

class MySqlPool
{
    private static array $config = [];
    private static array $connections = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$connections = [];
    }

    public static function connection(): MySQL
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException('Swoole extension is required for MySQL.');
        }
        $cid = Coroutine::getCid();
        if ($cid < 0) {
            $cid = 0;
        }
        if (isset(self::$connections[$cid])) {
            return self::$connections[$cid];
        }

        $config = self::$config;
        $client = new MySQL();
        $connected = $client->connect([
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 3306,
            'user' => $config['user'] ?? 'root',
            'password' => $config['password'] ?? '',
            'database' => $config['database'] ?? '',
            'charset' => $config['charset'] ?? 'utf8mb4',
            'timeout' => $config['timeout'] ?? 1.0,
        ]);
        if ($connected === false) {
            throw new RuntimeException('Failed to connect to MySQL: ' . ($client->connect_error ?? 'unknown error'));
        }
        self::$connections[$cid] = $client;
        return $client;
    }
}

class RedisPool
{
    private static array $config = [];
    private static array $connections = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$connections = [];
    }

    public static function enabled(): bool
    {
        return !empty(self::$config['host']);
    }

    public static function connection(): ?Redis
    {
        if (!self::enabled()) {
            return null;
        }
        if (!extension_loaded('swoole')) {
            return null;
        }
        $cid = Coroutine::getCid();
        if ($cid < 0) {
            $cid = 0;
        }
        if (isset(self::$connections[$cid])) {
            return self::$connections[$cid];
        }

        $config = self::$config;
        $client = new Redis();
        $connected = $client->connect(
            (string)($config['host'] ?? '127.0.0.1'),
            (int)($config['port'] ?? 6379),
            (float)($config['timeout'] ?? 1.0)
        );
        if ($connected === false) {
            error_log('Redis connect failed: ' . ($client->errMsg ?? 'unknown error'));
            return null;
        }
        $auth = $config['auth'] ?? null;
        if ($auth) {
            if ($client->auth((string)$auth) === false) {
                error_log('Redis auth failed: ' . ($client->errMsg ?? 'unknown error'));
                return null;
            }
        }
        $db = $config['db'] ?? null;
        if ($db !== null) {
            $client->select((int)$db);
        }
        self::$connections[$cid] = $client;
        return $client;
    }
}

class RedisCache
{
    public static function get(string $key): ?string
    {
        $redis = RedisPool::connection();
        if (!$redis) {
            return null;
        }
        $value = $redis->get($key);
        if ($value === false || $value === null) {
            return null;
        }
        return is_string($value) ? $value : (string)$value;
    }

    public static function set(string $key, string $value, int $ttl = 0): void
    {
        $redis = RedisPool::connection();
        if (!$redis) {
            return;
        }
        if ($ttl > 0) {
            $redis->setex($key, $ttl, $value);
            return;
        }
        $redis->set($key, $value);
    }

    public static function del(string $key): void
    {
        $redis = RedisPool::connection();
        if (!$redis) {
            return;
        }
        $redis->del($key);
    }

    public static function deleteByPrefix(string $prefix): void
    {
        $redis = RedisPool::connection();
        if (!$redis) {
            return;
        }
        $keys = $redis->keys($prefix . '*');
        if (!is_array($keys) || $keys === []) {
            return;
        }
        foreach ($keys as $key) {
            $redis->del((string)$key);
        }
    }
}

class DbStatement
{
    private ?\Swoole\Coroutine\MySQL\Statement $stmt = null;
    private array $bound = [];
    private array $rows = [];
    private int $index = 0;
    private int $affected = 0;

    public function __construct(private MySQL $conn, private string $sql, array $rows = [])
    {
        $this->rows = $rows;
        $this->affected = count($rows);
    }

    public function bindValue(string $key, mixed $value, mixed $type = null): void
    {
        if (str_starts_with($key, ':')) {
            $key = substr($key, 1);
        }
        $this->bound[$key] = $value;
    }

    public function execute(array $params = []): bool
    {
        if (empty($params)) {
            $params = $this->bound;
        }
        $this->stmt = $this->prepareStatement($params);
        $result = $this->stmt->execute($params);
        $this->rows = is_array($result) ? $result : [];
        $this->index = 0;
        $this->affected = $this->conn->affected_rows ?? count($this->rows);
        return $result !== false;
    }

    public function fetch(): array|false
    {
        if ($this->stmt === null) {
            return false;
        }
        if (!isset($this->rows[$this->index])) {
            return false;
        }
        return $this->rows[$this->index++];
    }

    public function fetchAll(): array
    {
        if ($this->stmt === null) {
            return $this->rows;
        }
        return $this->rows;
    }

    public function fetchColumn(): mixed
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        foreach ($row as $value) {
            return $value;
        }
        return false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }

    private function prepareStatement(array &$params): \Swoole\Coroutine\MySQL\Statement
    {
        if (array_is_list($params)) {
            $stmt = $this->conn->prepare($this->sql);
            return $stmt;
        }

        [$sql, $ordered] = $this->normalizeNamedParams($this->sql, $params);
        $params = $ordered;
        $stmt = $this->conn->prepare($sql);
        return $stmt;
    }

    private function normalizeNamedParams(string $sql, array $params): array
    {
        $ordered = [];
        $sql = preg_replace_callback('/:[a-zA-Z_][a-zA-Z0-9_]*/', function ($matches) use (&$ordered, $params) {
            $key = substr($matches[0], 1);
            $ordered[] = $params[$key] ?? null;
            return '?';
        }, $sql);
        if (!is_string($sql)) {
            $sql = '';
        }
        return [$sql, $ordered];
    }
}

class DbConnection
{
    public function __construct(private MySQL $conn)
    {
    }

    public function exec(string $sql): int
    {
        $result = $this->conn->query($sql);
        if ($result === false) {
            throw new RuntimeException('MySQL exec failed: ' . ($this->conn->error ?? 'unknown error'));
        }
        return $this->conn->affected_rows ?? 0;
    }

    public function query(string $sql): DbStatement
    {
        $result = $this->conn->query($sql);
        if ($result === false) {
            throw new RuntimeException('MySQL query failed: ' . ($this->conn->error ?? 'unknown error'));
        }
        $rows = is_array($result) ? $result : [];
        return new DbStatement($this->conn, $sql, $rows);
    }

    public function prepare(string $sql): DbStatement
    {
        return new DbStatement($this->conn, $sql);
    }

    public function lastInsertId(): int
    {
        $stmt = $this->query('SELECT LAST_INSERT_ID() AS id');
        $row = $stmt->fetch();
        return $row && isset($row['id']) ? (int)$row['id'] : 0;
    }
}

class Database
{
    private DbConnection $pdo;

    public function __construct(string $path = '', bool $migrate = true)
    {
        $this->pdo = new DbConnection(MySqlPool::connection());

        if ($migrate) {
            $this->migrate();
        }
    }

    public function connection(): DbConnection
    {
        return $this->pdo;
    }
    private function migrate(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS projects (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS config (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                value TEXT,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_project_name (project_id, name),
                KEY idx_config_project (project_id),
                CONSTRAINT fk_config_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS urls (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url TEXT NOT NULL,
                url_hash CHAR(40) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_test_at VARCHAR(32),
                last_test_status VARCHAR(32),
                last_error_message TEXT,
                last_aim_score DOUBLE,
                last_errors INT,
                last_unique_errors INT,
                last_unique_contrast_errors INT,
                last_unique_alerts INT,
                last_contrast_errors INT,
                last_alerts INT,
                last_features INT,
                last_structure INT,
                last_aria INT,
                last_total_elements INT,
                last_http_status INT,
                last_report_url TEXT,
                last_credits_remaining INT,
                UNIQUE KEY uniq_project_url (project_id, url_hash),
                KEY idx_urls_project (project_id),
                KEY idx_urls_active (project_id, active),
                CONSTRAINT fk_urls_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS results (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED NOT NULL,
                run_id BIGINT UNSIGNED,
                tested_at VARCHAR(32) NOT NULL,
                viewport_label VARCHAR(64),
                aim_score DOUBLE,
                errors INT,
                unique_errors INT,
                unique_contrast_errors INT,
                unique_alerts INT,
                contrast_errors INT,
                alerts INT,
                features INT,
                structure INT,
                aria INT,
                total_elements INT,
                http_status INT,
                page_title TEXT,
                final_url TEXT,
                report_url TEXT,
                credits_remaining INT,
                analysis_duration DOUBLE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_results_run (project_id, run_id),
                KEY idx_results_project (project_id),
                KEY idx_results_url (project_id, url_id),
                KEY idx_results_tested (project_id, tested_at),
                CONSTRAINT fk_results_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_results_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                initiated_at VARCHAR(32) NOT NULL,
                mode VARCHAR(32) NOT NULL,
                viewports TEXT,
                url_count INT NOT NULL DEFAULT 0,
                KEY idx_audit_runs_project (project_id),
                KEY idx_audit_runs_initiated (project_id, initiated_at),
                CONSTRAINT fk_audit_runs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                params TEXT,
                error_message TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME,
                finished_at DATETIME,
                KEY idx_queue_project (project_id),
                KEY idx_queue_status (project_id, status),
                CONSTRAINT fk_queue_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_queue_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS errors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED,
                url TEXT,
                viewport_label VARCHAR(64),
                context TEXT NOT NULL,
                message TEXT NOT NULL,
                job_id BIGINT UNSIGNED,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_errors_project (project_id),
                KEY idx_errors_url (project_id, url_id),
                KEY idx_errors_created (project_id, created_at),
                CONSTRAINT fk_errors_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_errors_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS issue_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED NOT NULL,
                viewport_label VARCHAR(64),
                item_id VARCHAR(128) NOT NULL,
                category VARCHAR(64) NOT NULL,
                description TEXT,
                count INT NOT NULL,
                tested_at VARCHAR(32) NOT NULL,
                KEY idx_issue_items_project (project_id),
                KEY idx_issue_items_url (project_id, url_id),
                KEY idx_issue_items_item (project_id, item_id),
                KEY idx_issue_items_viewport (project_id, viewport_label),
                CONSTRAINT fk_issue_items_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_items_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS selectors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                selector TEXT NOT NULL,
                selector_hash CHAR(40) NOT NULL UNIQUE,
                KEY idx_selectors_hash (selector_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS issue_elements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED NOT NULL,
                viewport_label VARCHAR(64),
                item_id VARCHAR(128) NOT NULL,
                category VARCHAR(64) NOT NULL,
                description TEXT,
                selector TEXT,
                selector_id BIGINT UNSIGNED,
                contrast_ratio DOUBLE,
                foreground_color VARCHAR(16),
                background_color VARCHAR(16),
                large_text TINYINT(1),
                tested_at VARCHAR(32) NOT NULL,
                KEY idx_issue_elements_project (project_id),
                KEY idx_issue_elements_url (project_id, url_id),
                KEY idx_issue_elements_item (project_id, item_id),
                KEY idx_issue_elements_selector (project_id, selector_id),
                KEY idx_issue_elements_selector_cat (project_id, category, selector_id),
                KEY idx_issue_elements_viewport (project_id, viewport_label),
                CONSTRAINT fk_issue_elements_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_elements_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS issue_docs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id VARCHAR(128) NOT NULL UNIQUE,
                payload MEDIUMTEXT NOT NULL,
                fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS issue_suppressions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                item_id VARCHAR(128) NOT NULL,
                category VARCHAR(64) NOT NULL,
                reason TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_issue_suppression (project_id, item_id, category),
                KEY idx_issue_suppressions_project (project_id),
                CONSTRAINT fk_issue_suppressions_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS issue_suppression_elements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED NOT NULL,
                viewport_label VARCHAR(64),
                item_id VARCHAR(128) NOT NULL,
                category VARCHAR(64) NOT NULL,
                selector TEXT,
                reason TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_issue_suppression_elements (project_id, url_id, viewport_label, item_id, category, selector(190)),
                KEY idx_issue_suppress_elements_project (project_id),
                KEY idx_issue_suppress_elements_url (project_id, url_id),
                KEY idx_issue_suppress_elements_item (project_id, item_id),
                KEY idx_issue_suppress_elements_category (project_id, category),
                CONSTRAINT fk_issue_suppression_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_suppression_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS tags (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(128) NOT NULL,
                UNIQUE KEY uniq_tags_project (project_id, name),
                KEY idx_tags_project (project_id),
                CONSTRAINT fk_tags_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS url_tags (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                url_id BIGINT UNSIGNED NOT NULL,
                tag_id BIGINT UNSIGNED NOT NULL,
                UNIQUE KEY uniq_url_tags (project_id, url_id),
                KEY idx_url_tags_project (project_id),
                KEY idx_url_tags_url (project_id, url_id),
                CONSTRAINT fk_url_tags_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_url_tags_url FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE,
                CONSTRAINT fk_url_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS viewports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(64) NOT NULL,
                width INT NOT NULL,
                eval_delay INT NOT NULL DEFAULT 250,
                user_agent TEXT,
                UNIQUE KEY uniq_viewports_project (project_id, label),
                KEY idx_viewports_project (project_id),
                CONSTRAINT fk_viewports_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS metrics_cache (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                cache_key VARCHAR(255) NOT NULL,
                errors INT NOT NULL,
                contrast INT NOT NULL,
                alerts INT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_metrics_cache (project_id, cache_key),
                KEY idx_metrics_cache_project (project_id),
                CONSTRAINT fk_metrics_cache_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

}

class MainDatabase extends Database
{
}

class ProjectRepository
{
    public function __construct(private DbConnection $pdo) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM projects ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name, string $slug): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (name, slug) VALUES (:name, :slug)'
        );
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
        ]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) {
            return (int)$row['id'];
        }
        $select = $this->pdo->prepare('SELECT id FROM projects WHERE slug = :slug');
        $select->execute(['slug' => $slug]);
        $id = $select->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }

    public function upsert(string $name, string $slug): int
    {
        $existing = $this->findBySlug($slug);
        if ($existing) {
            return (int)$existing['id'];
        }
        return $this->create($name, $slug);
    }

    public function update(int $id, string $name, string $slug): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE projects SET name = :name, slug = :slug WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

class UrlRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function list(
        string $search = null,
        string $sort = 'created_at',
        string $direction = 'DESC',
        string $tag = null,
        array $viewports = []
    ): array
    {
        $allowedSort = [
            'url' => 'u.url',
            'last_aim_score' => 'COALESCE(r.aim_score, u.last_aim_score)',
            'last_errors' => 'COALESCE(r.errors, u.last_errors)',
            'last_contrast_errors' => 'COALESCE(r.contrast_errors, u.last_contrast_errors)',
            'last_alerts' => 'COALESCE(r.alerts, u.last_alerts)',
            'last_test_at' => 'COALESCE(r.tested_at, u.last_test_at)',
            'created_at' => 'u.created_at',
        ];
        $sortColumn = $allowedSort[$sort] ?? $allowedSort['created_at'];
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $order = $sortColumn . ' ' . $dir;

        $params = ['project_id' => $this->projectId];
        $conditions = ['u.project_id = :project_id'];
        if ($search) {
            $conditions[] = 'u.url LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        if ($tag) {
            $conditions[] = 't.name = :tag';
            $params['tag'] = $tag;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $resultJoin = '';
        if (!empty($viewports)) {
            $placeholders = [];
            foreach ($viewports as $idx => $viewport) {
                $key = 'vp' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $viewport;
            }
            $placeholderList = implode(',', $placeholders);
            $resultJoin = "LEFT JOIN results r ON r.id = (
                SELECT r2.id
                FROM results r2
                WHERE r2.url_id = u.id
                  AND r2.project_id = u.project_id
                  AND r2.viewport_label IN ($placeholderList)
                ORDER BY r2.tested_at DESC
                LIMIT 1
            )";
        } else {
            $resultJoin = "LEFT JOIN results r ON r.id = (
                SELECT r2.id
                FROM results r2
                WHERE r2.url_id = u.id
                  AND r2.project_id = u.project_id
                ORDER BY r2.tested_at DESC
                LIMIT 1
            )";
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                u.*,
                r.tested_at AS latest_tested_at,
                r.aim_score AS latest_aim_score,
                r.errors AS latest_errors,
                r.unique_errors AS latest_unique_errors,
                r.unique_contrast_errors AS latest_unique_contrast_errors,
                r.unique_alerts AS latest_unique_alerts,
                r.contrast_errors AS latest_contrast_errors,
                r.alerts AS latest_alerts,
                r.features AS latest_features,
                r.structure AS latest_structure,
                r.aria AS latest_aria,
                r.total_elements AS latest_total_elements,
                r.http_status AS latest_http_status,
                r.report_url AS latest_report_url,
                r.credits_remaining AS latest_credits_remaining,
                r.viewport_label AS latest_viewport_label,
                GROUP_CONCAT(t.name, ',') AS tags_csv
             FROM urls u
             $resultJoin
             LEFT JOIN url_tags ut ON u.id = ut.url_id AND ut.project_id = u.project_id
             LEFT JOIN tags t ON ut.tag_id = t.id AND t.project_id = u.project_id
             $where
             GROUP BY u.id
             ORDER BY $order"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map(function ($row) {
            if (!empty($row['latest_tested_at'])) {
                $row['last_test_at'] = $row['latest_tested_at'];
                $row['last_aim_score'] = $row['latest_aim_score'];
                $row['last_errors'] = $row['latest_errors'];
                $row['last_unique_errors'] = $row['latest_unique_errors'];
                $row['last_unique_contrast_errors'] = $row['latest_unique_contrast_errors'];
                $row['last_unique_alerts'] = $row['latest_unique_alerts'];
                $row['last_contrast_errors'] = $row['latest_contrast_errors'];
                $row['last_alerts'] = $row['latest_alerts'];
                $row['last_features'] = $row['latest_features'];
                $row['last_structure'] = $row['latest_structure'];
                $row['last_aria'] = $row['latest_aria'];
                $row['last_total_elements'] = $row['latest_total_elements'];
                $row['last_http_status'] = $row['latest_http_status'];
                $row['last_report_url'] = $row['latest_report_url'];
                $row['last_credits_remaining'] = $row['latest_credits_remaining'];
                $row['last_viewport_label'] = $row['latest_viewport_label'];
            }
            $tags = [];
            if (!empty($row['tags_csv'])) {
                $tags = array_values(array_filter(array_map('trim', explode(',', $row['tags_csv']))));
            }
            $row['tags'] = $tags;
            unset($row['tags_csv']);
            unset(
                $row['latest_tested_at'],
                $row['latest_aim_score'],
                $row['latest_errors'],
                $row['latest_unique_errors'],
                $row['latest_unique_contrast_errors'],
                $row['latest_unique_alerts'],
                $row['latest_contrast_errors'],
                $row['latest_alerts'],
                $row['latest_features'],
                $row['latest_structure'],
                $row['latest_aria'],
                $row['latest_total_elements'],
                $row['latest_http_status'],
                $row['latest_report_url'],
                $row['latest_credits_remaining'],
                $row['latest_viewport_label']
            );
            return $row;
        }, $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM urls WHERE id = :id AND project_id = :project_id');
        $stmt->execute([
            'id' => $id,
            'project_id' => $this->projectId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $url, bool $active = true): int
    {
        $hash = sha1($url);
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO urls (project_id, url, url_hash, active) VALUES (:project_id, :url, :url_hash, :active)'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'url' => $url,
            'url_hash' => $hash,
            'active' => $active ? 1 : 0,
        ]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) {
            return (int)$row['id'];
        }
        $select = $this->pdo->prepare('SELECT id FROM urls WHERE project_id = :project_id AND url_hash = :url_hash');
        $select->execute([
            'project_id' => $this->projectId,
            'url_hash' => $hash,
        ]);
        $id = $select->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }

    public function update(int $id, string $url, bool $active): void
    {
        $hash = sha1($url);
        $stmt = $this->pdo->prepare(
            'UPDATE urls SET url = :url, url_hash = :url_hash, active = :active WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute([
            'url' => $url,
            'url_hash' => $hash,
            'active' => $active ? 1 : 0,
            'id' => $id,
            'project_id' => $this->projectId,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM urls WHERE id = :id AND project_id = :project_id');
        $stmt->execute([
            'id' => $id,
            'project_id' => $this->projectId,
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE urls SET active = :active WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute([
            'active' => $active ? 1 : 0,
            'id' => $id,
            'project_id' => $this->projectId,
        ]);
    }

    public function importCsv(string $csvContent): array
    {
        $rows = [];
        $handle = fopen('php://memory', 'rw');
        fwrite($handle, $csvContent);
        rewind($handle);

        while (($data = fgetcsv($handle)) !== false) {
            if (empty($data[0])) {
                continue;
            }
            $url = trim($data[0]);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $rows[] = ['url' => $url, 'status' => 'invalid'];
                continue;
            }
            $id = $this->create($url, true);
            $rows[] = ['url' => $url, 'status' => $id > 0 ? 'imported' : 'duplicate'];
        }

        fclose($handle);
        return $rows;
    }

    public function updateLastSummary(int $id, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE urls SET
                last_test_at = :tested_at,
                last_test_status = :test_status,
                last_error_message = :error_message,
                last_aim_score = :aim_score,
                last_errors = :errors,
                last_unique_errors = :unique_errors,
                last_unique_contrast_errors = :unique_contrast_errors,
                last_unique_alerts = :unique_alerts,
                last_contrast_errors = :contrast_errors,
                last_alerts = :alerts,
                last_features = :features,
                last_structure = :structure,
                last_aria = :aria,
                last_total_elements = :total_elements,
                last_http_status = :http_status,
                last_report_url = :report_url,
                last_credits_remaining = :credits_remaining
            WHERE id = :id AND project_id = :project_id'
        );

        $stmt->execute([
            'tested_at' => $summary['tested_at'] ?? null,
            'test_status' => $summary['test_status'] ?? 'ok',
            'error_message' => $summary['error_message'] ?? null,
            'aim_score' => $summary['aim_score'] ?? null,
            'errors' => $summary['errors'] ?? null,
            'unique_errors' => $summary['unique_errors'] ?? null,
            'unique_contrast_errors' => $summary['unique_contrast_errors'] ?? null,
            'unique_alerts' => $summary['unique_alerts'] ?? null,
            'contrast_errors' => $summary['contrast_errors'] ?? null,
            'alerts' => $summary['alerts'] ?? null,
            'features' => $summary['features'] ?? null,
            'structure' => $summary['structure'] ?? null,
            'aria' => $summary['aria'] ?? null,
            'total_elements' => $summary['total_elements'] ?? null,
            'http_status' => $summary['http_status'] ?? null,
            'report_url' => $summary['report_url'] ?? null,
            'credits_remaining' => $summary['credits_remaining'] ?? null,
            'id' => $id,
            'project_id' => $this->projectId,
        ]);
    }

    public function updateLastError(int $id, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE urls SET
                last_test_at = CURRENT_TIMESTAMP,
                last_test_status = :test_status,
                last_error_message = :error_message
             WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute([
            'test_status' => 'failed',
            'error_message' => $message,
            'id' => $id,
            'project_id' => $this->projectId,
        ]);
    }

    public function updateLastCountsFromIssueItems(int $urlId, string $viewportLabel, int $errors, int $contrastErrors, int $alerts): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE urls
             SET last_errors = :errors,
                 last_contrast_errors = :contrast_errors,
                 last_alerts = :alerts
             WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute([
            'errors' => $errors,
            'contrast_errors' => $contrastErrors,
            'alerts' => $alerts,
            'id' => $urlId,
            'project_id' => $this->projectId,
        ]);
    }
}

class TagRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->prepare('SELECT name FROM tags WHERE project_id = :project_id ORDER BY name ASC');
        $stmt->execute(['project_id' => $this->projectId]);
        $rows = $stmt->fetchAll();
        $values = [];
        foreach ($rows as $row) {
            $values[] = (string)reset($row);
        }
        return $values;
    }

    public function tagsForUrl(int $urlId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.name
             FROM tags t
             JOIN url_tags ut ON ut.tag_id = t.id
             WHERE ut.url_id = :url_id AND ut.project_id = :project_id AND t.project_id = :project_id
             ORDER BY t.name ASC'
        );
        $stmt->execute([
            'url_id' => $urlId,
            'project_id' => $this->projectId,
        ]);
        $rows = $stmt->fetchAll();
        $values = [];
        foreach ($rows as $row) {
            $values[] = (string)reset($row);
        }
        return $values;
    }

    public function setTagsForUrl(int $urlId, array $tags): void
    {
        $clean = [];
        foreach ($tags as $tag) {
            $name = trim((string)$tag);
            if ($name === '') {
                continue;
            }
            $clean[$name] = true;
        }
        $tags = array_keys($clean);

        $this->pdo->prepare('DELETE FROM url_tags WHERE url_id = :url_id AND project_id = :project_id')
            ->execute(['url_id' => $urlId, 'project_id' => $this->projectId]);
        if (empty($tags)) {
            return;
        }

        $insertTag = $this->pdo->prepare(
            'INSERT IGNORE INTO tags (project_id, name) VALUES (:project_id, :name)'
        );
        $insertLink = $this->pdo->prepare(
            'INSERT IGNORE INTO url_tags (project_id, url_id, tag_id)
             SELECT :project_id, :url_id, id FROM tags WHERE project_id = :project_id AND name = :name'
        );

        foreach ($tags as $name) {
            $insertTag->execute(['project_id' => $this->projectId, 'name' => $name]);
            $insertLink->execute([
                'project_id' => $this->projectId,
                'url_id' => $urlId,
                'name' => $name,
            ]);
        }
    }
}

class ViewportRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT label, viewport_width, eval_delay, user_agent
             FROM viewports
             WHERE project_id = :project_id
             ORDER BY label ASC'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        $rows = $stmt->fetchAll();
        return $rows ?: [];
    }

    public function listAllForScan(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT label, viewport_width, eval_delay, user_agent
             FROM viewports
             WHERE project_id = :project_id
             ORDER BY label ASC'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        $rows = $stmt->fetchAll();
        return $rows ?: [];
    }

    public function listAllLabels(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT label FROM viewports WHERE project_id = :project_id ORDER BY label ASC'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        $rows = $stmt->fetchAll();
        $values = [];
        foreach ($rows as $row) {
            $values[] = (string)reset($row);
        }
        return $values;
    }

    public function upsert(array $data): void
    {
        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Viewport label is required');
        }
        $viewportWidth = $this->normalizeInt($data['viewport_width'] ?? null);
        $evalDelay = $this->normalizeInt($data['eval_delay'] ?? null);
        $scanEnabled = array_key_exists('scan_enabled', $data) ? (!empty($data['scan_enabled']) ? 1 : 0) : 1;
        $reportEnabled = array_key_exists('report_enabled', $data) ? (!empty($data['report_enabled']) ? 1 : 0) : 1;
        $stmt = $this->pdo->prepare(
            'INSERT INTO viewports (project_id, label, viewport_width, eval_delay, user_agent, scan_enabled, report_enabled)
             VALUES (:project_id, :label, :viewport_width, :eval_delay, :user_agent, :scan_enabled, :report_enabled)
             ON DUPLICATE KEY UPDATE
                viewport_width = VALUES(viewport_width),
                eval_delay = VALUES(eval_delay),
                user_agent = VALUES(user_agent),
                scan_enabled = VALUES(scan_enabled),
                report_enabled = VALUES(report_enabled)'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'label' => $label,
            'viewport_width' => $viewportWidth,
            'eval_delay' => $evalDelay,
            'user_agent' => isset($data['user_agent']) && $data['user_agent'] !== '' ? (string)$data['user_agent'] : null,
            'scan_enabled' => $scanEnabled,
            'report_enabled' => $reportEnabled,
        ]);
    }

    public function delete(string $label): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM viewports WHERE label = :label AND project_id = :project_id');
        $stmt->execute([
            'label' => $label,
            'project_id' => $this->projectId,
        ]);
    }

    public function ensureDefault(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM viewports WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $this->projectId]);
        $count = (int)$stmt->fetchColumn();
        if ($count === 0) {
            $this->upsert([
                'label' => 'default',
                'viewport_width' => 1200,
                'eval_delay' => 250,
                'user_agent' => null,
                'scan_enabled' => true,
                'report_enabled' => true,
            ]);
        }
    }

    private function normalizeInt($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return null;
        }
        return is_numeric($trimmed) ? (int)$trimmed : null;
    }
}

class ResultRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function record(int $urlId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO results (
                project_id, url_id, run_id, tested_at, viewport_label, aim_score, errors, unique_errors, unique_contrast_errors, unique_alerts, contrast_errors, alerts,
                features, structure, aria, total_elements, http_status, page_title,
                final_url, report_url, credits_remaining, analysis_duration
            ) VALUES (
                :project_id, :url_id, :run_id, :tested_at, :viewport_label, :aim_score, :errors, :unique_errors, :unique_contrast_errors, :unique_alerts, :contrast_errors, :alerts,
                :features, :structure, :aria, :total_elements, :http_status, :page_title,
                :final_url, :report_url, :credits_remaining, :analysis_duration
            )'
        );

        $stmt->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'run_id' => $data['run_id'] ?? null,
            'tested_at' => $data['tested_at'],
            'viewport_label' => $data['viewport_label'] ?? null,
            'aim_score' => $data['aim_score'],
            'errors' => $data['errors'],
            'unique_errors' => $data['unique_errors'] ?? null,
            'unique_contrast_errors' => $data['unique_contrast_errors'] ?? null,
            'unique_alerts' => $data['unique_alerts'] ?? null,
            'contrast_errors' => $data['contrast_errors'],
            'alerts' => $data['alerts'],
            'features' => $data['features'],
            'structure' => $data['structure'],
            'aria' => $data['aria'],
            'total_elements' => $data['total_elements'],
            'http_status' => $data['http_status'],
            'page_title' => $data['page_title'],
            'final_url' => $data['final_url'],
            'report_url' => $data['report_url'],
            'credits_remaining' => $data['credits_remaining'],
            'analysis_duration' => $data['analysis_duration'],
        ]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) {
            return (int)$row['id'];
        }
        $select = $this->pdo->prepare(
            'SELECT id FROM results WHERE project_id = :project_id AND url_id = :url_id AND tested_at = :tested_at ORDER BY id DESC LIMIT 1'
        );
        $select->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'tested_at' => $data['tested_at'],
        ]);
        $id = $select->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }

    public function historyForUrl(int $urlId, int $limit = 50, array $viewports = []): array
    {
        $sql = 'SELECT * FROM results WHERE project_id = ? AND url_id = ?';
        if (!empty($viewports)) {
            $sql .= ' AND viewport_label IN (' . implode(',', array_fill(0, count($viewports), '?')) . ')';
        }
        $sql .= ' ORDER BY tested_at DESC LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        $params = [$this->projectId, $urlId];
        if (!empty($viewports)) {
            $params = array_merge($params, array_values($viewports));
        }
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function trends(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tested_at, aim_score, errors, unique_errors, unique_contrast_errors, unique_alerts, contrast_errors, alerts
             FROM results
             WHERE project_id = :project_id
             ORDER BY tested_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':project_id', $this->projectId);
        $stmt->bindValue(':limit', $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateCountsForResult(int $urlId, string $viewportLabel, string $testedAt, int $errors, int $contrastErrors, int $alerts): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE results
             SET errors = :errors,
                 contrast_errors = :contrast_errors,
                 alerts = :alerts
             WHERE project_id = :project_id AND url_id = :url_id AND viewport_label = :viewport_label AND tested_at = :tested_at'
        );
        $stmt->execute([
            'errors' => $errors,
            'contrast_errors' => $contrastErrors,
            'alerts' => $alerts,
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'viewport_label' => $viewportLabel,
            'tested_at' => $testedAt,
        ]);
    }
}

class ConfigRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function set(string $name, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO config (project_id, name, value) VALUES (:project_id, :name, :value)
            ON DUPLICATE KEY UPDATE value = :value');
        $stmt->execute([
            'project_id' => $this->projectId,
            'name' => $name,
            'value' => $value,
        ]);
    }

    public function get(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM config WHERE project_id = :project_id AND name = :name');
        $stmt->execute([
            'project_id' => $this->projectId,
            'name' => $name,
        ]);
        $row = $stmt->fetchColumn();
        return $row === false ? null : (string)$row;
    }
}

class AuditRunRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function create(string $mode, array $viewports, int $urlCount): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_runs (project_id, initiated_at, mode, viewports, url_count)
             VALUES (:project_id, :initiated_at, :mode, :viewports, :url_count)'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'initiated_at' => date('c'),
            'mode' => $mode,
            'viewports' => json_encode(array_values($viewports)),
            'url_count' => $urlCount,
        ]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) {
            return (int)$row['id'];
        }
        $select = $this->pdo->prepare(
            'SELECT id FROM audit_runs WHERE project_id = :project_id ORDER BY initiated_at DESC LIMIT 1'
        );
        $select->execute(['project_id' => $this->projectId]);
        $id = $select->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }
}

class IssueRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId, private array $suppressedItems = [])
    {
    }

    public function updateSuppressed(array $suppressed): void
    {
        $this->suppressedItems = $suppressed;
    }

    private function isSuppressed(string $itemId, string $category): bool
    {
        if ($this->suppressedItems === []) {
            return false;
        }
        $key = strtolower($category) . '|' . strtolower($itemId);
        return isset($this->suppressedItems[$key]);
    }

    private function suppressionElementClause(string $alias): string
    {
        return "NOT EXISTS (
            SELECT 1 FROM issue_suppression_elements se
            WHERE se.project_id = {$this->projectId}
              AND se.item_id = {$alias}.item_id
              AND se.category = {$alias}.category
              AND se.selector = {$alias}.selector
              AND (se.viewport_label IS NULL OR se.viewport_label = {$alias}.viewport_label)
        )";
    }

    private function latestResultsJoin(string $alias, array $viewports = [], ?int $urlId = null): array
    {
        $params = [];
        $whereParts = ['project_id = :latest_project_id'];
        $params['latest_project_id'] = $this->projectId;
        if ($urlId !== null) {
            $whereParts[] = 'url_id = :latest_url_id';
            $params['latest_url_id'] = $urlId;
        }
        if (!empty($viewports)) {
            $placeholders = [];
            foreach ($viewports as $idx => $vp) {
                $key = 'latest_vp' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $vp;
            }
            $whereParts[] = 'viewport_label IN (' . implode(',', $placeholders) . ')';
        }
        $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
        $sql = "JOIN (
            SELECT url_id, viewport_label, MAX(tested_at) AS tested_at
            FROM results
            $where
            GROUP BY url_id, viewport_label
        ) latest ON latest.url_id = {$alias}.url_id
            AND latest.viewport_label = {$alias}.viewport_label
            AND latest.tested_at = {$alias}.tested_at";
        return ['sql' => $sql, 'params' => $params];
    }

    private function hasElements(array $viewports = []): bool
    {
        $sql = 'SELECT 1 FROM issue_elements WHERE project_id = :project_id';
        $params = ['project_id' => $this->projectId];
        if (!empty($viewports)) {
            $placeholders = [];
            foreach ($viewports as $idx => $vp) {
                $key = 'vp' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $vp;
            }
            $sql .= ' AND viewport_label IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function hasElementsForUrl(int $urlId, array $viewports = []): bool
    {
        $sql = 'SELECT 1 FROM issue_elements WHERE project_id = :project_id AND url_id = :url_id';
        $params = [
            'project_id' => $this->projectId,
            'url_id' => $urlId,
        ];
        if (!empty($viewports)) {
            $placeholders = [];
            foreach ($viewports as $idx => $vp) {
                $key = 'vp_url' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $vp;
            }
            $sql .= ' AND viewport_label IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function saveForUrlTest(int $urlId, string $testedAt, array $categories, int $reportType, string $viewportLabel): void
    {
        $this->pdo->prepare(
            'DELETE FROM issue_items WHERE project_id = :project_id AND url_id = :url_id AND tested_at = :tested_at'
        )->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'tested_at' => $testedAt,
        ]);
        $this->pdo->prepare(
            'DELETE FROM issue_elements WHERE project_id = :project_id AND url_id = :url_id AND tested_at = :tested_at'
        )->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'tested_at' => $testedAt,
        ]);
        if ($reportType < 2) {
            return;
        }

        $itemStmt = $this->pdo->prepare(
            'INSERT INTO issue_items (project_id, url_id, viewport_label, item_id, category, description, count, tested_at)
             VALUES (:project_id, :url_id, :viewport_label, :item_id, :category, :description, :count, :tested_at)'
        );

        $elemStmt = $this->pdo->prepare(
            'INSERT INTO issue_elements (
                project_id, url_id, viewport_label, item_id, category, selector_id, selector, tested_at,
                contrast_ratio, foreground_color, background_color, large_text
             ) VALUES (
                :project_id, :url_id, :viewport_label, :item_id, :category, :selector_id, :selector, :tested_at,
                :contrast_ratio, :foreground_color, :background_color, :large_text
             )'
        );
        $selectorRepo = new SelectorRepository($this->pdo);
        $selectorCache = [];

        foreach ($categories as $categoryName => $categoryData) {
            if (empty($categoryData['items']) || !is_array($categoryData['items'])) {
                continue;
            }

            foreach ($categoryData['items'] as $itemId => $itemData) {
                if ($this->isSuppressed((string)$itemId, (string)$categoryName)) {
                    continue;
                }
                $rawCount = (int)($itemData['count'] ?? 0);
                $description = (string)($itemData['description'] ?? '');

                $selectors = [];
                if ($reportType >= 4) {
                    $selectors = $this->normalizeSelectors($itemData['selectors'] ?? null);
                } elseif ($reportType >= 3) {
                    $selectors = $this->normalizeXPaths($itemData['xpaths'] ?? null);
                }
                $contrastData = [];
                if (!empty($itemData['contrastdata']) && is_array($itemData['contrastdata'])) {
                    $contrastData = $itemData['contrastdata'];
                }

                $selectorMap = [];
                foreach ($selectors as $idx => $sel) {
                    $selector = is_string($sel) ? trim($sel) : '';
                    if ($selector === '') {
                        continue;
                    }
                    if (!isset($selectorMap[$selector])) {
                        $selectorMap[$selector] = $contrastData[$idx] ?? null;
                    }
                }

                foreach ($selectorMap as $selector => $contrastRow) {
                    if (!isset($selectorCache[$selector])) {
                        $selectorCache[$selector] = $selectorRepo->getOrCreate($selector);
                    }
                    $selectorId = $selectorCache[$selector];
                    $ratio = null;
                    $fg = null;
                    $bg = null;
                    $large = null;
                    if (is_array($contrastRow) && count($contrastRow) >= 4) {
                        $ratio = is_numeric($contrastRow[0]) ? (float)$contrastRow[0] : null;
                        $fg = (string)$contrastRow[1];
                        $bg = (string)$contrastRow[2];
                        $large = !empty($contrastRow[3]) ? 1 : 0;
                    }

                    if ($reportType >= 3) {
                        $elemStmt->execute([
                            'project_id' => $this->projectId,
                            'url_id' => $urlId,
                            'viewport_label' => $viewportLabel,
                            'item_id' => (string)$itemId,
                            'category' => (string)$categoryName,
                            'selector_id' => $selectorId,
                            'selector' => $selector,
                            'tested_at' => $testedAt,
                            'contrast_ratio' => $ratio,
                            'foreground_color' => $fg,
                            'background_color' => $bg,
                            'large_text' => $large,
                        ]);
                    }
                }

                if ($rawCount <= 0) {
                    continue;
                }

                $itemStmt->execute([
                    'project_id' => $this->projectId,
                    'url_id' => $urlId,
                    'viewport_label' => $viewportLabel,
                    'item_id' => (string)$itemId,
                    'category' => (string)$categoryName,
                    'description' => $description,
                    'count' => $rawCount,
                    'tested_at' => $testedAt,
                ]);
            }
        }
    }

    private function normalizeSelectors($rawSelectors): array
    {
        if ($rawSelectors === null) {
            return [];
        }

        if (is_string($rawSelectors)) {
            $trimmed = trim($rawSelectors);
            return $trimmed === '' ? [] : [$trimmed];
        }

        if (!is_array($rawSelectors)) {
            return [];
        }

        if (array_key_exists('selector', $rawSelectors)) {
            $rawSelectors = $rawSelectors['selector'];
        }

        $selectors = [];
        if (is_array($rawSelectors)) {
            foreach ($rawSelectors as $entry) {
                if (is_string($entry)) {
                    $trimmed = trim($entry);
                    if ($trimmed !== '') {
                        $selectors[] = $trimmed;
                    }
                    continue;
                }
                if (is_array($entry) && isset($entry['selector']) && is_string($entry['selector'])) {
                    $trimmed = trim($entry['selector']);
                    if ($trimmed !== '') {
                        $selectors[] = $trimmed;
                    }
                }
            }
            return $selectors;
        }

        if (is_string($rawSelectors)) {
            $trimmed = trim($rawSelectors);
            if ($trimmed !== '') {
                $selectors[] = $trimmed;
            }
        }

        return $selectors;
    }

    private function normalizeXPaths($rawXPaths): array
    {
        if ($rawXPaths === null) {
            return [];
        }

        if (is_string($rawXPaths)) {
            $trimmed = trim($rawXPaths);
            return $trimmed === '' ? [] : ['XPath: ' . $trimmed];
        }

        if (!is_array($rawXPaths)) {
            return [];
        }

        if (array_key_exists('xpath', $rawXPaths)) {
            $rawXPaths = $rawXPaths['xpath'];
        }

        $xpaths = [];
        if (is_array($rawXPaths)) {
            foreach ($rawXPaths as $entry) {
                if (is_string($entry)) {
                    $trimmed = trim($entry);
                    if ($trimmed !== '') {
                        $xpaths[] = 'XPath: ' . $trimmed;
                    }
                }
            }
        }

        return $xpaths;
    }

    public function summary(?string $categoryFilter = null, array $viewports = [], bool $includeSuppressed = false): array
    {
        if ($this->hasElements($viewports)) {
            $params = ['project_id' => $this->projectId];
            $whereParts = ['ie.project_id = :project_id'];
            if ($categoryFilter) {
                $whereParts[] = 'ie.category = :category';
                $params['category'] = $categoryFilter;
            }
            if (!$includeSuppressed) {
                $whereParts[] = 'NOT EXISTS (
                    SELECT 1 FROM issue_suppressions s
                    WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                )';
                $whereParts[] = $this->suppressionElementClause('ie');
            }
            $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
            $latest = $this->latestResultsJoin('ie', $viewports);
            $params = array_merge($params, $latest['params']);

            $stmt = $this->pdo->prepare(
                "SELECT
                    ie.item_id,
                    ie.category,
                    COALESCE(MIN(ii.description), '') AS description,
                    COUNT(*) AS total_count,
                    COUNT(DISTINCT ie.url_id) AS url_count,
                    COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector)) AS unique_selectors
                 FROM issue_elements ie
                 {$latest['sql']}
                 LEFT JOIN issue_items ii
                   ON ii.item_id = ie.item_id
                  AND ii.category = ie.category
                  AND ii.url_id = ie.url_id
                  AND ii.viewport_label = ie.viewport_label
                  AND ii.project_id = ie.project_id
                 $where
                 GROUP BY ie.item_id, ie.category
                 ORDER BY ie.category, total_count DESC, url_count DESC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return array_map(function ($row) use ($includeSuppressed) {
                return [
                    'item_id' => $row['item_id'],
                    'category' => $row['category'],
                    'description' => $row['description'],
                    'total_count' => (int)$row['total_count'],
                    'url_count' => (int)$row['url_count'],
                    'unique_selectors' => (int)$row['unique_selectors'],
                    'suppressed' => $includeSuppressed ? $this->isSuppressed($row['item_id'], $row['category']) : false,
                ];
            }, $rows);
        }

        $params = ['project_id' => $this->projectId];
        $whereParts = ['ii.project_id = :project_id'];
        if ($categoryFilter) {
            $whereParts[] = 'ii.category = :category';
            $params['category'] = $categoryFilter;
        }
        if (!$includeSuppressed) {
            $whereParts[] = 'NOT EXISTS (
                SELECT 1 FROM issue_suppressions s
                WHERE s.project_id = :project_id AND s.item_id = ii.item_id AND s.category = ii.category
            )';
        }
        if (!empty($viewports)) {
            $placeholders = [];
            foreach ($viewports as $idx => $vp) {
                $key = 'vp' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $vp;
            }
            $whereParts[] = 'ii.viewport_label IN (' . implode(',', $placeholders) . ')';
        }
        $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

        $stmt = $this->pdo->prepare(
            "SELECT
                ii.item_id,
                ii.category,
                COALESCE(MIN(ii.description), '') AS description,
                SUM(ii.count) AS total_count,
                COUNT(DISTINCT ii.url_id) AS url_count,
                0 AS unique_selectors
             FROM issue_items ii
             $where
             GROUP BY ii.item_id, ii.category
             ORDER BY ii.category, total_count DESC, url_count DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map(function ($row) use ($includeSuppressed) {
            return [
                'item_id' => $row['item_id'],
                'category' => $row['category'],
                'description' => $row['description'],
                'total_count' => (int)$row['total_count'],
                'url_count' => (int)$row['url_count'],
                'unique_selectors' => (int)$row['unique_selectors'],
                'suppressed' => $includeSuppressed ? $this->isSuppressed($row['item_id'], $row['category']) : false,
            ];
        }, $rows);
    }

    public function details(string $itemId, string $category, array $viewports = []): array
    {
        if ($this->isSuppressed($itemId, $category)) {
            return [];
        }
        $latest = $this->latestResultsJoin('ie', $viewports);
        $sql = "
            SELECT
                u.id AS url_id,
                u.url,
                ie.viewport_label,
                ie.selector,
                ie.contrast_ratio,
                ie.foreground_color,
                ie.background_color,
                ie.large_text
            FROM issue_elements ie
            {$latest['sql']}
            JOIN urls u ON u.id = ie.url_id AND u.project_id = ie.project_id
            WHERE ie.project_id = :project_id
              AND ie.item_id = :item_id AND ie.category = :category
              AND " . $this->suppressionElementClause('ie') . "
            ORDER BY u.url, ie.selector
        ";

        $stmt = $this->pdo->prepare($sql);
        $params = array_merge([
            'project_id' => $this->projectId,
            'item_id' => $itemId,
            'category' => $category,
        ], $latest['params']);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        $byUrl = [];
        foreach ($rows as $row) {
            $urlId = (int)$row['url_id'];
            if (!isset($byUrl[$urlId])) {
                $byUrl[$urlId] = [
                    'url_id' => $urlId,
                    'url' => $row['url'],
                    'elements' => [],
                ];
            }
            $byUrl[$urlId]['elements'][] = [
                'selector' => $row['selector'],
                'viewport_label' => $row['viewport_label'] ?? 'default',
                'contrast_ratio' => $row['contrast_ratio'] !== null ? (float)$row['contrast_ratio'] : null,
                'foreground_color' => $row['foreground_color'],
                'background_color' => $row['background_color'],
                'large_text' => $row['large_text'] !== null ? (bool)$row['large_text'] : null,
            ];
        }

        return array_values($byUrl);
    }

    public function detailsForUrlIssue(int $urlId, string $itemId, string $category, array $viewports = []): array
    {
        if ($this->isSuppressed($itemId, $category)) {
            return [];
        }
        $latest = $this->latestResultsJoin('issue_elements', $viewports, $urlId);
        $sql = "
            SELECT
                issue_elements.viewport_label AS viewport_label,
                issue_elements.selector AS selector,
                issue_elements.contrast_ratio AS contrast_ratio,
                issue_elements.foreground_color AS foreground_color,
                issue_elements.background_color AS background_color,
                issue_elements.large_text AS large_text
            FROM issue_elements
            {$latest['sql']}
            WHERE issue_elements.project_id = :project_id
              AND issue_elements.url_id = :url_id
              AND issue_elements.item_id = :item_id
              AND issue_elements.category = :category
              AND " . $this->suppressionElementClause('issue_elements') . "
            ORDER BY issue_elements.selector
        ";
        $stmt = $this->pdo->prepare($sql);
        $params = array_merge(
            ['project_id' => $this->projectId, 'url_id' => $urlId, 'item_id' => $itemId, 'category' => $category],
            $latest['params']
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function summaryForUrl(int $urlId, array $viewports = []): array
    {
        if ($this->hasElementsForUrl($urlId, $viewports)) {
            $latest = $this->latestResultsJoin('ie', $viewports, $urlId);
            $stmt = $this->pdo->prepare(
                "SELECT
                    ie.item_id,
                    ie.category,
                    COALESCE(MIN(ii.description), '') AS description,
                    COUNT(*) AS count,
                    COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector)) AS unique_selectors,
                    ie.tested_at,
                    ie.viewport_label
                 FROM issue_elements ie
                 {$latest['sql']}
                 LEFT JOIN issue_items ii
                   ON ii.item_id = ie.item_id
                  AND ii.category = ie.category
                  AND ii.url_id = ie.url_id
                  AND ii.viewport_label = ie.viewport_label
                  AND ii.tested_at = ie.tested_at
                  AND ii.project_id = ie.project_id
                 WHERE ie.project_id = :project_id
                   AND ie.url_id = :url_id
                   AND NOT EXISTS (
                     SELECT 1 FROM issue_suppressions s
                     WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                   )
                   AND {$this->suppressionElementClause('ie')}
                 GROUP BY ie.item_id, ie.category, ie.tested_at, ie.viewport_label
                 ORDER BY ie.category, count DESC"
            );
            $params = array_merge(['project_id' => $this->projectId, 'url_id' => $urlId], $latest['params']);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare(
            'SELECT item_id, category, description, count, tested_at, viewport_label
             FROM issue_items
             WHERE project_id = ?
               AND url_id = ?
             ' . (empty($viewports) ? '' : ' AND viewport_label IN (' . implode(',', array_fill(0, count($viewports), '?')) . ')') . '
             ORDER BY category, count DESC'
        );
        $params = [$this->projectId, $urlId];
        if (!empty($viewports)) {
            $params = array_merge($params, array_values($viewports));
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map(function ($row) {
            $row['unique_selectors'] = 0;
            return $row;
        }, $rows);
    }

    public function countGlobalUniqueByCategory(string $category, array $viewports = []): int
    {
        $latest = $this->latestResultsJoin('issue_elements', $viewports);
        $sql = "SELECT COUNT(DISTINCT COALESCE(selector_id, selector))
                FROM issue_elements
                {$latest['sql']}
                WHERE project_id = :project_id
                  AND category = :category
                  AND NOT EXISTS (
                    SELECT 1 FROM issue_suppressions s
                    WHERE s.project_id = :project_id AND s.item_id = issue_elements.item_id AND s.category = issue_elements.category
                  )";
        $params = ['project_id' => $this->projectId, 'category' => $category];
        $params = array_merge($params, $latest['params']);
        $sql .= ' AND ' . $this->suppressionElementClause('issue_elements');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false ? 0 : (int)$val;
    }

    public function pagesForIssue(string $itemId, string $category, array $viewports = []): array
    {
        if ($this->isSuppressed($itemId, $category)) {
            return [];
        }
        if ($this->hasElements($viewports)) {
            $latest = $this->latestResultsJoin('ie', $viewports);
            $sql = "
                SELECT
                    u.id AS url_id,
                    u.url,
                    u.last_report_url,
                    COUNT(*) AS count
                FROM issue_elements ie
                {$latest['sql']}
                JOIN urls u ON u.id = ie.url_id AND u.project_id = ie.project_id
                WHERE ie.project_id = :project_id
                  AND ie.item_id = :item_id AND ie.category = :category
                  AND " . $this->suppressionElementClause('ie') . "
                GROUP BY u.id, u.url, u.last_report_url
                ORDER BY count DESC
            ";
            $stmt = $this->pdo->prepare($sql);
            $params = array_merge(
                ['project_id' => $this->projectId, 'item_id' => $itemId, 'category' => $category],
                $latest['params']
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        $sql = "
            SELECT
                u.id AS url_id,
                u.url,
                u.last_report_url,
                COALESCE(SUM(ii.count), 0) AS count
            FROM issue_items ii
            JOIN urls u ON u.id = ii.url_id AND u.project_id = ii.project_id
            WHERE ii.project_id = ? AND ii.item_id = ? AND ii.category = ?
            " . (empty($viewports) ? "" : " AND ii.viewport_label IN (" . implode(',', array_fill(0, count($viewports), '?')) . ")") . "
            GROUP BY u.id, u.url, u.last_report_url
            ORDER BY count DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $params = [$this->projectId, $itemId, $category];
        if (!empty($viewports)) {
            $params = array_merge($params, array_values($viewports));
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function pagesForAllIssues(?string $category = null, array $viewports = [], bool $includeSuppressed = false): array
    {
        if ($this->hasElements($viewports)) {
            $latest = $this->latestResultsJoin('ie', $viewports);
            $conditions = [];
            $params = array_merge(['project_id' => $this->projectId], $latest['params']);
            $conditions[] = 'ie.project_id = :project_id';
            if ($category) {
                $conditions[] = 'ie.category = :category';
                $params['category'] = $category;
            }
            if (!$includeSuppressed) {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category)';
                $conditions[] = $this->suppressionElementClause('ie');
            }
            $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "
                SELECT
                    ie.item_id,
                    ie.category,
                    COALESCE(MIN(ii.description), '') AS description,
                    u.id AS url_id,
                    u.url,
                    u.last_report_url,
                    COUNT(*) AS count
                FROM issue_elements ie
                {$latest['sql']}
                LEFT JOIN issue_items ii
                    ON ii.item_id = ie.item_id
                    AND ii.category = ie.category
                    AND ii.url_id = ie.url_id
                    AND (ii.viewport_label = ie.viewport_label OR ii.viewport_label IS NULL)
                    AND ii.project_id = ie.project_id
                JOIN urls u ON u.id = ie.url_id AND u.project_id = ie.project_id
                $where
                GROUP BY ie.item_id, ie.category, u.id, u.url, u.last_report_url
                ORDER BY ie.category, count DESC, u.url
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        $conditions = ['ii.project_id = ?'];
        $params = [$this->projectId];
        if ($category) {
            $conditions[] = 'ii.category = ?';
            $params[] = $category;
        }
        if (!empty($viewports)) {
            $conditions[] = 'ii.viewport_label IN (' . implode(',', array_fill(0, count($viewports), '?')) . ')';
            $params = array_merge($params, array_values($viewports));
        }
        if (!$includeSuppressed) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = ? AND s.item_id = ii.item_id AND s.category = ii.category)';
            $params[] = $this->projectId;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                ii.item_id,
                ii.category,
                COALESCE(MIN(ii.description), '') AS description,
                u.id AS url_id,
                u.url,
                u.last_report_url,
                COALESCE(SUM(ii.count), 0) AS count
            FROM issue_items ii
            JOIN urls u ON u.id = ii.url_id AND u.project_id = ii.project_id
            $where
            GROUP BY ii.item_id, ii.category, u.id, u.url, u.last_report_url
            ORDER BY ii.category, count DESC, u.url
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function selectorsForAllIssues(?string $category = null, array $viewports = [], bool $includeSuppressed = false): array
    {
        if (!$this->hasElements($viewports)) {
            return [];
        }
        $latest = $this->latestResultsJoin('ie', $viewports);
        $conditions = ['ie.project_id = :project_id'];
        $params = array_merge(['project_id' => $this->projectId], $latest['params']);
        if ($category) {
            $conditions[] = 'ie.category = :category';
            $params['category'] = $category;
        }
        if (!$includeSuppressed) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category)';
            $conditions[] = $this->suppressionElementClause('ie');
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                ie.item_id,
                ie.category,
                u.url,
                ie.viewport_label,
                ie.selector,
                ie.contrast_ratio,
                ie.foreground_color,
                ie.background_color,
                ie.large_text
            FROM issue_elements ie
            {$latest['sql']}
            JOIN urls u ON u.id = ie.url_id AND u.project_id = ie.project_id
            $where
            ORDER BY ie.category, ie.item_id, u.url, ie.selector
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function summaryFiltered(?string $category, ?string $itemId, array $viewports, bool $includeSuppressed, ?array $urlIds, ?string $selectorLike): array
    {
        $useElements = $this->hasElements($viewports) || $selectorLike !== null;
        if ($useElements) {
            $latest = $this->latestResultsJoin('ie', $viewports);
            $conditions = ['ie.project_id = :project_id'];
            $params = array_merge(['project_id' => $this->projectId], $latest['params']);
            if ($category) {
                $conditions[] = 'ie.category = :category';
                $params['category'] = $category;
            }
            if ($itemId) {
                $conditions[] = 'ie.item_id = :item_id';
                $params['item_id'] = $itemId;
            }
            if ($urlIds !== null) {
                if (empty($urlIds)) {
                    return [];
                }
                $placeholders = [];
                foreach ($urlIds as $idx => $id) {
                    $key = 'url_' . $idx;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $id;
                }
                $conditions[] = 'ie.url_id IN (' . implode(',', $placeholders) . ')';
            }
            if ($selectorLike !== null) {
                $conditions[] = 'ie.selector LIKE :selector_like';
                $params['selector_like'] = '%' . $selectorLike . '%';
            }
            if (!$includeSuppressed) {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category)';
                $conditions[] = $this->suppressionElementClause('ie');
            }
            $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "
                SELECT
                    ie.item_id,
                    ie.category,
                    COALESCE(MIN(ii.description), '') AS description,
                    COUNT(*) AS total_count,
                    COUNT(DISTINCT ie.url_id) AS url_count,
                    COUNT(DISTINCT COALESCE(ie.selector_id, ie.selector)) AS unique_selectors
                FROM issue_elements ie
                {$latest['sql']}
                LEFT JOIN issue_items ii
                  ON ii.item_id = ie.item_id
                 AND ii.category = ie.category
                 AND ii.url_id = ie.url_id
                 AND ii.viewport_label = ie.viewport_label
                 AND ii.tested_at = ie.tested_at
                 AND ii.project_id = ie.project_id
                $where
                GROUP BY ie.item_id, ie.category
                ORDER BY ie.category, total_count DESC, url_count DESC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        if ($selectorLike !== null) {
            return [];
        }
        $conditions = ['ii.project_id = ?'];
        $params = [$this->projectId];
        if ($category) {
            $conditions[] = 'ii.category = ?';
            $params[] = $category;
        }
        if ($itemId) {
            $conditions[] = 'ii.item_id = ?';
            $params[] = $itemId;
        }
        if ($urlIds !== null) {
            if (empty($urlIds)) {
                return [];
            }
            $conditions[] = 'ii.url_id IN (' . implode(',', array_fill(0, count($urlIds), '?')) . ')';
            $params = array_merge($params, array_values($urlIds));
        }
        if (!empty($viewports)) {
            $conditions[] = 'ii.viewport_label IN (' . implode(',', array_fill(0, count($viewports), '?')) . ')';
            $params = array_merge($params, array_values($viewports));
        }
        if (!$includeSuppressed) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = ? AND s.item_id = ii.item_id AND s.category = ii.category)';
            $params[] = $this->projectId;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                ii.item_id,
                ii.category,
                COALESCE(MIN(ii.description), '') AS description,
                COALESCE(SUM(ii.count), 0) AS total_count,
                COUNT(DISTINCT ii.url_id) AS url_count,
                0 AS unique_selectors
            FROM issue_items ii
            $where
            GROUP BY ii.item_id, ii.category
            ORDER BY ii.category, total_count DESC, url_count DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function pagesForAllIssuesFiltered(?string $category, ?string $itemId, array $viewports, bool $includeSuppressed, ?array $urlIds, ?string $selectorLike): array
    {
        if ($this->hasElements($viewports) || $selectorLike !== null) {
            $latest = $this->latestResultsJoin('ie', $viewports);
            $conditions = ['ie.project_id = :project_id'];
            $params = array_merge(['project_id' => $this->projectId], $latest['params']);
            if ($category) {
                $conditions[] = 'ie.category = :category';
                $params['category'] = $category;
            }
            if ($itemId) {
                $conditions[] = 'ie.item_id = :item_id';
                $params['item_id'] = $itemId;
            }
            if ($urlIds !== null) {
                if (empty($urlIds)) {
                    return [];
                }
                $placeholders = [];
                foreach ($urlIds as $idx => $id) {
                    $key = 'url_' . $idx;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $id;
                }
                $conditions[] = 'ie.url_id IN (' . implode(',', $placeholders) . ')';
            }
            if ($selectorLike !== null) {
                $conditions[] = 'ie.selector LIKE :selector_like';
                $params['selector_like'] = '%' . $selectorLike . '%';
            }
            if (!$includeSuppressed) {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category)';
                $conditions[] = $this->suppressionElementClause('ie');
            }
            $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "
                SELECT
                    ie.item_id,
                    ie.category,
                    u.id AS url_id,
                    u.url,
                    u.last_report_url,
                    COUNT(*) AS count
                FROM issue_elements ie
                {$latest['sql']}
                JOIN urls u ON u.id = ie.url_id AND u.project_id = ie.project_id
                $where
                GROUP BY ie.item_id, ie.category, u.id, u.url, u.last_report_url
                ORDER BY ie.category, count DESC, u.url
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        if ($selectorLike !== null) {
            return [];
        }
        $conditions = ['ii.project_id = ?'];
        $params = [$this->projectId];
        if ($category) {
            $conditions[] = 'ii.category = ?';
            $params[] = $category;
        }
        if ($itemId) {
            $conditions[] = 'ii.item_id = ?';
            $params[] = $itemId;
        }
        if ($urlIds !== null) {
            if (empty($urlIds)) {
                return [];
            }
            $conditions[] = 'ii.url_id IN (' . implode(',', array_fill(0, count($urlIds), '?')) . ')';
            $params = array_merge($params, array_values($urlIds));
        }
        if (!empty($viewports)) {
            $conditions[] = 'ii.viewport_label IN (' . implode(',', array_fill(0, count($viewports), '?')) . ')';
            $params = array_merge($params, array_values($viewports));
        }
        if (!$includeSuppressed) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = ? AND s.item_id = ii.item_id AND s.category = ii.category)';
            $params[] = $this->projectId;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                ii.item_id,
                ii.category,
                u.id AS url_id,
                u.url,
                u.last_report_url,
                COALESCE(SUM(ii.count), 0) AS count
            FROM issue_items ii
            JOIN urls u ON u.id = ii.url_id AND u.project_id = ii.project_id
            $where
            GROUP BY ii.item_id, ii.category, u.id, u.url, u.last_report_url
            ORDER BY ii.category, count DESC, u.url
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function selectorsForAllIssuesFiltered(?string $category, ?string $itemId, array $viewports, bool $includeSuppressed, ?array $urlIds, ?string $selectorLike): array
    {
        if (!$this->hasElements($viewports)) {
            return [];
        }
        $latest = $this->latestResultsJoin('ie', $viewports);
        $conditions = ['ie.project_id = :project_id'];
        $params = array_merge(['project_id' => $this->projectId], $latest['params']);
        if ($category) {
            $conditions[] = 'ie.category = :category';
            $params['category'] = $category;
        }
        if ($itemId) {
            $conditions[] = 'ie.item_id = :item_id';
            $params['item_id'] = $itemId;
        }
        if ($urlIds !== null) {
            if (empty($urlIds)) {
                return [];
            }
            $placeholders = [];
            foreach ($urlIds as $idx => $id) {
                $key = 'url_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $conditions[] = 'ie.url_id IN (' . implode(',', $placeholders) . ')';
        }
        if ($selectorLike !== null) {
            $conditions[] = 'ie.selector LIKE :selector_like';
            $params['selector_like'] = '%' . $selectorLike . '%';
        }
        if (!$includeSuppressed) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM issue_suppressions s WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category)';
            $conditions[] = $this->suppressionElementClause('ie');
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                ie.item_id,
                ie.category,
                u.url,
                ie.viewport_label,
                ie.selector,
                ie.contrast_ratio,
                ie.foreground_color,
                ie.background_color,
                ie.large_text
            FROM issue_elements ie
            {$latest['sql']}
            JOIN urls u ON u.id = ie.url_id AND u.project_id = ie.project_id
            $where
            ORDER BY ie.category, ie.item_id, u.url, ie.selector
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countsForUrlViewport(int $urlId, string $viewportLabel): array
    {
        $stmt = $this->pdo->prepare('SELECT MAX(tested_at) FROM results WHERE project_id = :project_id AND url_id = :url_id AND viewport_label = :viewport_label');
        $stmt->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'viewport_label' => $viewportLabel,
        ]);
        $testedAt = (string)($stmt->fetchColumn() ?: '');
        if ($testedAt === '') {
            return ['errors' => 0, 'contrast_errors' => 0, 'alerts' => 0];
        }
        return $this->countsForResult($urlId, $viewportLabel, $testedAt);
    }

    public function countsForResult(int $urlId, string $viewportLabel, string $testedAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM issue_elements
             WHERE project_id = :project_id AND url_id = :url_id AND viewport_label = :viewport_label AND tested_at = :tested_at
             LIMIT 1'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'viewport_label' => $viewportLabel,
            'tested_at' => $testedAt,
        ]);
        $hasElements = (bool)$stmt->fetchColumn();
        if ($hasElements) {
            $stmt = $this->pdo->prepare(
                'SELECT category, COUNT(*) AS total_count
                 FROM issue_elements ie
                 WHERE ie.project_id = :project_id
                   AND url_id = :url_id
                   AND viewport_label = :viewport_label
                   AND tested_at = :tested_at
                   AND NOT EXISTS (
                     SELECT 1 FROM issue_suppressions s
                     WHERE s.project_id = :project_id AND s.item_id = ie.item_id AND s.category = ie.category
                   )
                   AND ' . $this->suppressionElementClause('ie') . '
                 GROUP BY category'
            );
            $stmt->execute([
                'project_id' => $this->projectId,
                'url_id' => $urlId,
                'viewport_label' => $viewportLabel,
                'tested_at' => $testedAt,
            ]);
            $rows = $stmt->fetchAll();
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT category, SUM(count) AS total_count
                 FROM issue_items ii
                 WHERE ii.project_id = :project_id
                   AND url_id = :url_id
                   AND viewport_label = :viewport_label
                   AND tested_at = :tested_at
                   AND NOT EXISTS (
                     SELECT 1 FROM issue_suppressions s
                     WHERE s.project_id = :project_id AND s.item_id = ii.item_id AND s.category = ii.category
                   )
                 GROUP BY category'
            );
            $stmt->execute([
                'project_id' => $this->projectId,
                'url_id' => $urlId,
                'viewport_label' => $viewportLabel,
                'tested_at' => $testedAt,
            ]);
            $rows = $stmt->fetchAll();
        }
        $counts = ['errors' => 0, 'contrast_errors' => 0, 'alerts' => 0];
        foreach ($rows as $row) {
            $category = $row['category'] ?? '';
            $total = (int)($row['total_count'] ?? 0);
            if ($category === 'error') {
                $counts['errors'] = $total;
            } elseif ($category === 'contrast') {
                $counts['contrast_errors'] = $total;
            } elseif ($category === 'alert') {
                $counts['alerts'] = $total;
            }
        }
        return $counts;
    }

    public function getDoc(string $itemId, WaveClient $client): ?array
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM issue_docs WHERE item_id = :id');
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetchColumn();
        if ($row !== false) {
            $decoded = json_decode($row, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        try {
            $doc = $client->fetchDoc($itemId);
            $insert = $this->pdo->prepare(
                'INSERT INTO issue_docs (item_id, payload, fetched_at)
                 VALUES (:id, :payload, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE payload = :payload, fetched_at = CURRENT_TIMESTAMP'
            );
            $insert->execute([
                'id' => $itemId,
                'payload' => json_encode($doc),
            ]);
            return $doc;
        } catch (Throwable $e) {
            return null;
        }
    }
}

class IssueSuppressionRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM issue_suppressions WHERE project_id = :project_id ORDER BY created_at DESC'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        return $stmt->fetchAll();
    }

    public function upsert(string $itemId, string $category, ?string $reason = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO issue_suppressions (project_id, item_id, category, reason)
             VALUES (:project_id, :item_id, :category, :reason)
             ON DUPLICATE KEY UPDATE reason = :reason'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'item_id' => $itemId,
            'category' => $category,
            'reason' => $reason,
        ]);
    }

    public function delete(string $itemId, string $category): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM issue_suppressions WHERE project_id = :project_id AND item_id = :item_id AND category = :category'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'item_id' => $itemId,
            'category' => $category,
        ]);
    }
}

class IssueElementSuppressionRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM issue_suppression_elements WHERE project_id = :project_id ORDER BY created_at DESC'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        return $stmt->fetchAll();
    }

    public function upsert(int $urlId, string $itemId, string $category, string $selector, ?string $viewportLabel, ?string $reason = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO issue_suppression_elements (project_id, url_id, viewport_label, item_id, category, selector, reason)
             VALUES (:project_id, :url_id, :viewport_label, :item_id, :category, :selector, :reason)
             ON DUPLICATE KEY UPDATE reason = :reason'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'viewport_label' => $viewportLabel,
            'item_id' => $itemId,
            'category' => $category,
            'selector' => $selector,
            'reason' => $reason,
        ]);
    }

    public function deleteBySelector(string $itemId, string $category, string $selector, ?string $viewportLabel): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM issue_suppression_elements
             WHERE project_id = :project_id
               AND item_id = :item_id
               AND category = :category
               AND selector = :selector
               AND ((:viewport_label IS NULL AND viewport_label IS NULL) OR viewport_label = :viewport_label)'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'item_id' => $itemId,
            'category' => $category,
            'selector' => $selector,
            'viewport_label' => $viewportLabel,
        ]);
    }
}

class SelectorRepository
{
    public function __construct(private DbConnection $pdo) {}

    public function getOrCreate(string $selector): int
    {
        $hash = sha1($selector);
        $stmt = $this->pdo->prepare('SELECT id FROM selectors WHERE selector_hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO selectors (selector, selector_hash) VALUES (:selector, :hash)'
        );
        $stmt->execute([
            'selector' => $selector,
            'hash' => $hash,
        ]);
        $id = $this->pdo->lastInsertId();
        if ($id) {
            return (int)$id;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM selectors WHERE selector_hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }
}

class QueueRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    private function cacheKey(): string
    {
        return 'queue_summary:' . $this->projectId;
    }

    private function clearCache(): void
    {
        RedisCache::del($this->cacheKey());
    }

    public function enqueue(int $urlId, array $params = []): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO queue (project_id, url_id, params, status) VALUES (:project_id, :url_id, :params, "pending")'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'params' => json_encode($params),
        ]);
        $this->clearCache();
        return (int)$this->pdo->lastInsertId();
    }

    public function fetchPending(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM queue WHERE project_id = :project_id AND status = "pending" ORDER BY created_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':project_id', $this->projectId);
        $stmt->bindValue(':limit', $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markRunning(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE queue SET status = "running", started_at = CURRENT_TIMESTAMP WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute(['id' => $id, 'project_id' => $this->projectId]);
        $this->clearCache();
    }

    public function markComplete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE queue SET status = "completed", finished_at = CURRENT_TIMESTAMP WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute(['id' => $id, 'project_id' => $this->projectId]);
        $this->clearCache();
    }

    public function markFailed(int $id, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE queue SET status = "failed", finished_at = CURRENT_TIMESTAMP, error_message = :message WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute(['message' => $message, 'id' => $id, 'project_id' => $this->projectId]);
        $this->clearCache();
    }

    public function runningCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM queue WHERE project_id = :project_id AND status = "running"');
        $stmt->execute(['project_id' => $this->projectId]);
        return (int)$stmt->fetchColumn();
    }

    public function summary(): array
    {
        $cached = RedisCache::get($this->cacheKey());
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS count
             FROM queue
             WHERE project_id = :project_id
             GROUP BY status'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        $rows = $stmt->fetchAll();
        $summary = [
            'total' => 0,
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            $count = (int)($row['count'] ?? 0);
            if ($status !== '' && array_key_exists($status, $summary)) {
                $summary[$status] = $count;
            }
            $summary['total'] += $count;
        }
        $payload = json_encode($summary);
        if ($payload !== false) {
            RedisCache::set($this->cacheKey(), $payload, 3);
        }
        return $summary;
    }

    public function all(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.*, u.url
             FROM queue q
             LEFT JOIN urls u ON u.id = q.url_id AND u.project_id = q.project_id
             WHERE q.project_id = :project_id
             ORDER BY q.created_at DESC'
        );
        $stmt->execute(['project_id' => $this->projectId]);
        return $stmt->fetchAll();
    }

    public function failed(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.*, u.url FROM queue q LEFT JOIN urls u ON u.id = q.url_id AND u.project_id = q.project_id
             WHERE q.project_id = :project_id AND q.status = "failed"
             ORDER BY q.finished_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':project_id', $this->projectId);
        $stmt->bindValue(':limit', $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function clearAll(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM queue WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $this->projectId]);
        $this->clearCache();
    }
}

class MetricsCacheRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    private function cacheKey(string $key): string
    {
        return 'metrics_cache:' . $this->projectId . ':' . $key;
    }

    public function get(string $key): ?array
    {
        $redisKey = $this->cacheKey($key);
        $cached = RedisCache::get($redisKey);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM metrics_cache WHERE project_id = :project_id AND cache_key = :key'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'key' => $key,
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $payload = json_encode($row);
            if ($payload !== false) {
                RedisCache::set($redisKey, $payload, 300);
            }
        }
        return $row ?: null;
    }

    public function set(string $key, int $errors, int $contrast, int $alerts): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO metrics_cache (project_id, cache_key, errors, contrast, alerts, updated_at)
             VALUES (:project_id, :key, :errors, :contrast, :alerts, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE errors = :errors, contrast = :contrast, alerts = :alerts, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'key' => $key,
            'errors' => $errors,
            'contrast' => $contrast,
            'alerts' => $alerts,
        ]);
        $payload = json_encode([
            'project_id' => $this->projectId,
            'cache_key' => $key,
            'errors' => $errors,
            'contrast' => $contrast,
            'alerts' => $alerts,
            'updated_at' => date('c'),
        ]);
        if ($payload !== false) {
            RedisCache::set($this->cacheKey($key), $payload, 300);
        }
    }

    public function clear(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM metrics_cache WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $this->projectId]);
        RedisCache::deleteByPrefix('metrics_cache:' . $this->projectId . ':');
    }
}

class ErrorRepository
{
    public function __construct(private DbConnection $pdo, private int $projectId) {}

    public function record(?int $urlId, ?string $url, ?string $viewportLabel, string $context, string $message, ?int $jobId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO errors (project_id, url_id, url, viewport_label, context, message, job_id)
             VALUES (:project_id, :url_id, :url, :viewport_label, :context, :message, :job_id)'
        );
        $stmt->execute([
            'project_id' => $this->projectId,
            'url_id' => $urlId,
            'url' => $url,
            'viewport_label' => $viewportLabel,
            'context' => $context,
            'message' => $message,
            'job_id' => $jobId,
        ]);
    }

    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM errors WHERE project_id = :project_id ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':project_id', $this->projectId);
        $stmt->bindValue(':limit', $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function clearAll(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM errors WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $this->projectId]);
    }
}

class WaveClient
{
    private string $baseUrl;
    /** @var callable|null */
    private $httpResolver;
    private ?string $lastUrl = null;
    private ?string $lastRaw = null;
    private ?int $lastStatus = null;

    public function __construct(?string $baseUrl = null, ?callable $httpResolver = null)
    {
        $this->baseUrl = $baseUrl ?: 'https://wave.webaim.org/api/request';
        $this->httpResolver = $httpResolver;
    }

    public function setHttpResolver(callable $resolver): void
    {
        $this->httpResolver = $resolver;
    }

    private function resolveHttpClient(): ?PHAPI\Services\HttpClient
    {
        if (!is_callable($this->httpResolver)) {
            return null;
        }

        $client = ($this->httpResolver)();
        return $client instanceof PHAPI\Services\HttpClient ? $client : null;
    }

    public function analyze(string $apiKey, string $url, array $options = []): array
    {
        if ($apiKey === '') {
            throw new Exception('Missing WAVE API key');
        }

        $query = array_merge($options, [
            'key' => $apiKey,
            'url' => $url,
        ]);

        $requestUrl = $this->baseUrl . '?' . http_build_query($query);
        $this->lastUrl = $requestUrl;
        $this->lastRaw = null;
        $this->lastStatus = null;
        $data = null;
        $client = $this->resolveHttpClient();
        if ($client) {
            if (method_exists($client, 'getJsonWithMeta')) {
                $meta = $client->getJsonWithMeta($requestUrl);
                $data = $meta['data'] ?? null;
                $this->lastStatus = isset($meta['status']) ? (int)$meta['status'] : null;
                $this->lastRaw = isset($meta['body']) && is_string($meta['body']) ? $meta['body'] : null;
            } else {
                try {
                    $data = $client->getJson($requestUrl);
                } catch (Throwable $e) {
                    if (class_exists('PHAPI\\Exceptions\\HttpRequestException') && $e instanceof PHAPI\Exceptions\HttpRequestException) {
                        $this->lastStatus = $e->status();
                        $this->lastRaw = $e->body();
                    }
                    throw $e;
                }
            }
            if (!is_array($data) || $data === []) {
                throw new Exception('WAVE API request failed or returned invalid JSON');
            }
        } else {
            $data = $this->fetchViaCurl($requestUrl);
            if (!is_array($data)) {
                throw new Exception('Invalid JSON from WAVE API');
            }
        }

        if (isset($data['status']['success']) && $data['status']['success'] === false) {
            $message = $data['status']['error'] ?? 'WAVE API returned an error';
            throw new Exception($message);
        }

        $categoryCount = function (string $key) use ($data): ?int {
            return $data['categories'][$key]['count'] ?? null;
        };

        return [
            'raw' => $data,
            'http_status' => $data['status']['httpstatuscode'] ?? null,
            'page_title' => $data['statistics']['pagetitle'] ?? null,
            'final_url' => $data['statistics']['pageurl'] ?? null,
            'analysis_duration' => $data['statistics']['time'] ?? null,
            'credits_remaining' => $data['statistics']['creditsremaining'] ?? null,
            'aim_score' => $data['statistics']['AIMscore'] ?? null,
            'errors' => $categoryCount('error'),
            'contrast_errors' => $categoryCount('contrast'),
            'alerts' => $categoryCount('alert'),
            'features' => $categoryCount('feature'),
            'structure' => $categoryCount('structure'),
            'aria' => $categoryCount('aria'),
            'total_elements' => $data['statistics']['totalelements'] ?? null,
            'report_url' => $data['statistics']['waveurl'] ?? null,
        ];
    }

    private function fetchViaCurl(string $requestUrl): ?array
    {
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->lastRaw = null;
            $this->lastStatus = null;
            throw new Exception('cURL error: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->lastRaw = is_string($response) ? $response : null;
        $this->lastStatus = is_int($status) ? $status : null;

        if ($status >= 400) {
            throw new Exception('WAVE API HTTP error ' . $status . ': ' . $response);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public function lastRequestContext(): array
    {
        return [
            'url' => $this->lastUrl,
            'status' => $this->lastStatus,
            'raw' => $this->lastRaw,
        ];
    }

    public function fetchDoc(string $itemId): array
    {
        $url = 'https://wave.webaim.org/api/docs?id=' . urlencode($itemId);
        $this->lastUrl = $url;
        $this->lastRaw = null;
        $this->lastStatus = null;
        $client = $this->resolveHttpClient();
        if ($client) {
            if (method_exists($client, 'getJsonWithMeta')) {
                $meta = $client->getJsonWithMeta($url);
                $data = $meta['data'] ?? null;
                $this->lastStatus = isset($meta['status']) ? (int)$meta['status'] : null;
                $this->lastRaw = isset($meta['body']) && is_string($meta['body']) ? $meta['body'] : null;
            } else {
                try {
                    $data = $client->getJson($url);
                } catch (Throwable $e) {
                    if (class_exists('PHAPI\\Exceptions\\HttpRequestException') && $e instanceof PHAPI\Exceptions\HttpRequestException) {
                        $this->lastStatus = $e->status();
                        $this->lastRaw = $e->body();
                    }
                    throw $e;
                }
            }
            if (!is_array($data) || $data === []) {
                throw new Exception('Invalid doc JSON');
            }
            return $data;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception('Doc fetch error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new Exception('Doc HTTP error ' . $status);
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Invalid doc JSON');
        }
        return $data;
    }
}

class TestRunner
{
    public function __construct(
        private UrlRepository $urls,
        private ResultRepository $results,
        private QueueRepository $queue,
        private WaveClient $waveClient,
        private string $apiKey,
        private IssueRepository $issues,
        private array $defaultParams = [],
        private int $retryAttempts = 2,
        private int $retryDelayMs = 500
    ) {
    }

    public function runPending(int $maxConcurrent = 10, int $batchSize = 10): array
    {
        if ($this->apiKey === '') {
            return ['processed' => 0, 'message' => 'Missing WAVE API key. Add it in Project Configuration.'];
        }
        $running = $this->queue->runningCount();
        $capacity = max(0, $maxConcurrent - $running);
        if ($capacity === 0) {
            return ['processed' => 0, 'message' => 'Concurrency limit reached'];
        }

        $jobs = $this->queue->fetchPending(min($capacity, $batchSize));
        $processed = 0;
        foreach ($jobs as $job) {
            $processed++;
            $this->queue->markRunning((int)$job['id']);

            try {
                $url = $this->urls->find((int)$job['url_id']);
                if (!$url) {
                    throw new Exception('URL not found for job ' . $job['id']);
                }

                $params = json_decode($job['params'] ?? '{}', true) ?: [];
                $mergedParams = array_merge($this->defaultParams, $params);
                $reportType = (int)($mergedParams['reporttype'] ?? 4);
                $reportType = $reportType < 1 ? 1 : ($reportType > 4 ? 4 : $reportType);
                $mergedParams['reporttype'] = $reportType;
                $runId = isset($mergedParams['run_id']) ? (int)$mergedParams['run_id'] : null;
                $viewportLabel = isset($mergedParams['viewport_label']) ? (string)$mergedParams['viewport_label'] : 'default';
                if ($viewportLabel === '') {
                    $viewportLabel = 'default';
                }
                unset($mergedParams['viewport_label']);
                unset($mergedParams['run_id']);

                $analysis = waveAnalyzeWithRetry(
                    $this->waveClient,
                    $this->apiKey,
                    $url['url'],
                    $mergedParams,
                    $this->retryAttempts,
                    $this->retryDelayMs
                );
                $raw = $analysis['raw'] ?? [];
                $categories = $raw['categories'] ?? [];

                $testedAt = date('c');
                $recordData = [
                    'tested_at' => $testedAt,
                    'viewport_label' => $viewportLabel,
                    'run_id' => $runId,
                    'aim_score' => $analysis['aim_score'],
                    'errors' => $analysis['errors'],
                    'contrast_errors' => $analysis['contrast_errors'],
                    'alerts' => $analysis['alerts'],
                    'features' => $analysis['features'],
                    'structure' => $analysis['structure'],
                    'aria' => $analysis['aria'],
                    'total_elements' => $analysis['total_elements'],
                    'http_status' => $analysis['http_status'],
                    'page_title' => $analysis['page_title'],
                    'final_url' => $analysis['final_url'],
                    'report_url' => $analysis['report_url'],
                    'credits_remaining' => $analysis['credits_remaining'],
                    'analysis_duration' => $analysis['analysis_duration'],
                ];

                $urlId = (int)$job['url_id'];

                $this->issues->saveForUrlTest($urlId, $testedAt, $categories, $reportType, $viewportLabel);
                if ($reportType >= 3) {
                    $recordData['unique_errors'] = $this->issues->countGlobalUniqueByCategory('error', [$viewportLabel]);
                    $recordData['unique_contrast_errors'] = $this->issues->countGlobalUniqueByCategory('contrast', [$viewportLabel]);
                    $recordData['unique_alerts'] = $this->issues->countGlobalUniqueByCategory('alert', [$viewportLabel]);
                } else {
                    $recordData['unique_errors'] = null;
                    $recordData['unique_contrast_errors'] = null;
                    $recordData['unique_alerts'] = null;
                }

                $counts = $this->issues->countsForResult($urlId, $viewportLabel, $testedAt);
                $recordData['errors'] = $counts['errors'];
                $recordData['contrast_errors'] = $counts['contrast_errors'];
                $recordData['alerts'] = $counts['alerts'];

                $this->results->record($urlId, $recordData);
                $this->urls->updateLastSummary($urlId, $recordData);

                $this->queue->markComplete((int)$job['id']);
            } catch (Throwable $e) {
                error_log('Job failed: ' . $e->getMessage());
                $this->queue->markFailed((int)$job['id'], $e->getMessage());
            }
        }

        return ['processed' => $processed, 'message' => 'Processed pending jobs'];
    }

    public function updateDefaults(array $params): void
    {
        $this->defaultParams = $params;
    }

    public function updateApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function updateRetry(int $attempts, int $delayMs): void
    {
        $this->retryAttempts = max(0, $attempts);
        $this->retryDelayMs = max(0, $delayMs);
    }
}

class ProjectState
{
    public function __construct(
        public int $projectId,
        public array $suppressedIssueIds,
        public IssueSuppressionRepository $suppressions,
        public Database $database,
        public ConfigRepository $config,
        public UrlRepository $urls,
        public TagRepository $tags,
        public ViewportRepository $viewports,
        public ResultRepository $results,
        public QueueRepository $queue,
        public ErrorRepository $errors,
        public IssueRepository $issues,
        public WaveClient $waveClient,
        public TestRunner $runner,
        public string $waveApiKey,
        public array $waveDefaultParams,
        public string $authUser,
        public string $authPass
    ) {
    }
}

class BackgroundWorker
{
    private ?\Swoole\Process $process = null;
    private string $buffer = '';
    private ?\Closure $onEvent;

    public function __construct(private string $baseDir, ?\Closure $onEvent = null)
    {
        $this->onEvent = $onEvent;
    }

    public function createProcess(): \Swoole\Process
    {
        $baseDir = $this->baseDir;
        return new \Swoole\Process(function (\Swoole\Process $worker) use ($baseDir) {
            $state = require $baseDir . '/app/bootstrap.php';
            if (!$state instanceof MainState) {
                return;
            }
            while (true) {
                $payload = $worker->read();
                if ($payload === '' || $payload === false) {
                    usleep(10000);
                    continue;
                }
                $lines = preg_split('/\\r?\\n/', trim($payload));
                if (!$lines) {
                    continue;
                }
                foreach ($lines as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $message = json_decode($line, true);
                    if (!is_array($message)) {
                        continue;
                    }
                    $type = (string)($message['type'] ?? '');
                    $projectId = (int)($message['project_id'] ?? 0);
                    if ($type === 'queue_tick') {
                        $projects = $state->projects->listAll();
                        $maxConcurrent = (int)(getenv('QUEUE_MAX_CONCURRENT') ?: 2);
                        $maxConcurrent = min(2, max(1, $maxConcurrent));
                        $batchSize = (int)(getenv('QUEUE_BATCH_SIZE') ?: 2);
                        $take = max(1, min($batchSize, $maxConcurrent));
                        foreach ($projects as $projectRow) {
                            $projectState = $state->projectState($projectRow);
                            $jobs = $projectState->queue->fetchPending($take);
                            if (empty($jobs)) {
                                continue;
                            }
                            foreach ($jobs as $job) {
                                $projectState->queue->markRunning((int)$job['id']);
                            }
                            foreach ($jobs as $job) {
                                $result = processQueueJob($job, $projectState);
                                $params = json_decode($job['params'] ?? '{}', true);
                                $worker->write(json_encode([
                                    'event' => 'queue.job',
                                    'status' => $result['status'] ?? null,
                                    'job_id' => $result['job_id'] ?? null,
                                    'url_id' => $job['url_id'] ?? null,
                                    'viewport_label' => is_array($params) ? ($params['viewport_label'] ?? null) : null,
                                    'error' => $result['error'] ?? null,
                                ]) . "\n");
                            }
                        }
                        continue;
                    }
                    if ($projectId <= 0 || $type === '') {
                        continue;
                    }
                    $project = $state->projects->find($projectId);
                    if (!$project) {
                        continue;
                    }
                    $projectState = $state->projectState($project);
                    if ($type === 'metrics_refresh') {
                        try {
                            recomputeUniqueMetricsCache($projectState);
                            $projectState->config->set('metrics_dirty', '0');
                            $worker->write(json_encode([
                                'event' => 'metrics.updated',
                                'project_id' => $projectId,
                            ]) . "\n");
                        } catch (Throwable $e) {
                            $worker->write(json_encode([
                                'event' => 'metrics.error',
                                'project_id' => $projectId,
                                'error' => $e->getMessage(),
                            ]) . "\n");
                        } finally {
                            $projectState->config->set('metrics_refresh_running', '0');
                        }
                    } elseif ($type === 'selectors_backfill') {
                        try {
                            $limit = (int)(getenv('SELECTOR_BACKFILL_LIMIT') ?: 200);
                            $updated = backfillSelectorIds($projectState, $limit);
                            if ($updated === 0) {
                                $stmt = $projectState->database->connection()->prepare(
                                    'SELECT 1 FROM issue_elements WHERE project_id = :project_id AND selector_id IS NULL LIMIT 1'
                                );
                                $stmt->execute(['project_id' => $projectId]);
                                $pending = $stmt->fetchColumn();
                                if (!$pending) {
                                    $projectState->config->set('selectors_backfill_done', '1');
                                }
                            }
                            $worker->write(json_encode([
                                'event' => 'selectors.backfill',
                                'project_id' => $projectId,
                                'updated' => $updated,
                            ]) . "\n");
                        } catch (Throwable $e) {
                            $worker->write(json_encode([
                                'event' => 'selectors.error',
                                'project_id' => $projectId,
                                'error' => $e->getMessage(),
                            ]) . "\n");
                        } finally {
                            $projectState->config->set('selectors_backfill_running', '0');
                        }
                    }
                }
            }
        }, false, SOCK_STREAM, true);
    }

    public function isStarted(): bool
    {
        return $this->process !== null;
    }

    public function attachProcess(\Swoole\Process $process): void
    {
        $this->process = $process;
        \Swoole\Event::add($process->pipe, function () {
            $this->handleEvents();
        });
    }

    public function enqueue(array $task): bool
    {
        if ($this->process === null) {
            return false;
        }
        $payload = json_encode($task);
        if ($payload === false) {
            return false;
        }
        return $this->process->write($payload . "\n") !== false;
    }

    private function handleEvents(): void
    {
        if ($this->process === null) {
            return;
        }
        $chunk = $this->process->read();
        if ($chunk === '' || $chunk === false) {
            return;
        }
        $this->buffer .= $chunk;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            if ($line === '') {
                continue;
            }
            $message = json_decode($line, true);
            if (!is_array($message)) {
                continue;
            }
            if (is_callable($this->onEvent)) {
                ($this->onEvent)($message);
            }
        }
    }
}

class MainState
{
    public function __construct(
        public string $baseDir,
        public MainDatabase $database,
        public ProjectRepository $projects,
        public string $authUser,
        public string $authPass,
        public ?\Closure $httpResolver = null,
        public ?BackgroundWorker $backgroundWorker = null
    ) {
    }

    public function projectState(array $projectRow): ProjectState
    {
        $projectId = (int)($projectRow['id'] ?? 0);
        if ($projectId <= 0) {
            throw new RuntimeException('Project id missing.');
        }

        $database = new Database();
        $pdo = $database->connection();
        $configRepository = new ConfigRepository($pdo, $projectId);
        $urlRepository = new UrlRepository($pdo, $projectId);
        $tagRepository = new TagRepository($pdo, $projectId);
        $viewportRepository = new ViewportRepository($pdo, $projectId);
        $viewportRepository->ensureDefault();
        $resultRepository = new ResultRepository($pdo, $projectId);
        $queueRepository = new QueueRepository($pdo, $projectId);
        $errorRepository = new ErrorRepository($pdo, $projectId);
        $suppressionRepository = new IssueSuppressionRepository($pdo, $projectId);
        $suppressedIssueIds = [];
        foreach ($suppressionRepository->listAll() as $row) {
            $itemId = strtolower((string)($row['item_id'] ?? ''));
            $category = strtolower((string)($row['category'] ?? ''));
            if ($itemId === '' || $category === '') {
                continue;
            }
            $suppressedIssueIds[$category . '|' . $itemId] = true;
        }
        $issueRepository = new IssueRepository($pdo, $projectId, $suppressedIssueIds);
        $waveClient = new WaveClient();
        if (is_callable($this->httpResolver)) {
            $waveClient->setHttpResolver($this->httpResolver);
        }

        $configReportType = $configRepository->get('reporttype');
        $reportType = $configReportType !== null ? (int)$configReportType : 4;
        if ($reportType < 1 || $reportType > 4) {
            $reportType = 4;
        }
        $viewportWidth = $configRepository->get('viewportwidth');
        $evalDelay = $configRepository->get('evaldelay');
        $userAgent = $configRepository->get('useragent');
        $waveDefaultParams = [
            'reporttype' => $reportType,
            'format' => 'json',
        ];
        if ($viewportWidth !== null && is_numeric($viewportWidth)) {
            $waveDefaultParams['viewportwidth'] = (int)$viewportWidth;
        }
        if ($evalDelay !== null && is_numeric($evalDelay)) {
            $waveDefaultParams['evaldelay'] = (int)$evalDelay;
        }
        if ($userAgent !== null && $userAgent !== '') {
            $waveDefaultParams['useragent'] = $userAgent;
        }

        $retryAttempts = (int)($configRepository->get('retry_attempts') ?? 2);
        $retryDelayMs = (int)($configRepository->get('retry_delay_ms') ?? 500);
        if ($retryAttempts < 0) {
            $retryAttempts = 0;
        }
        if ($retryDelayMs < 0) {
            $retryDelayMs = 0;
        }

        $waveApiKey = (string)($configRepository->get('api_key') ?? '');

        $runner = new TestRunner(
            $urlRepository,
            $resultRepository,
            $queueRepository,
            $waveClient,
            $waveApiKey,
            $issueRepository,
            $waveDefaultParams,
            $retryAttempts,
            $retryDelayMs
        );

        return new ProjectState(
            $projectId,
            $suppressedIssueIds,
            $suppressionRepository,
            $database,
            $configRepository,
            $urlRepository,
            $tagRepository,
            $viewportRepository,
            $resultRepository,
            $queueRepository,
            $errorRepository,
            $issueRepository,
            $waveClient,
            $runner,
            $waveApiKey,
            $waveDefaultParams,
            $this->authUser,
            $this->authPass
        );
    }
}

function waveAnalyzeWithRetry(
    WaveClient $client,
    string $apiKey,
    string $url,
    array $params,
    int $attempts,
    int $delayMs
): array {
    $tries = max(1, $attempts + 1);
    $lastError = null;
    for ($i = 0; $i < $tries; $i++) {
        try {
            return $client->analyze($apiKey, $url, $params);
        } catch (Throwable $e) {
            $lastError = $e;
            if ($i >= $tries - 1) {
                break;
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
    }
    throw $lastError ?: new Exception('WAVE API request failed.');
}

function slugifyProjectName(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'project';
}

function processQueueJob(array $job, ProjectState $state): array
{
    $jobId = (int)($job['id'] ?? 0);
    $pdo = $state->database->connection();

    $urls = $state->urls;
    $results = $state->results;
    $queue = $state->queue;
    $errors = $state->errors;
    $config = $state->config;
    $issues = $state->issues;
    $metricsCache = new MetricsCacheRepository($pdo, $state->projectId);
    $urlId = !empty($job['url_id']) ? (int)$job['url_id'] : 0;
    $urlRow = $urlId ? $urls->find($urlId) : null;
    $urlValue = $urlRow['url'] ?? null;
    $waveClient = $state->waveClient;

    $params = json_decode($job['params'] ?? '{}', true);
    if (!is_array($params)) {
        $params = [];
    }
    $viewportLabel = isset($params['viewport_label']) ? (string)$params['viewport_label'] : null;
    $runId = isset($params['run_id']) ? (int)$params['run_id'] : null;

    if ($state->waveApiKey === '') {
        $queue->markFailed($jobId, 'Missing WAVE API key. Add it in Project Configuration.');
        if ($urlId) {
            $urls->updateLastError($urlId, 'Missing WAVE API key. Add it in Project Configuration.');
        }
        $errors->record(
            $urlId ?: null,
            $urlValue,
            $viewportLabel,
            'queue.job',
            'Missing WAVE API key. Add it in Project Configuration.',
            $jobId
        );
        return ['job_id' => $jobId, 'status' => 'failed', 'error' => 'Missing WAVE API key.'];
    }

    try {
        if (!$urlRow) {
            throw new Exception('URL not found for job ' . $jobId);
        }

        $configReportType = $config->get('reporttype');
        $reportType = $configReportType !== null ? (int)$configReportType : (int)($state->waveDefaultParams['reporttype'] ?? 4);
        $reportType = $reportType < 1 ? 1 : ($reportType > 4 ? 4 : $reportType);

        $mergedParams = array_merge($state->waveDefaultParams, $params);
        $mergedParams['reporttype'] = $reportType;
        if ($viewportLabel === null || $viewportLabel === '') {
            $viewportLabel = 'default';
        }
        unset($mergedParams['viewport_label']);
        unset($mergedParams['run_id']);

        if (!isset($mergedParams['viewportwidth']) || $mergedParams['viewportwidth'] === '') {
            $viewportWidth = $config->get('viewportwidth');
            if ($viewportWidth !== null && is_numeric($viewportWidth)) {
                $mergedParams['viewportwidth'] = (int)$viewportWidth;
            }
        }
        if (!isset($mergedParams['evaldelay']) || $mergedParams['evaldelay'] === '') {
            $evalDelay = $config->get('evaldelay');
            if ($evalDelay !== null && is_numeric($evalDelay)) {
                $mergedParams['evaldelay'] = (int)$evalDelay;
            }
        }
        if (!isset($mergedParams['useragent']) || $mergedParams['useragent'] === '') {
            $userAgent = $config->get('useragent');
            if ($userAgent !== null && $userAgent !== '') {
                $mergedParams['useragent'] = $userAgent;
            }
        }

        $retryAttempts = (int)($config->get('retry_attempts') ?? 2);
        $retryDelayMs = (int)($config->get('retry_delay_ms') ?? 500);
        if ($retryAttempts < 0) {
            $retryAttempts = 0;
        }
        if ($retryDelayMs < 0) {
            $retryDelayMs = 0;
        }
        $analysis = waveAnalyzeWithRetry(
            $waveClient,
            $state->waveApiKey,
            $urlValue,
            $mergedParams,
            $retryAttempts,
            $retryDelayMs
        );
        $raw = $analysis['raw'] ?? [];
        $categories = $raw['categories'] ?? [];

        $testedAt = date('c');
        $recordData = [
            'tested_at' => $testedAt,
            'viewport_label' => $viewportLabel,
            'run_id' => $runId,
            'aim_score' => $analysis['aim_score'],
            'errors' => $analysis['errors'],
            'contrast_errors' => $analysis['contrast_errors'],
            'alerts' => $analysis['alerts'],
            'features' => $analysis['features'],
            'structure' => $analysis['structure'],
            'aria' => $analysis['aria'],
            'total_elements' => $analysis['total_elements'],
            'http_status' => $analysis['http_status'],
            'page_title' => $analysis['page_title'],
            'final_url' => $analysis['final_url'],
            'report_url' => $analysis['report_url'],
            'credits_remaining' => $analysis['credits_remaining'],
            'analysis_duration' => $analysis['analysis_duration'],
        ];

        $urlId = $urlId ?: (int)$job['url_id'];

        $issues->saveForUrlTest($urlId, $testedAt, $categories, $reportType, $viewportLabel);
        if ($reportType >= 3) {
            $recordData['unique_errors'] = $issues->countGlobalUniqueByCategory('error', [$viewportLabel]);
            $recordData['unique_contrast_errors'] = $issues->countGlobalUniqueByCategory('contrast', [$viewportLabel]);
            $recordData['unique_alerts'] = $issues->countGlobalUniqueByCategory('alert', [$viewportLabel]);
        } else {
            $recordData['unique_errors'] = null;
            $recordData['unique_contrast_errors'] = null;
            $recordData['unique_alerts'] = null;
        }

        $counts = $issues->countsForResult($urlId, $viewportLabel, $testedAt);
        $recordData['errors'] = $counts['errors'];
        $recordData['contrast_errors'] = $counts['contrast_errors'];
        $recordData['alerts'] = $counts['alerts'];

        $results->record($urlId, $recordData);
        $metricsCache->clear();
        $config->set('metrics_dirty', '1');
        $recordData['test_status'] = 'ok';
        $recordData['error_message'] = null;
        $urls->updateLastSummary($urlId, $recordData);

        $queue->markComplete($jobId);
        $app = \PHAPI\PHAPI::app();
        if ($app) {
            $app->realtime()->broadcast('queue', [
                'event' => 'queue.job',
                'status' => 'completed',
                'job_id' => $jobId,
                'url_id' => $urlId,
                'viewport_label' => $viewportLabel,
            ]);
        }
        return ['job_id' => $jobId, 'status' => 'completed'];
    } catch (Throwable $e) {
        $queue->markFailed($jobId, $e->getMessage());
        if ($urlId) {
            $urls->updateLastError($urlId, $e->getMessage());
        }
        $context = $waveClient->lastRequestContext();
        if (!empty($context['raw']) || !empty($context['status']) || !empty($context['url'])) {
            $snippet = '';
            if (!empty($context['raw'])) {
                $snippet = substr((string)$context['raw'], 0, 2000);
            }
            $errors->record(
                $urlId ?: null,
                $urlValue,
                $viewportLabel,
                'queue.wave_response',
                json_encode([
                    'request_url' => $context['url'] ?? null,
                    'http_status' => $context['status'] ?? null,
                    'body_snippet' => $snippet,
                ])
            );
        }
        $errors->record(
            $urlId ?: null,
            $urlValue,
            $viewportLabel,
            'queue.job',
            $e->getMessage(),
            $jobId
        );
        $app = \PHAPI\PHAPI::app();
        if ($app) {
            $app->realtime()->broadcast('queue', [
                'event' => 'queue.job',
                'status' => 'failed',
                'job_id' => $jobId,
                'url_id' => $urlId,
                'viewport_label' => $viewportLabel,
                'error' => $e->getMessage(),
            ]);
        }
        return ['job_id' => $jobId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}

function backfillSelectorIds(ProjectState $state, int $limit = 500): int
{
    $pdo = $state->database->connection();
    $stmt = $pdo->prepare(
        'SELECT id, selector
         FROM issue_elements
         WHERE project_id = :project_id
           AND selector_id IS NULL AND selector IS NOT NULL AND selector != ""
         LIMIT :limit'
    );
    $stmt->bindValue(':project_id', $state->projectId);
    $stmt->bindValue(':limit', $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }
    $selectorRepo = new SelectorRepository($pdo);
    $update = $pdo->prepare('UPDATE issue_elements SET selector_id = :selector_id WHERE id = :id');
    $cache = [];
    $updated = 0;
    foreach ($rows as $row) {
        $selector = (string)($row['selector'] ?? '');
        if ($selector === '') {
            continue;
        }
        if (!isset($cache[$selector])) {
            $cache[$selector] = $selectorRepo->getOrCreate($selector);
        }
        $selectorId = $cache[$selector];
        if ($selectorId <= 0) {
            continue;
        }
        $update->execute([
            'selector_id' => $selectorId,
            'id' => (int)$row['id'],
        ]);
        $updated++;
    }
    return $updated;
}

function recomputeUniqueMetricsCache(ProjectState $state): void
{
    $config = $state->config;
    $reportType = (int)($config->get('reporttype') ?? 4);
    if ($reportType < 3) {
        return;
    }
    $viewports = $state->viewports->listAllLabels();
    if (empty($viewports)) {
        $viewports = ['default'];
    }
    $cache = new MetricsCacheRepository($state->database->connection(), $state->projectId);
    $issues = $state->issues;
    $groups = [];
    $all = $viewports;
    sort($all);
    $groups[] = $all;
    foreach ($viewports as $vp) {
        $groups[] = [$vp];
    }
    foreach ($groups as $vpList) {
        $key = 'unique|' . implode(',', $vpList);
        $errors = $issues->countGlobalUniqueByCategory('error', $vpList);
        $contrast = $issues->countGlobalUniqueByCategory('contrast', $vpList);
        $alerts = $issues->countGlobalUniqueByCategory('alert', $vpList);
        $cache->set($key, $errors, $contrast, $alerts);
    }
}
