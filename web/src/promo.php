<?php
declare(strict_types=1);

/** 拉新（夸克转存再分享）的数据层：任务 + Cookie 账号都落库，不碰命令行。 */

function promo_tables_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM promo_tasks LIMIT 0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function promo_migrate(): void
{
    $pdo = db();
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS promo_tasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id  TEXT NOT NULL,
    title        TEXT NOT NULL DEFAULT '',
    source_url   TEXT NOT NULL DEFAULT '',
    source_pwd   TEXT NOT NULL DEFAULT '',
    provider     TEXT NOT NULL DEFAULT 'quark',
    status       TEXT NOT NULL DEFAULT 'pending',
    target_url   TEXT NOT NULL DEFAULT '',
    target_pwd   TEXT NOT NULL DEFAULT '',
    account_id   INTEGER,
    risk_level   TEXT NOT NULL DEFAULT '',
    error        TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL,
    done_at      TEXT
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promo_tasks_status ON promo_tasks(status, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_promo_tasks_resource ON promo_tasks(resource_id)');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS promo_accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    provider   TEXT NOT NULL DEFAULT 'quark',
    label      TEXT NOT NULL DEFAULT '',
    cookie     TEXT NOT NULL,
    enabled    INTEGER NOT NULL DEFAULT 1,
    last_used  TEXT,
    note       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL);
}

/** 从一条资源生成拉新任务；同资源重复创建返回已有任务 id */
function promo_task_create(array $row, string $risk = ''): int
{
    promo_migrate();
    $exists = db()->prepare('SELECT id FROM promo_tasks WHERE resource_id = ? AND status NOT IN (' . "'failed','canceled'" . ') LIMIT 1');
    if (!$exists) {
        return 0;
    }
    $exists->execute([(string) ($row['id'] ?? '')]);
    $hit = $exists->fetchColumn();
    if ($hit !== false) {
        return (int) $hit;
    }
    $stmt = db()->prepare('INSERT INTO promo_tasks(resource_id,title,source_url,source_pwd,provider,risk_level,created_at)
        VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([
        (string) ($row['id'] ?? ''), mb_substr((string) ($row['title'] ?? ''), 0, 120),
        (string) ($row['share_url'] ?? ''), (string) ($row['pwd'] ?? ''),
        (string) ($row['provider'] ?? 'quark'), $risk, now_iso(),
    ]);
    return (int) db()->lastInsertId();
}

function promo_task_list(string $status, int $limit = 100): array
{
    if (!promo_tables_ready()) {
        return [];
    }
    $sql = 'SELECT t.*, a.label AS account_label FROM promo_tasks t
        LEFT JOIN promo_accounts a ON a.id = t.account_id';
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE t.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY t.id DESC LIMIT ' . max(1, min($limit, 200));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function promo_task_counts(): array
{
    if (!promo_tables_ready()) {
        return [];
    }
    $out = ['pending' => 0, 'done' => 0, 'executing' => 0, 'failed' => 0, 'canceled' => 0];
    foreach (db()->query('SELECT status, COUNT(*) c FROM promo_tasks GROUP BY status') as $r) {
        $out[$r['status']] = (int) $r['c'];
    }
    return $out;
}

function promo_done_today(): int
{
    if (!promo_tables_ready()) {
        return 0;
    }
    return (int) db()->query("SELECT COUNT(*) FROM promo_tasks WHERE status = 'done' AND substr(done_at,1,10) = date('now')")->fetchColumn();
}

/** 今日还能执行的配额 */
function promo_quota_left(): int
{
    $limit = cfg_get_int('promo_daily_limit');
    if ($limit <= 0) {
        return PHP_INT_MAX;
    }
    return max(0, $limit - promo_done_today());
}

function promo_task_cancel(int $id): void
{
    db()->prepare("UPDATE promo_tasks SET status = 'canceled' WHERE id = ? AND status IN ('pending','failed')")->execute([$id]);
}

function promo_task_retry(int $id): void
{
    db()->prepare("UPDATE promo_tasks SET status = 'pending', error = '' WHERE id = ? AND status IN ('failed','canceled')")->execute([$id]);
}

/** 账号 */
function promo_accounts(bool $onlyEnabled = false): array
{
    promo_migrate();
    $sql = 'SELECT * FROM promo_accounts' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY id DESC';
    return db()->query($sql)->fetchAll();
}

function promo_account_save(array $f): string
{
    promo_migrate();
    $provider = (string) ($f['provider'] ?? 'quark');
    $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 60);
    $cookie = trim((string) ($f['cookie'] ?? ''));
    $note = mb_substr(trim((string) ($f['note'] ?? '')), 0, 200);
    $enabled = ($f['enabled'] ?? '1') === '1' ? 1 : 0;
    $id = (int) ($f['id'] ?? 0);
    if ($cookie === '') {
        return 'Cookie 不能为空';
    }
    if ($id > 0) {
        db()->prepare('UPDATE promo_accounts SET provider = ?, label = ?, cookie = ?, enabled = ? WHERE id = ?')
            ->execute([$provider, $label, $cookie, $enabled, $id]);
    } else {
        db()->prepare('INSERT INTO promo_accounts(provider,label,cookie,enabled,created_at) VALUES(?,?,?,?,?)')
            ->execute([$provider, $label, $cookie, $enabled, now_iso()]);
    }
    return '';
}

function promo_account_toggle(int $id): void
{
    promo_migrate();
    db()->prepare('UPDATE promo_accounts SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
}

function promo_mask_cookie(string $cookie): string
{
    if (mb_strlen($cookie) <= 16) {
        return str_repeat('*', 8);
    }
    return mb_substr($cookie, 0, 6) . '…' . mb_substr($cookie, -4);
}

/** 取资源原始行（含下架，后台需要用） */
function promo_resource(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM resources WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 单个任务执行：转存 → 再分享。返回给后台显示的一句话结果 */
function promo_run_one(int $taskId): string
{
    require_once __DIR__ . '/quark.php';
    promo_migrate();

    $stmt = db()->prepare('SELECT * FROM promo_tasks WHERE id = ?');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        return '任务不存在';
    }
    if (!in_array($task['status'], ['pending', 'failed'], true)) {
        return '任务状态不允许执行（当前 ' . $task['status'] . '）';
    }
    if (cfg_get('promo_open') !== '1') {
        return '拉新总开关未开启';
    }
    if (promo_quota_left() <= 0) {
        return '今日配额已用完';
    }

    $account = null;
    foreach (promo_accounts(true) as $a) {
        if ($a['provider'] === $task['provider']) {
            $account = $a;
            break;
        }
    }
    if ($account === null) {
        db()->prepare("UPDATE promo_tasks SET status = 'failed', error = ? WHERE id = ?")
            ->execute(['没有启用的 ' . $task['provider'] . ' 账号', $taskId]);
        return '转存失败：没有启用的夸克账号，先去「网盘账号」添加 Cookie';
    }

    db()->prepare("UPDATE promo_tasks SET status = 'executing', account_id = ?, error = '' WHERE id = ?")
        ->execute([(int) $account['id'], $taskId]);

    try {
        $client = new QuarkClient((string) $account['cookie'], (string) cfg('repo.proxy', ''));
        $pwdId = quark_pwd_id((string) $task['source_url']);
        $pwd = (string) ($task['source_pwd'] ?? '');
        $tok = $client->shareToken($pwdId, $pwd);
        $files = $client->listFiles($pwdId, $tok['stoken']);
        if ($files === []) {
            throw new QuarkError('分享里没有可转存的文件');
        }
        $saveTask = $client->save($pwdId, $tok['stoken'], $files);
        $result = $client->waitTask($saveTask);
        $fid = quark_first_fid($result);
        if ($fid === '') {
            throw new QuarkError('转存成功但拿不到新文件 fid，无法再分享');
        }
        $shareTask = $client->createShare($fid, (string) $task['title']);
        $shareId = $client->shareIdOf($shareTask);
        [$url, $newPwd] = $client->publish($shareId);

        db()->prepare("UPDATE promo_tasks SET status = 'done', target_url = ?, target_pwd = ?, done_at = ? WHERE id = ?")
            ->execute([$url, (string) ($newPwd ?? ''), now_iso(), $taskId]);
        db()->prepare('UPDATE promo_accounts SET last_used = ? WHERE id = ?')
            ->execute([now_iso(), (int) $account['id']]);
        repo_log('promo', true, "转存成功 #{$taskId} -> {$url}");
        return '转存成功：' . $url . ($newPwd ? '（码 ' . $newPwd . '）' : '');
    } catch (Throwable $e) {
        $msg = mb_substr($e->getMessage(), 0, 300);
        db()->prepare("UPDATE promo_tasks SET status = 'failed', error = ? WHERE id = ?")->execute([$msg, $taskId]);
        repo_log('promo', false, "转存失败 #{$taskId}：{$msg}");
        return '转存失败：' . $msg;
    }
}

/** 批量执行待处理任务，受每日配额与最小间隔限制 @return array{0:int,1:int,2:string} */
function promo_run_pending(int $limit = 5): array
{
    promo_migrate();
    $left = promo_quota_left();
    if ($left <= 0) {
        return [0, 0, '今日配额已用完'];
    }
    $limit = min($limit, $left);
    $stmt = db()->prepare("SELECT id FROM promo_tasks WHERE status = 'pending' ORDER BY id LIMIT {$limit}");
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($ids === []) {
        return [0, 0, '没有待执行任务'];
    }
    $gap = cfg_get_int('promo_min_interval');
    $ok = 0;
    $fail = 0;
    foreach ($ids as $i => $id) {
        if ($i > 0 && $gap > 0) {
            sleep($gap);
        }
        $msg = promo_run_one((int) $id);
        if (str_starts_with($msg, '转存成功')) {
            $ok++;
        } else {
            $fail++;
            if (str_contains($msg, 'Cookie') || str_contains($msg, '账号')) {
                break;
            }
        }
    }
    return [$ok, $fail, "配额剩余 " . max(0, promo_quota_left()) . " 条"];
}
