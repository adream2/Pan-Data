<?php
declare(strict_types=1);

/** 建表 + 迁移。可重复执行。 */
function schema_migrate(): array
{
    $pdo = db();
    $done = [];

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS resources (
    id           TEXT PRIMARY KEY,
    title        TEXT NOT NULL,
    core_title   TEXT NOT NULL DEFAULT '',
    type         TEXT NOT NULL DEFAULT 'other',
    year         INTEGER,
    season       INTEGER,
    episode      INTEGER,
    complete     INTEGER NOT NULL DEFAULT 0,
    provider     TEXT NOT NULL,
    share_url    TEXT NOT NULL,
    pwd          TEXT,
    status       TEXT NOT NULL DEFAULT 'unchecked',
    checked_at   TEXT,
    sources      INTEGER NOT NULL DEFAULT 1,
    mirrors_json TEXT NOT NULL DEFAULT '[]',
    channel      TEXT,
    post_url     TEXT,
    posted_at    TEXT,
    desc         TEXT NOT NULL DEFAULT '',
    hidden       INTEGER NOT NULL DEFAULT 0,
    featured     INTEGER NOT NULL DEFAULT 0,
    origin       TEXT NOT NULL DEFAULT 'repo',
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);
SQL);
    $done[] = 'resources';

    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_res_posted ON resources(posted_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_res_type ON resources(type)',
        'CREATE INDEX IF NOT EXISTS idx_res_provider ON resources(provider)',
        'CREATE INDEX IF NOT EXISTS idx_res_status ON resources(status)',
        'CREATE INDEX IF NOT EXISTS idx_res_hidden ON resources(hidden)',
    ] as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS submissions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT NOT NULL,
    share_url     TEXT NOT NULL,
    pwd           TEXT,
    note          TEXT,
    contact       TEXT,
    ip            TEXT,
    status        TEXT NOT NULL DEFAULT 'pending',
    reject_reason TEXT,
    resource_id   TEXT,
    created_at    TEXT NOT NULL,
    reviewed_at   TEXT
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sub_status ON submissions(status, created_at DESC)');
    $done[] = 'submissions';

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    pass_hash     TEXT NOT NULL,
    created_at    TEXT NOT NULL,
    last_login_at TEXT
);
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    kind       TEXT NOT NULL,
    ok         INTEGER NOT NULL DEFAULT 1,
    message    TEXT NOT NULL DEFAULT '',
    stats_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
SQL);
    $done[] = 'logs';

    // 全文索引：trigram 分词对中文友好（≥3 字走 FTS，1-2 字回退 LIKE）
    try {
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS resources_fts USING fts5(
            id UNINDEXED, title, core_title, desc, tokenize='trigram'
        )");
        $done[] = 'fts5';
    } catch (Throwable $e) {
        $done[] = 'fts5_unavailable';
    }

    return $done;
}

function schema_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM resources LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 增量升级：举报、点击统计、搜索日志、网盘账号（PRAGMA user_version 控制）
 *
 * 2026-11 起不再有前台用户体系：站点只服务匿名访客，管理只有一个站长账号（admins）。
 * users / favorites 两张表已从建表语句里移除；老库里残留的表不影响运行。
 */
function schema_upgrade(): void
{
    $version = (int) db()->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 2) {
        return;
    }
    $pdo = db();
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reports (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id TEXT NOT NULL,
    kind        TEXT NOT NULL DEFAULT 'invalid',
    note        TEXT,
    user_id     INTEGER,
    ip          TEXT,
    status      TEXT NOT NULL DEFAULT 'open',
    created_at  TEXT NOT NULL
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, created_at DESC)');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pan_accounts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    provider     TEXT NOT NULL,
    label        TEXT NOT NULL DEFAULT '',
    cookie       TEXT NOT NULL,
    enabled      INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT,
    note         TEXT,
    created_at   TEXT NOT NULL
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS clicks (
    resource_id TEXT NOT NULL,
    day         TEXT NOT NULL,
    count       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (resource_id, day)
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS search_logs (
    q    TEXT NOT NULL,
    day  TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (q, day)
)
SQL);
    $pdo->exec('PRAGMA user_version = 2');
}
