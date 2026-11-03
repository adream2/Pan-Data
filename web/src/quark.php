<?php
declare(strict_types=1);

/**
 * 夸克网盘客户端（PHP 版）：转存别人分享 → 在自己账号生成新分享链接。
 * 接口与 collector/quark_client.py 保持一致，方便两端对账。
 */

const QUARK_BASE = 'https://drive-pc.quark.cn/1/clouddrive';
const QUARK_SAVE_BASE = 'https://drive.quark.cn/1/clouddrive';
const QUARK_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

final class QuarkError extends RuntimeException
{
    public array $payload;

    public function __construct(string $message, array $payload = [])
    {
        parent::__construct($message);
        $this->payload = $payload;
    }
}

final class QuarkClient
{
    private string $cookie;
    private string $proxy;

    public function __construct(string $cookie, string $proxy = '')
    {
        if (trim($cookie) === '') {
            throw new QuarkError('缺少 Cookie（浏览器登录夸克后复制整条 Cookie）');
        }
        $this->cookie = $cookie;
        $this->proxy = $proxy;
    }

    private function call(string $method, string $url, array $params = [], ?array $body = null): array
    {
        $query = array_merge(['pr' => 'ucpro', 'fr' => 'pc', 'uc_param_str' => ''], $params);
        $full = $url . '?' . http_build_query($query);
        $ch = curl_init($full);
        $headers = [
            'cookie: ' . $this->cookie,
            'user-agent: ' . QUARK_UA,
            'accept: application/json, text/plain, */*',
            'origin: https://pan.quark.cn',
            'referer: https://pan.quark.cn/',
            'content-type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        if ($this->proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) {
            throw new QuarkError('网络错误：' . ($err ?: 'curl 失败'));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new QuarkError('接口返回非 JSON（HTTP ' . $code . '）', ['body' => mb_substr((string) $raw, 0, 200)]);
        }
        $status = $data['status'] ?? 200;
        $rc = $data['code'] ?? 0;
        if (!in_array((int) $status, [200, 0], true) || !in_array((int) $rc, [0], true)) {
            throw new QuarkError('接口返回 ' . $rc . '：' . ($data['message'] ?? '未知错误'), $data);
        }
        return $data;
    }

    /** @return array{stoken:string, title:string, file_num:int} */
    public function shareToken(string $pwdId, string $passcode = ''): array
    {
        $p = $this->call('POST', QUARK_BASE . '/share/sharepage/token', [], [
            'pwd_id' => $pwdId,
            'passcode' => $passcode,
            'support_visit_limit_private_share' => true,
        ]);
        $d = $p['data'] ?? [];
        if (empty($d['stoken'])) {
            throw new QuarkError('拿不到 stoken（分享可能已失效或需要提取码）', $p);
        }
        return [
            'stoken'   => (string) $d['stoken'],
            'title'    => (string) ($d['title'] ?? ''),
            'file_num' => (int) ($d['file_num'] ?? 0),
        ];
    }

    /** @return array<int, array{fid:string, token:string, name:string}> */
    public function listFiles(string $pwdId, string $stoken, string $pdirFid = '0'): array
    {
        $p = $this->call('GET', QUARK_BASE . '/share/sharepage/detail', [
            'pwd_id' => $pwdId, 'stoken' => $stoken, 'pdir_fid' => $pdirFid,
            'force' => '0', '_page' => '1', '_size' => '100',
            '_fetch_banner' => '0', '_fetch_share' => '0', '_fetch_total' => '1',
            '_sort' => 'file_type:asc,updated_at:desc',
        ]);
        $out = [];
        foreach (($p['data']['list'] ?? []) as $i) {
            if (!empty($i['fid'])) {
                $out[] = [
                    'fid'   => (string) $i['fid'],
                    'token' => (string) ($i['share_fid_token'] ?? ''),
                    'name'  => (string) ($i['file_name'] ?? ''),
                ];
            }
        }
        return $out;
    }

    public function save(string $pwdId, string $stoken, array $files, string $toFid = '0'): string
    {
        $p = $this->call('POST', QUARK_SAVE_BASE . '/share/sharepage/save', [
            '__dt' => (string) random_int(600, 9999),
            '__t'  => (string) random_int(1000000000000, 9999999999999),
        ], [
            'fid_list'       => array_column($files, 'fid'),
            'fid_token_list' => array_column($files, 'token'),
            'to_pdir_fid'    => $toFid,
            'pwd_id'         => $pwdId,
            'stoken'         => $stoken,
            'pdir_fid'       => '0',
            'scene'          => 'link',
        ]);
        $taskId = (string) ($p['data']['task_id'] ?? '');
        if ($taskId === '') {
            throw new QuarkError('转存任务创建失败', $p);
        }
        return $taskId;
    }

    public function waitTask(string $taskId, int $retries = 20): array
    {
        $last = [];
        for ($i = 0; $i < $retries; $i++) {
            $p = $this->call('GET', QUARK_BASE . '/task', ['task_id' => $taskId, 'retry_index' => (string) $i]);
            $d = $p['data'] ?? [];
            $last = $d;
            $st = (int) ($d['status'] ?? 0);
            if ($st === 2) {
                return $d;
            }
            if ($st === 3) {
                throw new QuarkError('任务失败：' . ($d['message'] ?? '未知原因'), $p);
            }
            usleep(900000);
        }
        throw new QuarkError('任务超时', ['task' => $last]);
    }

    public function createShare(string $fid, string $title, int $expireDays = 0): string
    {
        $expire = [0 => 1, 1 => 2, 7 => 3, 30 => 4][$expireDays] ?? 1;
        $p = $this->call('POST', QUARK_BASE . '/share', [], [
            'fid_list' => [$fid],
            'title'    => mb_substr($title, 0, 60),
            'url_type' => 1,
            'expired_type' => $expire,
        ]);
        $taskId = (string) ($p['data']['task_id'] ?? '');
        if ($taskId === '') {
            throw new QuarkError('创建分享任务失败', $p);
        }
        return $taskId;
    }

    public function shareIdOf(string $taskId, int $retries = 15): string
    {
        for ($i = 0; $i < $retries; $i++) {
            $p = $this->call('GET', QUARK_BASE . '/task', ['task_id' => $taskId, 'retry_index' => (string) $i]);
            $sid = (string) ($p['data']['share_id'] ?? '');
            if ($sid !== '') {
                return $sid;
            }
            usleep(800000);
        }
        throw new QuarkError('分享任务没有返回 share_id');
    }

    /** @return array{0:string,1:?string} [url, passcode] */
    public function publish(string $shareId): array
    {
        $p = $this->call('POST', QUARK_BASE . '/share/password', [], ['share_id' => $shareId]);
        $d = $p['data'] ?? [];
        $url = (string) ($d['share_url'] ?? '');
        if ($url === '') {
            throw new QuarkError('取分享链接失败', $p);
        }
        return [$url, ($d['passcode'] ?? null) ?: null];
    }
}

function quark_pwd_id(string $url): string
{
    if (!preg_match('#/s/([0-9A-Za-z]+)#', $url, $m)) {
        throw new QuarkError('不是夸克分享链接：' . $url);
    }
    return $m[1];
}

function quark_first_fid(array $result): string
{
    foreach (['save_as', 'file_list'] as $key) {
        $v = $result[$key] ?? null;
        if (is_array($v) && $v !== []) {
            if (isset($v['fid']) && $v['fid'] !== '') {
                return (string) $v['fid'];
            }
            $first = $v[0] ?? null;
            if (is_array($first) && !empty($first['fid'])) {
                return (string) $first['fid'];
            }
        }
    }
    return '';
}
