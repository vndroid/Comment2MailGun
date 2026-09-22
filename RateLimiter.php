<?php

namespace TypechoPlugin\Comment2MailGun;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 基于 Redis 的邮件发送限流（可选组件）
 *
 * 三个维度，全部在一段 Lua 里原子地「检查 + 占位 + 计数」，不存在检查与写入之间的竞态：
 *  - rcpt：同一收件人在 guestInterval 秒内最多一封访客通知；以真实发送结果为准
 *          （发送中占位 → 成功后改为 sent 并延长到整个窗口 → 失败后改为短暂的 retry 冷却）
 *  - site：全站每小时外发总量（博主通知与访客通知都计入）
 *  - src ：同一来源（登录用户按 uid，访客按 IP）每小时触发的访客通知数
 *
 * 键名：plugin:comment2mailgun:{站点指纹}:...
 * 指纹放在 {} 里同时充当 Redis Cluster 的 hash tag，保证一段脚本涉及的键落在同一个 slot。
 * 收件人邮箱与来源只以 sha256 形式出现在键名中，Redis 里不保存明文。
 */
class RateLimiter
{
    public const BASE = 'plugin:comment2mailgun:';

    /** 发送中占位的 TTL：须大于一次发送的最长耗时（一言 5s + MailGun 15s） */
    private const PENDING_TTL = 60;

    /** 发送失败后的重试冷却 */
    private const RETRY_TTL = 60;

    /** 小时计数键的 TTL：比窗口略长，保证整点前后不会提前丢失 */
    private const HOUR_TTL = 3660;

    private const CONNECT_TIMEOUT = 1.0;
    private const READ_TIMEOUT = 1.0;

    public const OK = 'ok';
    public const DENY_RCPT = 'rcpt';
    public const DENY_SITE = 'site';
    public const DENY_SRC = 'src';
    public const UNAVAILABLE = 'unavailable';

    /**
     * KEYS[1] = 收件人键（ARGV[2] 为 0 时不使用）；KEYS[2..n] = 计数键
     * ARGV[1] = 占位 token；ARGV[2] = 占位 TTL；ARGV[3..n+1] = 各计数键上限（0 = 不限）；ARGV[n+2] = 计数键 TTL
     * 返回 'ok' / 'rcpt' / 'q2'..'qn'（第几个计数键超限）
     */
    private const ACQUIRE_LUA = <<<'LUA'
local pending = tonumber(ARGV[2])
if pending > 0 and redis.call('EXISTS', KEYS[1]) == 1 then
    return 'rcpt'
end
local n = #KEYS
for i = 2, n do
    local limit = tonumber(ARGV[i + 1])
    if limit > 0 and tonumber(redis.call('GET', KEYS[i]) or '0') >= limit then
        return 'q' .. i
    end
end
if pending > 0 then
    redis.call('SET', KEYS[1], ARGV[1], 'EX', pending)
end
local ttl = tonumber(ARGV[n + 2])
for i = 2, n do
    if redis.call('INCR', KEYS[i]) == 1 then
        redis.call('EXPIRE', KEYS[i], ttl)
    end
end
return 'ok'
LUA;

    /** 仅当占位仍属于自己时才改写：超时的发送不能覆盖后来者的占位 */
    private const FINISH_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('SET', KEYS[1], ARGV[2], 'EX', tonumber(ARGV[3]))
    return 1
end
return 0
LUA;

    private ?\Redis $redis = null;
    private bool $failed = false;
    private string $prefix;

    private function __construct(
        private string $host,
        private int $port,
        private string $password,
        private int $database,
        private int $guestInterval,
        private int $siteHourly,
        private int $sourceHourly
    ) {
        $this->prefix = self::BASE . '{' . self::fingerprint() . '}:';
    }

    /**
     * 未启用 Redis 时返回 null，调用方据此跳过全部限流
     */
    public static function fromSettings($settings): ?self
    {
        if ('1' !== (string)$settings->redisEnable) {
            return null;
        }

        return new self(
            trim((string)$settings->redisHost) ?: '127.0.0.1',
            Plugin::intSetting($settings->redisPort, 6379, 1, 65535),
            (string)$settings->redisPassword,
            Plugin::intSetting($settings->redisDb, 0, 0, 255),
            Plugin::intSetting($settings->guestInterval, 600, 0, 86400),
            Plugin::intSetting($settings->siteHourly, 200, 0, 100000),
            Plugin::intSetting($settings->sourceHourly, 20, 0, 100000)
        );
    }

    /**
     * 站点指纹：同一个 Redis 库承载多个站点时互不干扰（与 Access 插件同一做法）
     */
    public static function fingerprint(): string
    {
        return substr(md5(__DIR__), 0, 12);
    }

    /**
     * 访客通知：检查收件人窗口、全站额度、来源额度并占位
     *
     * @return array{0: string, 1: string} [结果, token]；结果为 OK 时 token 用于 finishGuest()
     */
    public function acquireGuest(string $mail, string $source): array
    {
        $token = bin2hex(random_bytes(16));
        $result = $this->acquire(
            $this->rcptKey($mail),
            $this->guestInterval > 0 ? self::PENDING_TTL : 0,
            $token,
            [$this->siteKey() => $this->siteHourly, $this->sourceKey($source) => $this->sourceHourly],
            [2 => self::DENY_SITE, 3 => self::DENY_SRC]
        );

        return [$result, $token];
    }

    /**
     * 博主通知：只计入全站额度
     */
    public function acquireOwner(): string
    {
        return $this->acquire(
            $this->prefix . 'rcpt:-',
            0,
            '',
            [$this->siteKey() => $this->siteHourly],
            [2 => self::DENY_SITE]
        );
    }

    /**
     * 访客通知发送结束：成功 → 窗口内不再发送；失败 → 短暂冷却后允许重试
     */
    public function finishGuest(string $mail, string $token, bool $sent): void
    {
        if ($this->guestInterval <= 0 || $token === '') {
            return;
        }

        [$value, $ttl] = $sent
            ? ['sent', $this->guestInterval]
            : ['retry', min(self::RETRY_TTL, $this->guestInterval)];

        try {
            $redis = $this->connection();
            if ($redis !== null) {
                $redis->eval(self::FINISH_LUA, [$this->rcptKey($mail), $token, $value, $ttl], 1);
            }
        } catch (\Throwable $e) {
            // 占位会在 PENDING_TTL 后自然过期，这里不影响本次发送结果
            error_log('[Comment2MailGun] Redis 写回发送结果失败：' . $e->getMessage());
        }
    }

    /**
     * 连通性自检，供保存配置时提示使用
     *
     * @return string|null null 表示可用，否则为错误原因
     */
    public function probe(): ?string
    {
        if (!extension_loaded('redis')) {
            return _t('PHP 未安装 redis 扩展');
        }

        try {
            $redis = $this->connect();
            $redis->ping();
            $redis->close();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function acquire(string $rcptKey, int $pendingTtl, string $token, array $counters, array $names): string
    {
        try {
            $redis = $this->connection();
            if ($redis === null) {
                return self::UNAVAILABLE;
            }

            $keys = array_merge([$rcptKey], array_keys($counters));
            $args = array_merge([$token, $pendingTtl], array_values($counters), [self::HOUR_TTL]);
            $result = $redis->eval(self::ACQUIRE_LUA, array_merge($keys, $args), count($keys));
        } catch (\Throwable $e) {
            error_log('[Comment2MailGun] Redis 限流脚本执行失败：' . $e->getMessage());
            return self::UNAVAILABLE;
        }

        if ($result === self::OK || $result === self::DENY_RCPT) {
            return $result;
        }
        if (is_string($result) && preg_match('/^q(\d+)$/', $result, $m) && isset($names[(int)$m[1]])) {
            return $names[(int)$m[1]];
        }

        // eval 返回 false 等意外值：说明没有真正执行，视为不可用
        return self::UNAVAILABLE;
    }

    private function connection(): ?\Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        if ($this->failed || !extension_loaded('redis')) {
            return null;
        }

        try {
            return $this->redis = $this->connect();
        } catch (\Throwable $e) {
            // 同一次请求内只尝试连接一次，不让每个调用点各吃一遍超时
            $this->failed = true;
            error_log('[Comment2MailGun] 无法连接 Redis ' . $this->host . ':' . $this->port . '：' . $e->getMessage());
            return null;
        }
    }

    private function connect(): \Redis
    {
        $redis = new \Redis();
        // 第 5 个参数是重连间隔，给 0；第 6 个参数是读超时（phpredis 默认 0 = 无限期阻塞，必须显式设置）
        if (!@$redis->connect($this->host, $this->port, self::CONNECT_TIMEOUT, null, 0, self::READ_TIMEOUT)) {
            throw new \RuntimeException('连接失败');
        }
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::READ_TIMEOUT);
        if ($this->password !== '') {
            $redis->auth($this->password);
        }
        if ($this->database > 0) {
            $redis->select($this->database);
        }

        return $redis;
    }

    private function rcptKey(string $mail): string
    {
        return $this->prefix . 'rcpt:' . hash('sha256', strtolower(trim($mail)));
    }

    private function siteKey(): string
    {
        return $this->prefix . 'quota:site:' . intdiv(time(), 3600);
    }

    private function sourceKey(string $source): string
    {
        return $this->prefix . 'quota:src:' . intdiv(time(), 3600) . ':' . hash('sha256', $source);
    }
}
