<?php
/**
 * PUBG 数据服务层。
 *
 * 负责官方 API 代理、服务端 Key 池限流、文件缓存、赛季解析和统计聚合。
 * API Key 只允许来自服务器环境变量或私有 PHP 配置文件，避免被提交到代码仓库或输出到浏览器。
 */

$allowedShards = ['steam', 'kakao', 'xbox', 'psn'];
$selectedShard = (isset($_GET['shard']) && in_array($_GET['shard'], $allowedShards, true)) ? $_GET['shard'] : 'steam';

const PUBG_KEY_RPM = 10;
const PUBG_RATE_INTERVAL_US = 6000000;
const PUBG_HTTP_TIMEOUT_SEC = 10;
const PUBG_HTTP_CONNECT_TIMEOUT_SEC = 4;

function normalizePubgApiKeys($rawKeys): array {
    if (is_string($rawKeys)) {
        $rawKeys = preg_split('/[\r\n,;]+/', $rawKeys);
    }
    if (!is_array($rawKeys)) {
        return [];
    }

    $keys = [];
    foreach ($rawKeys as $key) {
        $key = trim((string)$key);
        if ($key !== '' && preg_match('/^[A-Za-z0-9_\-.]+$/', $key)) {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

function loadPubgApiKeys(): array {
    $keys = normalizePubgApiKeys(getenv('PUBG_API_KEYS') ?: '');
    if (!empty($keys)) {
        return $keys;
    }

    $configuredFile = getenv('PUBG_API_KEYS_FILE') ?: '';
    $candidateFiles = array_filter([
        $configuredFile,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'pubg_keys.php',
    ]);

    foreach ($candidateFiles as $file) {
        if (!is_file($file)) {
            continue;
        }
        $loaded = require $file;
        $keys = normalizePubgApiKeys($loaded);
        if (!empty($keys)) {
            return $keys;
        }
    }

    return [];
}

function normalizeSteamHelpCookie($rawCookie): string {
    $cookie = trim(str_replace(["\r", "\n"], '', (string)$rawCookie));
    if ($cookie === '' || strlen($cookie) > 12000) {
        return '';
    }
    return preg_match('/\b(steamLoginSecure|steamRememberLogin|sessionid)=/i', $cookie) ? $cookie : '';
}

function normalizeSteamHelpAccountId($accountId): string {
    $accountId = trim((string)$accountId);
    return preg_match('/^account\.[A-Za-z0-9_-]+$/', $accountId) ? $accountId : '';
}

function normalizeSteamHelpCronToken($token): string {
    $token = trim(str_replace(["\r", "\n"], '', (string)$token));
    if ($token === '' || strlen($token) < 24 || strlen($token) > 256) {
        return '';
    }
    return preg_match('/^[A-Za-z0-9_\-.:]+$/', $token) ? $token : '';
}

function loadSteamHelpConfig(): array {
    $config = [
        'sessions'     => [],
        'poll_seconds' => 180,
        'timeout'      => 8,
        'cron_token'   => normalizeSteamHelpCronToken(getenv('STEAM_HELP_CRON_TOKEN') ?: ''),
    ];

    $envCookie = normalizeSteamHelpCookie(getenv('STEAM_HELP_COOKIE') ?: '');
    $envAccountId = normalizeSteamHelpAccountId(getenv('STEAM_HELP_ACCOUNT_ID') ?: '');
    if ($envCookie !== '' && $envAccountId !== '') {
        $config['sessions'][$envAccountId] = ['cookie' => $envCookie];
    }

    $configFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'steam_help.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            if (isset($loaded['sessions']) && is_array($loaded['sessions'])) {
                foreach ($loaded['sessions'] as $accountId => $session) {
                    $accountId = normalizeSteamHelpAccountId($accountId);
                    $cookie = is_array($session)
                        ? normalizeSteamHelpCookie($session['cookie'] ?? '')
                        : normalizeSteamHelpCookie($session);
                    if ($accountId !== '' && $cookie !== '') {
                        $config['sessions'][$accountId] = ['cookie' => $cookie];
                    }
                }
            } elseif (isset($loaded['account_id'], $loaded['cookie'])) {
                $accountId = normalizeSteamHelpAccountId($loaded['account_id']);
                $cookie = normalizeSteamHelpCookie($loaded['cookie']);
                if ($accountId !== '' && $cookie !== '') {
                    $config['sessions'][$accountId] = ['cookie' => $cookie];
                }
            }
            if (isset($loaded['poll_seconds'])) {
                $config['poll_seconds'] = max(180, min(900, (int)$loaded['poll_seconds']));
            }
            if (isset($loaded['timeout'])) {
                $config['timeout'] = max(4, min(12, (int)$loaded['timeout']));
            }
            if (isset($loaded['cron_token'])) {
                $config['cron_token'] = normalizeSteamHelpCronToken($loaded['cron_token']);
            }
        }
    }

    return $config;
}

$apiKeys = loadPubgApiKeys();
$steamHelpConfig = loadSteamHelpConfig();

$apiConfig = [
    'baseUrl' => 'https://api.pubg.com',
    'shard'   => $selectedShard
];

$platIcons = [
    'steam' => 'https://p0.meituan.net/poiugc/620e3c83b0c01d086334433e0185ae772320.png',
    'kakao' => 'https://p0.meituan.net/poiugc/b3bb75065fd06aac94e9157e66244a7e2120.png',
    'xbox'  => 'https://p0.meituan.net/poiugc/8cd37b70df73fb9d97fbde54848e73e22101.png',
    'psn'   => 'https://p0.meituan.net/poiugc/c1017cfed28479f0680276efeee49f903117.png'
];

$accurateSeasonMonths = [
    '01' => '2018年10月', '02' => '2018年12月', '03' => '2019年03月', '04' => '2019年07月',
    '05' => '2019年10月', '06' => '2020年01月', '07' => '2020年04月', '08' => '2020年07月',
    '09' => '2020年10月', '10' => '2020年12月', '11' => '2021年03月', '12' => '2021年06月',
    '13' => '2021年08月', '14' => '2021年10月', '15' => '2021年12月', '16' => '2022年02月',
    '17' => '2022年04月', '18' => '2022年06月', '19' => '2022年08月', '20' => '2022年10月',
    '21' => '2022年12月', '22' => '2023年02月', '23' => '2023年04月', '24' => '2023年06月',
    '25' => '2023年08月', '26' => '2023年10月', '27' => '2023年12月', '28' => '2024年02月',
    '29' => '2024年04月', '30' => '2024年06月', '31' => '2024年08月', '32' => '2024年10月',
    '33' => '2024年12月', '34' => '2025年02月', '35' => '2025年04月', '36' => '2025年06月',
    '37' => '2025年08月', '38' => '2025年10月', '39' => '2025年12月', '40' => '2026年02月',
    '41' => '2026年04月', '42' => '2026年06月', '43' => '2026年08月', '44' => '2026年10月'
];

$gameModes = [ 
    'squad'     => '四排TPP', 
    'squad-fpp' => '四排FPP', 
    'solo'      => '单排TPP', 
    'solo-fpp'  => '单排FPP', 
    'duo'       => '双排TPP', 
    'duo-fpp'   => '双排FPP' 
];

$rankImages = [
    'Survivor'   => 'https://p0.meituan.net/poiugc/260ae3c9916a91d0fa5baa0e3cf2983359732.webp',
    'Master'     => 'https://p0.meituan.net/poiugc/5797a5d74de8356077adc3d8ec35e39f56556.webp',
    'Diamond_1'  => 'https://p0.meituan.net/poiugc/d179a74d3bb019620d9a6a65398212b061530.webp',
    'Diamond_2'  => 'https://p0.meituan.net/poiugc/5ef1eeadbdb9d6bae087326aed7677a661008.webp',
    'Diamond_3'  => 'https://p0.meituan.net/poiugc/8c8b5681d461a46332fcfbbd2471695a59988.webp',
    'Diamond_4'  => 'https://p0.meituan.net/poiugc/efdca7cf91fd6e06ceb34694135e423062368.webp',
    'Diamond_5'  => 'https://p0.meituan.net/poiugc/17121121cdbb93f68fc3c149d1619fcc59590.webp',
    'Crystal_1'  => 'https://p0.meituan.net/poiugc/b8f5549fc49ac88d60cd0f95d3af58eb66416.webp',
    'Crystal_2'  => 'https://p0.meituan.net/poiugc/ba40b0312e0a7c46006f7c1e69ae4d8567460.webp',
    'Crystal_3'  => 'https://p0.meituan.net/poiugc/a7f4362bcc9ad4b77740c11d4ffe53fe66354.webp',
    'Crystal_4'  => 'https://p0.meituan.net/poiugc/cfafd3815dd07e30e4d687e76246d9a366560.webp',
    'Crystal_5'  => 'https://p0.meituan.net/poiugc/3cd61e400cbcd9c9005b34622bc4964568636.webp',
    'Platinum_1' => 'https://p0.meituan.net/poiugc/db0d17108a409a2f0225a95099325a7956982.webp',
    'Platinum_2' => 'https://p0.meituan.net/poiugc/967b7566f378d0b13bf18b85b00cd0b656708.webp',
    'Platinum_3' => 'https://p0.meituan.net/poiugc/ff8f3f280eabcdd9b99cb6c124c1866957022.webp',
    'Platinum_4' => 'https://p0.meituan.net/poiugc/141305ee9a0ff31f860a14111c1c2e1b57908.webp',
    'Platinum_5' => 'https://p0.meituan.net/poiugc/208376b01e0e11eb1c27897413f9fc5857172.webp',
    'Gold_1'     => 'https://p0.meituan.net/poiugc/99957c9c5b5cfeab669d58b5b779cb4c52876.webp',
    'Gold_2'     => 'https://p0.meituan.net/poiugc/a931716ebfa69b100c9901d6809caec855624.webp',
    'Gold_3'     => 'https://p0.meituan.net/poiugc/6e7dbcb626ba4e5155fd7dbc1af4901e52812.webp',
    'Gold_4'     => 'https://p0.meituan.net/poiugc/fe5a15f742a504c85c9770d734bf632d52806.webp',
    'Gold_5'     => 'https://p0.meituan.net/poiugc/e333f239364c8daabfaa602fb9804d7552360.webp',
    'Silver_1'   => 'https://p0.meituan.net/poiugc/3ef4d4fb7ea33af30b2061074a90374749856.webp',
    'Silver_2'   => 'https://p0.meituan.net/poiugc/a5ca629e4b3a3065e7149fe7a71e44a854216.webp',
    'Silver_3'   => 'https://p0.meituan.net/poiugc/12d889c409ddcf821a95d20fde4172e258802.webp',
    'Silver_4'   => 'https://p0.meituan.net/poiugc/c4a8a9d934929ff65313fac08128f53551334.webp',
    'Silver_5'   => 'https://p0.meituan.net/poiugc/ae2987f32648de68b73b321625d866a851154.webp',
    'Bronze_1'   => 'https://p0.meituan.net/poiugc/c7da860352b425276b3f0c31296c46cc64854.webp',
    'Bronze_2'   => 'https://p0.meituan.net/poiugc/f49e6fdde071d7fb0063d5ee2ffd36d861648.webp',
    'Bronze_3'   => 'https://p0.meituan.net/poiugc/354b77177f9856ceb114c36d4004afa763632.webp',
    'Bronze_4'   => 'https://p0.meituan.net/poiugc/7efe1b29d68c548ddc999a941bc80b0764060.webp',
    'Bronze_5'   => 'https://p0.meituan.net/poiugc/27c83d316391f83ba25e2667a9302bae65950.webp'
];

$tierLabels = [
    'Bronze'      => '青铜', 
    'Silver'      => '白银', 
    'Gold'        => '黄金', 
    'Platinum'    => '铂金', 
    'Crystal'     => '水晶', 
    'Diamond'     => '钻石', 
    'Master'      => '大师', 
    'Grandmaster' => '生存者', 
    'Survivor'    => '生存者'
];

function getCacheDir(string $subDir = ''): string {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache';
    $path = $subDir === '' ? $base : $base . DIRECTORY_SEPARATOR . trim($subDir, DIRECTORY_SEPARATOR);
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    return $path . DIRECTORY_SEPARATOR;
}

function getApiCacheFile(string $url): string {
    return getCacheDir() . hash('sha256', $url) . '.json';
}

function getApiCacheFileCandidates(string $url): array {
    $cacheDir = getCacheDir();
    return [
        $cacheDir . hash('sha256', $url) . '.json',
        $cacheDir . md5($url) . '.json',
    ];
}

function findApiCacheFile(string $url): string {
    foreach (getApiCacheFileCandidates($url) as $file) {
        if (is_file($file)) {
            return $file;
        }
    }
    return getApiCacheFile($url);
}

function readCachedApiResponse(string $url, int $cacheTtl): ?array {
    if ($cacheTtl <= 0) {
        return null;
    }

    $cacheFile = findApiCacheFile($url);
    if (!is_file($cacheFile) || time() - filemtime($cacheFile) >= $cacheTtl) {
        return null;
    }

    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    return is_array($cached) ? ['code' => 200, 'data' => $cached, 'cached' => true] : null;
}

function readStaleApiResponse(string $url): ?array {
    $cacheFile = findApiCacheFile($url);
    if (!is_file($cacheFile)) {
        return null;
    }
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    return is_array($cached) ? ['code' => 200, 'data' => $cached, 'cached' => true, 'stale' => true] : null;
}

function writeCachedApiResponse(string $url, string $response): void {
    $cacheFile = getApiCacheFile($url);
    $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmpFile, $response, LOCK_EX) !== false) {
        @rename($tmpFile, $cacheFile);
    } else {
        @unlink($tmpFile);
    }
}

function getSteamHelpSessionForAccount(string $accountId): ?array {
    global $steamHelpConfig;
    $accountId = normalizeSteamHelpAccountId($accountId);
    if ($accountId === '') {
        return null;
    }
    $session = $steamHelpConfig['sessions'][$accountId] ?? null;
    return is_array($session) && !empty($session['cookie']) ? $session : null;
}

function hasSteamHelpSessionForAccount(string $accountId): bool {
    return getSteamHelpSessionForAccount($accountId) !== null;
}

function getSteamVacCacheFile(string $accountId): string {
    return getCacheDir('steam') . 'vac_status_' . hash('sha256', normalizeSteamHelpAccountId($accountId)) . '.json';
}

function getBeijingDateTime(int $timestamp): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'));
}

function formatBeijingTimeOfDay(int $timestamp): string {
    if ($timestamp <= 0) {
        return '';
    }
    return getBeijingDateTime($timestamp)->format('H:i:s');
}

function formatBeijingDateTimeLabel(int $timestamp): string {
    if ($timestamp <= 0) {
        return '';
    }
    return getBeijingDateTime($timestamp)->format('Y-m-d H:i:s');
}

function completeSteamVacDerivedFields(array $status): array {
    $isTemporary = ($status['vac_state'] ?? '') === 'temporary';
    $detectedAt = isset($status['temporary_detected_at']) ? (int)$status['temporary_detected_at'] : 0;
    $endDate = trim((string)($status['end_date'] ?? ''));

    if ($isTemporary && $detectedAt > 0) {
        $detectedTime = formatBeijingTimeOfDay($detectedAt);
        $status['temporary_detected_at'] = $detectedAt;
        $status['temporary_detected_time'] = $detectedTime;
        $status['unlock_label'] = $endDate !== '' && $detectedTime !== '' ? $endDate . ' ' . $detectedTime : '';
        return $status;
    }

    $status['temporary_detected_at'] = null;
    $status['temporary_detected_time'] = '';
    $status['unlock_label'] = '';
    return $status;
}

function applySteamVacTemporaryDetectionTime(array $status, ?array $previous, int $detectedAt): array {
    $status['temporary_detected_at'] = null;
    $status['temporary_detected_time'] = '';
    $status['unlock_label'] = '';

    if (($status['vac_state'] ?? '') !== 'temporary') {
        return $status;
    }

    $endDate = trim((string)($status['end_date'] ?? ''));
    if ($endDate === '') {
        return $status;
    }

    $previousEndDate = trim((string)($previous['end_date'] ?? ''));
    $previousDetectedAt = isset($previous['temporary_detected_at']) ? (int)$previous['temporary_detected_at'] : 0;
    if (($previous['vac_state'] ?? '') === 'temporary' && $previousEndDate === $endDate && $previousDetectedAt > 0) {
        $detectedAt = $previousDetectedAt;
    }

    $status['temporary_detected_at'] = $detectedAt;
    return completeSteamVacDerivedFields($status);
}

function readStoredSteamVacStatus(string $accountId): ?array {
    $cacheFile = getSteamVacCacheFile($accountId);
    if (!is_file($cacheFile)) {
        return null;
    }

    $stored = json_decode((string)@file_get_contents($cacheFile), true);
    return is_array($stored) ? completeSteamVacDerivedFields($stored) : null;
}

function readCachedSteamVacStatus(string $accountId, int $cacheTtl, bool $allowStale = false): ?array {
    $cacheFile = getSteamVacCacheFile($accountId);
    if (!is_file($cacheFile)) {
        return null;
    }
    $age = time() - filemtime($cacheFile);
    if (!$allowStale && $age >= $cacheTtl) {
        return null;
    }

    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (!is_array($cached)) {
        return null;
    }
    if (($cached['vac_state'] ?? '') === 'temporary' && isset($cached['end_date']) && preg_match('/^\d+(?:\.\d+)?$/', trim((string)$cached['end_date']))) {
        return null;
    }
    $cached = completeSteamVacDerivedFields($cached);
    $cached['from_cache'] = true;
    $cached['cache_age'] = max(0, $age);
    return $cached;
}

function writeCachedSteamVacStatus(string $accountId, array $status): void {
    $cacheFile = getSteamVacCacheFile($accountId);
    $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
    $status = completeSteamVacDerivedFields($status);
    if (@file_put_contents($tmpFile, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @rename($tmpFile, $cacheFile);
    } else {
        @unlink($tmpFile);
    }
}

function requestSteamVacPage(array $session): array {
    global $steamHelpConfig;
    if (empty($session['cookie'])) {
        return ['code' => 0, 'body' => '', 'latency_ms' => 0, 'error' => 'not_configured'];
    }

    $url = 'https://help.steampowered.com/zh-cn/wizard/VacBans/';
    $start = microtime(true);
    $body = '';
    $httpCode = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => (int)$steamHelpConfig['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_COOKIE         => $session['cookie'],
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.6',
                'Referer: https://help.steampowered.com/zh-cn/',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            ],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $options);
        $body = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => (int)$steamHelpConfig['timeout'],
                'header'  => "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\nAccept-Language: zh-CN,zh;q=0.9,en;q=0.6\r\nReferer: https://help.steampowered.com/zh-cn/\r\nUser-Agent: Mozilla/5.0\r\nCookie: {$session['cookie']}\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = (string)@file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $httpCode = (int)$matches[1];
        }
        if ($body === '') {
            $error = 'empty_response';
        }
    }

    return [
        'code'       => $httpCode,
        'body'       => $body,
        'latency_ms' => (int)round((microtime(true) - $start) * 1000),
        'error'      => $error,
    ];
}

function normalizeSteamHelpText(string $html): string {
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function extractSteamVacEndDate(string $text): ?string {
    $patterns = [
        '/于\s*((?:20\d{2}\s*年\s*)?\d{1,2}\s*月\s*\d{1,2}\s*(?:日|号)?)\s*解禁/u',
        '/((?:20\d{2}\s*年\s*)?\d{1,2}\s*月\s*\d{1,2}\s*(?:日|号)?)\s*解禁/u',
        '/((?:20\d{2}\s*年\s*)?\d{1,2}\s*月\s*\d{1,2}\s*(?:日|号)?)/u',
        '/((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2}(?:,\s*20\d{2})?)/iu',
        '/\b(?:20\d{2}[\/-])?\d{1,2}[\/-]\d{1,2}\b/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            return normalizeSteamVacEndDateLabel((string)end($matches));
        }
    }
    return null;
}

function normalizeSteamVacEndDateLabel(string $dateText): string {
    $dateText = trim(preg_replace('/\s+/u', ' ', $dateText));
    if (preg_match('/(?:20\d{2}\s*年\s*)?(\d{1,2})\s*月\s*(\d{1,2})\s*(?:日|号)?/u', $dateText, $matches)) {
        return (int)$matches[1] . '月' . (int)$matches[2] . '日';
    }
    if (preg_match('/\b(?:20\d{2}[\/-])?(\d{1,2})[\/-](\d{1,2})\b/u', $dateText, $matches)) {
        return (int)$matches[1] . '月' . (int)$matches[2] . '日';
    }
    return $dateText;
}

function extractSteamVacPubgEndDate(string $text): ?string {
    $pubg = '(?:PUBG|BATTLEGROUNDS|PLAYERUNKNOWN|绝地求生|578080)';
    $date = '((?:20\d{2}\s*年\s*)?\d{1,2}\s*月\s*\d{1,2}\s*(?:日|号)?)';
    if (preg_match('/' . $pubg . '.{0,220}?于\s*' . $date . '\s*解禁/isu', $text, $matches)) {
        return normalizeSteamVacEndDateLabel($matches[1]);
    }
    if (preg_match('/' . $pubg . '.{0,220}?' . $date . '\s*解禁/isu', $text, $matches)) {
        return normalizeSteamVacEndDateLabel($matches[1]);
    }
    return null;
}

function parseSteamVacPage(string $html): array {
    $text = normalizeSteamHelpText($html);
    if ($text === '') {
        return ['vac_state' => 'unknown', 'label' => '无法解析 Steam 客服页', 'detail' => 'Steam 返回内容为空', 'end_date' => null];
    }

    if (preg_match('/(请先登录|登录您的\s*Steam|登录\s*Steam\s*帐户|Sign\s*in\s*to\s*Steam|sign\s*in\s*with\s*your\s*Steam|session\s*expired)/iu', $text)
        && !preg_match('/(VAC\s*声誉良好|没有任何关联的\s*VAC\s*或游戏封禁)/iu', $text)) {
        return ['vac_state' => 'login_required', 'label' => 'Steam Cookie 已失效', 'detail' => 'Steam 客服页要求重新登录', 'end_date' => null];
    }

    if (preg_match('/(VAC\s*声誉良好(?:的)?(?:帐户|账户)|没有任何关联的\s*VAC\s*或游戏封禁|no\s+VAC\s+or\s+game\s+bans)/iu', $text)) {
        return ['vac_state' => 'clear', 'label' => '未封禁', 'detail' => 'Steam 客服页显示 VAC 声誉良好', 'end_date' => null];
    }

    $pubgPattern = '/(PUBG|BATTLEGROUNDS|PLAYERUNKNOWN|绝地求生|578080)/iu';
    if (!preg_match_all($pubgPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        return ['vac_state' => 'unknown', 'label' => '未发现 PUBG 封禁条目', 'detail' => 'Steam 客服页未显示 VAC 声誉良好，也未出现 PUBG 条目', 'end_date' => null];
    }

    $pubgEndDate = extractSteamVacPubgEndDate($text);
    if ($pubgEndDate !== null) {
        return ['vac_state' => 'temporary', 'label' => 'PUBG 临时封禁', 'detail' => 'Steam 客服页显示 PUBG + 解禁日期', 'end_date' => $pubgEndDate];
    }

    foreach ($matches[0] as $match) {
        $offset = max(0, (int)$match[1] - 260);
        $snippet = substr($text, $offset, 620);
        $endDate = extractSteamVacEndDate($snippet);
        if ($endDate !== null) {
            return ['vac_state' => 'temporary', 'label' => 'PUBG 临时封禁', 'detail' => 'Steam 客服页显示 PUBG + 日期', 'end_date' => $endDate];
        }
    }

    return ['vac_state' => 'permanent', 'label' => 'PUBG 永久封禁', 'detail' => 'Steam 客服页显示 PUBG，但没有时间日期', 'end_date' => null];
}

function getSteamVacStatus(string $accountId, bool $force = false): array {
    global $steamHelpConfig;
    $accountId = normalizeSteamHelpAccountId($accountId);
    $session = getSteamHelpSessionForAccount($accountId);
    if ($session === null) {
        return [
            'status'     => 'not_configured',
            'vac_state'  => 'not_configured',
            'label'      => '未绑定 Steam 会话',
            'detail'     => '当前 accountId 未配置 Steam 客服 Cookie',
            'end_date'   => null,
            'checked_at' => null,
            'temporary_detected_at' => null,
            'temporary_detected_time' => '',
            'unlock_label' => '',
        ];
    }

    $cacheTtl = (int)$steamHelpConfig['poll_seconds'];
    $cached = $force ? null : readCachedSteamVacStatus($accountId, $cacheTtl);
    if ($cached !== null) {
        return $cached;
    }

    $response = requestSteamVacPage($session);
    if (($response['code'] ?? 0) < 200 || ($response['code'] ?? 0) >= 300 || empty($response['body'])) {
        $stale = readCachedSteamVacStatus($accountId, $cacheTtl, true);
        if ($stale !== null) {
            $stale['status'] = 'stale';
            $stale['detail'] = ($stale['detail'] ?? '') . '；当前请求失败，显示上次缓存结果';
            return $stale;
        }
        return [
            'status'      => 'error',
            'vac_state'   => 'unknown',
            'label'       => 'Steam 客服请求失败',
            'detail'      => 'HTTP ' . (int)($response['code'] ?? 0),
            'end_date'    => null,
            'checked_at'  => time(),
            'latency_ms'  => (int)($response['latency_ms'] ?? 0),
            'poll_seconds'=> $cacheTtl,
            'temporary_detected_at' => null,
            'temporary_detected_time' => '',
            'unlock_label' => '',
        ];
    }

    $parsed = parseSteamVacPage((string)$response['body']);
    $checkedAt = time();
    $previous = readStoredSteamVacStatus($accountId);
    $status = [
        'status'      => 'success',
        'account_id'  => $accountId,
        'vac_state'   => $parsed['vac_state'],
        'label'       => $parsed['label'],
        'detail'      => $parsed['detail'],
        'end_date'    => $parsed['end_date'],
        'checked_at'  => $checkedAt,
        'latency_ms'  => (int)($response['latency_ms'] ?? 0),
        'poll_seconds'=> $cacheTtl,
    ];
    $status = applySteamVacTemporaryDetectionTime($status, $previous, $checkedAt);
    writeCachedSteamVacStatus($accountId, $status);
    return $status;
}

function isValidSteamVacCronToken($providedToken): bool {
    global $steamHelpConfig;
    $expectedToken = (string)($steamHelpConfig['cron_token'] ?? '');
    $providedToken = normalizeSteamHelpCronToken($providedToken);
    return $expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken);
}

function summarizeSteamVacCronResult(string $accountId, array $status): array {
    return [
        'account_ref' => substr(hash('sha256', $accountId), 0, 12),
        'status'     => (string)($status['status'] ?? 'unknown'),
        'vac_state'  => (string)($status['vac_state'] ?? 'unknown'),
        'label'      => (string)($status['label'] ?? ''),
        'end_date'   => $status['end_date'] ?? null,
        'has_temporary_detected_at' => !empty($status['temporary_detected_at']),
        'latency_ms' => (int)($status['latency_ms'] ?? 0),
    ];
}

function runSteamVacCronRefresh(): array {
    global $steamHelpConfig;
    $sessions = is_array($steamHelpConfig['sessions'] ?? null) ? $steamHelpConfig['sessions'] : [];
    $startedAt = time();
    $results = [];

    if (function_exists('set_time_limit')) {
        @set_time_limit(max(30, (count($sessions) * (int)($steamHelpConfig['timeout'] ?? 8)) + 10));
    }

    foreach (array_keys($sessions) as $accountId) {
        $accountId = normalizeSteamHelpAccountId($accountId);
        if ($accountId === '') {
            continue;
        }
        $results[] = summarizeSteamVacCronResult($accountId, getSteamVacStatus($accountId, true));
    }

    return [
        'status' => 'success',
        'checked_at' => $startedAt,
        'checked_at_beijing' => formatBeijingDateTimeLabel($startedAt),
        'total' => count($results),
        'results' => $results,
    ];
}

function isRateLimitedPubgEndpoint(string $url): bool {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    return strpos($path, '/matches/') === false;
}

function reservePubgApiKey(bool $rateLimited = true): ?string {
    global $apiKeys;
    if (empty($apiKeys)) {
        return null;
    }

    if (!$rateLimited) {
        static $fastCursor = 0;
        $key = $apiKeys[$fastCursor % count($apiKeys)];
        $fastCursor++;
        return $key;
    }

    $stateFile = getCacheDir('rate') . 'pubg_key_pool.json';
    $lockFile = getCacheDir('rate') . 'pubg_key_pool.lock';
    $lockFailures = 0;

    while (true) {
        $lockHandle = @fopen($lockFile, 'c+');
        if (!$lockHandle) {
            $lockFailures++;
            usleep(500000);
            if ($lockFailures >= 3) {
                return reservePubgApiKey(false);
            }
            continue;
        }

        flock($lockHandle, LOCK_EX);
        $state = is_file($stateFile) ? json_decode((string)@file_get_contents($stateFile), true) : [];
        $state = is_array($state) ? $state : [];
        $nextAt = is_array($state['next_at'] ?? null) ? $state['next_at'] : [];
        $cursor = (int)($state['cursor'] ?? 0);
        $nowUs = (int)floor(microtime(true) * 1000000);
        $keyCount = count($apiKeys);
        $selectedIndex = null;
        $selectedReadyAt = PHP_INT_MAX;

        for ($i = 0; $i < $keyCount; $i++) {
            $index = ($cursor + $i) % $keyCount;
            $hash = hash('sha256', $apiKeys[$index]);
            $readyAt = (int)($nextAt[$hash] ?? 0);
            if ($readyAt <= $nowUs) {
                $selectedIndex = $index;
                $selectedReadyAt = $readyAt;
                break;
            }
            if ($readyAt < $selectedReadyAt) {
                $selectedIndex = $index;
                $selectedReadyAt = $readyAt;
            }
        }

        if ($selectedIndex !== null && $selectedReadyAt <= $nowUs) {
            $hash = hash('sha256', $apiKeys[$selectedIndex]);
            $nextAt[$hash] = $nowUs + PUBG_RATE_INTERVAL_US;
            @file_put_contents($stateFile, json_encode([
                'cursor'  => ($selectedIndex + 1) % $keyCount,
                'next_at' => $nextAt,
            ], JSON_UNESCAPED_SLASHES), LOCK_EX);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return $apiKeys[$selectedIndex];
        }

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        $sleepUs = max(250000, min(PUBG_RATE_INTERVAL_US, $selectedReadyAt - $nowUs));
        usleep($sleepUs);
    }
}

function coolDownPubgApiKey(string $apiKey, int $seconds = 60): void {
    $stateFile = getCacheDir('rate') . 'pubg_key_pool.json';
    $lockFile = getCacheDir('rate') . 'pubg_key_pool.lock';
    $lockHandle = @fopen($lockFile, 'c+');
    if (!$lockHandle) {
        return;
    }

    flock($lockHandle, LOCK_EX);
    $state = is_file($stateFile) ? json_decode((string)@file_get_contents($stateFile), true) : [];
    $state = is_array($state) ? $state : [];
    $nextAt = is_array($state['next_at'] ?? null) ? $state['next_at'] : [];
    $nextAt[hash('sha256', $apiKey)] = (int)floor((microtime(true) + $seconds) * 1000000);
    $state['next_at'] = $nextAt;
    @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

function isValidPubgAccountId(string $accountId): bool {
    return (bool)preg_match('/^account\.[A-Za-z0-9_-]+$/', $accountId);
}

function isValidPubgMatchId(string $matchId): bool {
    return (bool)preg_match('/^[A-Za-z0-9-]{8,100}$/', $matchId);
}

function isValidPubgSeasonId(string $seasonId): bool {
    return (bool)preg_match('/^division\.bro\.official\.(pc|console)-[A-Za-z0-9-]+$/', $seasonId);
}

function getSurvivalXpRequirement(int $level): int {
    $level = max(1, min(500, $level));
    if ($level < 10) return 100;
    if ($level < 20) return 300;
    if ($level < 30) return 500;
    if ($level < 40) return 700;
    if ($level < 50) return 900;
    if ($level < 60) return 1100;
    if ($level < 70) return 1300;
    if ($level < 80) return 1500;
    return 2000;
}

function getSurvivalCumulativeXpBeforeLevel(int $level): int {
    $level = max(1, min(500, $level));
    $total = 0;
    for ($i = 1; $i < $level; $i++) {
        $total += getSurvivalXpRequirement($i);
    }
    return $total;
}

function getSurvivalTierXpTotal(): int {
    return getSurvivalCumulativeXpBeforeLevel(500) + getSurvivalXpRequirement(500);
}

function calculateSurvivalMasteryProgress(int $tier, int $level, int $xp): array {
    $tier = max(1, min(5, $tier));
    $level = max(1, min(500, $level));
    $xp = max(0, $xp);
    $totalLevel = (($tier - 1) * 500) + $level;
    $maxed = $tier >= 5 && $level >= 500;
    $xpRequired = $maxed ? 0 : getSurvivalXpRequirement($level);
    $tierCumulativeBefore = getSurvivalCumulativeXpBeforeLevel($level);
    $globalCumulativeBefore = (($tier - 1) * getSurvivalTierXpTotal()) + $tierCumulativeBefore;

    if ($maxed) {
        $currentXp = 0;
    } elseif ($xp >= $globalCumulativeBefore) {
        $currentXp = $xp - $globalCumulativeBefore;
    } elseif ($xp >= $tierCumulativeBefore) {
        $currentXp = $xp - $tierCumulativeBefore;
    } else {
        $currentXp = $xp;
    }

    $currentXp = $xpRequired > 0 ? min($currentXp, $xpRequired) : 0;
    $nextTier = $level >= 500 ? min(5, $tier + 1) : $tier;
    $nextLevel = $level >= 500 ? 1 : $level + 1;

    return [
        'tier'             => $tier,
        'level'            => $level,
        'total_level'      => $totalLevel,
        'raw_xp'           => $xp,
        'current_xp'       => $currentXp,
        'required_xp'      => $xpRequired,
        'percent'          => $xpRequired > 0 ? ($currentXp / $xpRequired) * 100 : 100,
        'is_maxed'         => $maxed,
        'next_tier'        => $nextTier,
        'next_level'       => $nextLevel,
    ];
}

/**
 * 精准换算指定排位段位所对应的权重积分
 * 用于比对并提炼出玩家的生涯历史最高排位得分
 */
function getRankScore($tier, $subTier, $currentRankPoint = 0) {
    $tiers = [
        'Bronze'      => 10000,
        'Silver'      => 20000,
        'Gold'        => 30000,
        'Platinum'    => 40000,
        'Crystal'     => 50000,
        'Diamond'     => 60000,
        'Master'      => 70000,
        'Grandmaster' => 80000,
        'Survivor'    => 80000
    ];
    $base = $tiers[$tier] ?? 0;
    if ($base === 0) return 0;
    
    if (in_array($tier, ['Master', 'Survivor', 'Grandmaster'])) $sub = 6000;
    else {
        $subTierNum = is_numeric($subTier) ? max(1, min(5, (int)$subTier)) : 5;
        $sub = (6 - $subTierNum) * 1000;
    }
    
    return $base + $sub + (int)$currentRankPoint;
}

function getRankImageKey($tier, $subTier = null): string {
    if (!$tier || $tier === 'Unranked') return '';
    if ($tier === 'Grandmaster') return 'Survivor';
    if (in_array($tier, ['Master', 'Survivor'], true)) return $tier;
    return is_numeric($subTier) ? "{$tier}_{$subTier}" : '';
}

function formatRankTierLabel($tier, $subTier, array $tierLabels): string {
    if (!$tier || $tier === 'Unranked') return '未定级';

    $label = $tierLabels[$tier] ?? $tier;
    if (!in_array($tier, ['Master', 'Survivor', 'Grandmaster'], true) && is_numeric($subTier)) {
        $label .= $subTier;
    }
    return $label;
}

function formatRankRatio($value): string {
    $ratio = floatval($value ?? 0);
    if ($ratio <= 0) return '0%';
    $percent = $ratio <= 1 ? $ratio * 100 : $ratio;
    return number_format(round($percent, 1), 1, '.', '') . '%';
}

/**
 * 核心记录算法：遍历单赛季的排位字典，动态更新其生存期内的最高排位指标记录
 */
function updateHighestRankScore(array $rData, string $sId, &$highestRankScore, &$lifetimeHighestRank, array $seasonConfig, array $gameModes) {
    if (!isset($rData['data']['attributes']['rankedGameModeStats'])) return;

    foreach ($rData['data']['attributes']['rankedGameModeStats'] as $mode => $stats) {
        if (isset($stats['bestTier']['tier'])) {
            $tier             = $stats['bestTier']['tier'];
            $subTier          = $stats['bestTier']['subTier'] ?? null;
            $currentRankPoint = $stats['currentRankPoint'] ?? 0;
            $bestRankPoint    = $stats['bestRankPoint'] ?? 0;
            $score            = getRankScore($tier, $subTier, $bestRankPoint ?: $currentRankPoint);

            if ($score > $highestRankScore) {
                $highestRankScore    = $score;
                $lifetimeHighestRank = [
                    'season_id'    => $sId,
                    'tier'         => $tier,
                    'subTier'      => $subTier,
                    'currentRP'    => $currentRankPoint,
                    'bestRP'       => $bestRankPoint,
                    'mode'         => $gameModes[$mode] ?? $mode,
                    'season_name'  => $seasonConfig[$sId]['name'],
                    'season_month' => $seasonConfig[$sId]['month']
                ];
            }
        }
    }
}

/**
 * 官方 API 出口。
 *
 * 站点本身可以部署在 HTTP/3333；这里校验的是访问 PUBG 官方 HTTPS 接口时的证书，
 * 用来避免 Authorization Header 在出站链路上被劫持。
 */
function pubgApiRequest(string $url, int $cacheTtl = 0): array {
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($scheme !== 'https' || $host !== 'api.pubg.com') {
        return ['code' => 400, 'data' => ['error' => 'invalid_api_url']];
    }

    $cached = readCachedApiResponse($url, $cacheTtl);
    if ($cached !== null) {
        return $cached;
    }

    $rateLimited = isRateLimitedPubgEndpoint($url);
    $maxRetries = max(2, min(4, count($GLOBALS['apiKeys'] ?? [])));
    $response = '';
    $httpCode = 0;
    $curlError = '';

    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $apiKey = reservePubgApiKey($rateLimited);
        if ($apiKey === null) {
            $stale = readStaleApiResponse($url);
            return $stale ?? ['code' => 503, 'data' => ['error' => 'missing_pubg_api_keys']];
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => PUBG_HTTP_CONNECT_TIMEOUT_SEC,
            CURLOPT_TIMEOUT        => PUBG_HTTP_TIMEOUT_SEC,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.api+json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $options);
        $response = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 404) {
            break;
        }

        if ($httpCode === 429) {
            coolDownPubgApiKey($apiKey, 60);
        }

        $backoffUs = (300000 * ($attempt + 1)) + random_int(0, 250000);
        usleep($backoffUs);
    }

    if ($httpCode !== 200 && $httpCode !== 404) {
        $stale = readStaleApiResponse($url);
        if ($stale !== null) {
            return $stale;
        }
    }

    $isPlayerSeason = strpos($url, '/seasons/') !== false && strpos($url, '/players/') !== false;
    if ($httpCode !== 200 && $httpCode !== 429 && $isPlayerSeason) {
        $response = json_encode(['data' => ['attributes' => ['gameModeStats' => [], 'rankedGameModeStats' => []]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $httpCode = 200;
    }

    $decoded = json_decode($response !== '' ? $response : '{}', true);
    $jsonIsValid = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    $decoded = $jsonIsValid ? $decoded : ['error' => 'invalid_json'];

    if ($httpCode === 200 && $cacheTtl > 0 && $response !== '' && $jsonIsValid) {
        writeCachedApiResponse($url, $response);
    }

    if ($httpCode === 0 && $curlError !== '') {
        $decoded['curl_error'] = $curlError;
    }

    return ['code' => $httpCode, 'data' => $decoded];
}

/**
 * 官方标准赛季映射器
 * 过滤控制台和 PC 端接口差异，生成所有有效的当前与历史常规赛季结构体
 */
function getDynamicSeasons(array $apiConfig, array $accurateMap): array {
    $url = "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/seasons";
    $res = pubgApiRequest($url, 86400); 
    if ($res['code'] !== 200 || empty($res['data']['data'])) return [];
    
    $isConsole    = in_array($apiConfig['shard'], ['xbox', 'psn'], true);
    $seasonPrefix = $isConsole ? 'division.bro.official.console-' : 'division.bro.official.pc-2018-';
    $allSeasons   = $res['data']['data'];
    
    $modernSeasons = array_filter($allSeasons, function($season) use ($seasonPrefix, $accurateMap) { 
        if (strpos($season['id'], $seasonPrefix) !== 0) return false;
        $suffix       = substr($season['id'], strlen($seasonPrefix));
        $seasonNumStr = str_pad($suffix, 2, '0', STR_PAD_LEFT);
        return isset($accurateMap[$seasonNumStr]); 
    });
    
    usort($modernSeasons, function($a, $b) { return strcmp($b['id'], $a['id']); });
    
    $fullConfig = [];
    foreach ($modernSeasons as $season) {
        $suffix       = substr($season['id'], strlen($seasonPrefix));
        $seasonNumStr = str_pad($suffix, 2, '0', STR_PAD_LEFT);
        $monthStr     = $accurateMap[$seasonNumStr];
        
        $fullConfig[$season['id']] = [
            'name'      => (int)$seasonNumStr . "赛季", 
            'month'     => $monthStr, 
            'isCurrent' => (bool)($season['attributes']['isCurrentSeason'] ?? false)
        ];
    }
    return $fullConfig;
}

function formatTimeSec($seconds): string {
    $seconds = max(0, (int)round($seconds));
    if ($seconds <= 0) return '0分0秒';
    return floor($seconds / 60) . "分" . ($seconds % 60) . "秒";
}

/**
 * 数据数学模型萃取器
 * 负责提炼计算胜率、K/D、KPM 及各项单局场均表现阈值
 */
function calculateModeKD(array $modeStats): array {
    $kills        = intval($modeStats['kills'] ?? 0); 
    $wins         = intval($modeStats['wins'] ?? 0);
    $roundsPlayed = intval($modeStats['roundsPlayed'] ?? 0); 
    $deathCount   = intval($modeStats['losses'] ?? 0);
    
    $kdValue  = $deathCount === 0 ? floatval($kills) : ($kills / $deathCount);
    $kpmValue = $roundsPlayed === 0 ? 0 : ($kills / $roundsPlayed); 
    
    return [
        'kd'              => number_format(round($kdValue, 1), 1, '.', ''), 
        'kd_value'        => $kdValue, 
        'kpm'             => number_format(round($kpmValue, 1), 1, '.', ''), 
        'kills'           => $kills, 
        'wins'            => $wins,
        'total_matches'   => $roundsPlayed, 
        'death_count'     => $deathCount, 
        'assists'         => intval($modeStats['assists'] ?? 0), 
        'headshotKills'   => intval($modeStats['headshotKills'] ?? 0),
        'longestKill'     => round(max(0, floatval($modeStats['longestKill'] ?? 0)), 2), 
        'top10s'          => intval($modeStats['top10s'] ?? 0), 
        'avgDamageDealt'  => round(floatval($modeStats['damageDealt'] ?? 0) / max(1, $roundsPlayed), 2), 
        'dbnos'           => intval($modeStats['dBNOs'] ?? 0),
        'revives'         => intval($modeStats['revives'] ?? 0), 
        'killStreaks'     => intval($modeStats['roundMostKills'] ?? 0),
        'teamKills'       => intval($modeStats['teamKills'] ?? 0), 
        'vehicleDestroys' => intval($modeStats['vehicleDestroys'] ?? 0),
        'suicides'        => intval($modeStats['suicides'] ?? 0), 
        'avgTimeSurvived' => formatTimeSec($roundsPlayed > 0 ? round(floatval($modeStats['timeSurvived'] ?? 0) / $roundsPlayed) : 0)
    ];
}

function extractSeasonData(array $seasonStats, array $gameModes): array {
    $modeData     = []; 
    $hasValidData = false;
    foreach ($gameModes as $modeKey => $modeName) {
        $stats  = $seasonStats[$modeKey] ?? [];
        $kdInfo = calculateModeKD($stats);
        $kdInfo['is_solo'] = (strpos($modeKey, 'solo') !== false);
        if ($kdInfo['kills'] > 0 || $kdInfo['total_matches'] > 0) $hasValidData = true;
        $modeData[$modeKey] = $kdInfo;
    }
    return ['has_data' => $hasValidData, 'data' => $modeData];
}

function readNumericStat(array $stats, array $keys, $default = 0) {
    foreach ($keys as $key) {
        if (array_key_exists($key, $stats) && is_numeric($stats[$key])) {
            return (float)$stats[$key];
        }
    }
    return $default === null ? null : (float)$default;
}

function calculateRankedModeData(array $modeStats, array $tierLabels): array {
    $roundsPlayed     = (int)readNumericStat($modeStats, ['roundsPlayed', 'rounds']);
    $kills            = (int)readNumericStat($modeStats, ['kills', 'killCount']);
    $deaths           = (int)readNumericStat($modeStats, ['deaths', 'losses']);
    $assists          = (int)readNumericStat($modeStats, ['assists', 'assistCount']);
    $wins             = (int)readNumericStat($modeStats, ['wins', 'winCount']);
    $currentRankPoint = (int)readNumericStat($modeStats, ['currentRankPoint', 'currentRP']);
    $bestRankPoint    = (int)readNumericStat($modeStats, ['bestRankPoint', 'bestRP']);
    $damageDealt      = readNumericStat($modeStats, ['damageDealt', 'totalDamage', 'damage']);
    $playTime         = readNumericStat($modeStats, ['playTime', 'timeSurvived', 'totalTimeSurvived']);

    $currentTier    = $modeStats['currentTier']['tier'] ?? null;
    $currentSubTier = $modeStats['currentTier']['subTier'] ?? null;
    $bestTier       = $modeStats['bestTier']['tier'] ?? null;
    $bestSubTier    = $modeStats['bestTier']['subTier'] ?? null;
    $rawKda         = readNumericStat($modeStats, ['kda', 'KDA'], null);
    $computedKda    = ($kills + $assists) / max(1, $deaths);
    $kdaValue       = ($rawKda !== null && ($rawKda > 0 || $computedKda <= 0)) ? $rawKda : $computedKda;
    $rankScore      = getRankScore($bestTier, $bestSubTier, $bestRankPoint ?: $currentRankPoint);
    $avgSurvivalRaw = readNumericStat($modeStats, ['avgSurvivalTime', 'averageSurvivalTime'], null);
    $avgSurvivalSec = $avgSurvivalRaw !== null
        ? $avgSurvivalRaw
        : ($roundsPlayed > 0 ? ($playTime / $roundsPlayed) : 0);
    $killStreak = (int)readNumericStat($modeStats, ['roundMostKills', 'killStreak']);

    return [
        'has_data'               => $roundsPlayed > 0 || $kills > 0 || $currentRankPoint > 0 || $bestRankPoint > 0 || ($bestTier && $bestTier !== 'Unranked'),
        'total_matches'          => $roundsPlayed,
        'wins'                   => $wins,
        'kills'                  => $kills,
        'deaths'                 => $deaths,
        'assists'                => $assists,
        'kda'                    => number_format(round($kdaValue, 2), 2, '.', ''),
        'kda_value'              => $kdaValue,
        'win_ratio'              => formatRankRatio(readNumericStat($modeStats, ['winRatio', 'winRate'])),
        'top10_ratio'            => formatRankRatio(readNumericStat($modeStats, ['top10Ratio', 'top10Rate'])),
        'avg_rank'               => number_format(round(readNumericStat($modeStats, ['avgRank', 'averageRank']), 1), 1, '.', ''),
        'avg_damage'             => round($damageDealt / max(1, $roundsPlayed), 2),
        'damage_dealt'           => round($damageDealt, 2),
        'headshot_kills'         => (int)readNumericStat($modeStats, ['headshotKills']),
        'headshot_ratio'         => formatRankRatio(readNumericStat($modeStats, ['headshotKillRatio', 'headshotRatio'])),
        'dbnos'                  => (int)readNumericStat($modeStats, ['dBNOs', 'dbnos']),
        'revives'                => (int)readNumericStat($modeStats, ['revives']),
        'revive_ratio'           => formatRankRatio(readNumericStat($modeStats, ['reviveRatio'])),
        'team_kills'             => (int)readNumericStat($modeStats, ['teamKills']),
        'longest_kill'           => round(max(0, readNumericStat($modeStats, ['longestKill'])), 2),
        'kill_streak'            => $killStreak,
        'play_time'              => formatTimeSec(round($playTime)),
        'avg_survival_time'      => $avgSurvivalSec > 0 ? formatTimeSec($avgSurvivalSec) : ($roundsPlayed > 0 ? '--' : '0分0秒'),
        'current_rank_point'     => $currentRankPoint,
        'best_rank_point'        => $bestRankPoint,
        'current_tier'           => $currentTier,
        'current_sub_tier'       => $currentSubTier,
        'best_tier'              => $bestTier,
        'best_sub_tier'          => $bestSubTier,
        'current_rank_label'     => formatRankTierLabel($currentTier, $currentSubTier, $tierLabels),
        'best_rank_label'        => formatRankTierLabel($bestTier, $bestSubTier, $tierLabels),
        'current_rank_image_key' => getRankImageKey($currentTier, $currentSubTier),
        'best_rank_image_key'    => getRankImageKey($bestTier, $bestSubTier),
        'rank_score'             => $rankScore
    ];
}

function extractRankedSeasonData(array $rankedStats, array $gameModes, array $tierLabels): array {
    $modeData     = [];
    $hasValidData = false;
    $modeKeys     = array_unique(array_merge(array_keys($gameModes), array_keys($rankedStats)));

    foreach ($modeKeys as $modeKey) {
        $stats      = $rankedStats[$modeKey] ?? [];
        $rankedInfo = calculateRankedModeData($stats, $tierLabels);
        $rankedInfo['is_solo'] = (strpos($modeKey, 'solo') !== false);
        if ($rankedInfo['has_data']) $hasValidData = true;
        $modeData[$modeKey] = $rankedInfo;
    }

    return ['has_data' => $hasValidData, 'data' => $modeData];
}

function updateHighestRankedModeData(array $rankedModeData, string $sId, array &$highestRankedByMode, array $seasonConfig): void {
    foreach ($rankedModeData as $modeKey => $rankedInfo) {
        if (empty($rankedInfo['has_data'])) continue;

        $sortScore = ($rankedInfo['rank_score'] * 1000000)
            + ((int)$rankedInfo['best_rank_point'] * 1000)
            + (int)round($rankedInfo['kda_value'] * 100)
            + (int)$rankedInfo['total_matches'];

        if (!isset($highestRankedByMode[$modeKey]) || $sortScore > $highestRankedByMode[$modeKey]['sort_score']) {
            $rankedInfo['season_id']    = $sId;
            $rankedInfo['season_name']  = $seasonConfig[$sId]['name'] ?? '未知赛季';
            $rankedInfo['season_month'] = $seasonConfig[$sId]['month'] ?? '未知时间';
            $rankedInfo['sort_score']   = $sortScore;
            $highestRankedByMode[$modeKey] = $rankedInfo;
        }
    }
}

function getSeasonInfo(string $seasonId, array $seasonConfig): array {
    return $seasonConfig[$seasonId] ?? ['name' => '未知赛季', 'month' => '未知时间'];
}

if (!function_exists('renderStatsHtml')) {
    function renderStatsHtml(array $data) {
        $reviveLabel = !empty($data['is_solo']) ? '自我救援' : '救援队友';
        $statsMapping = [
            ['icon' => '🔫', 'label' => '总击杀',   'value' => $data['kills']],
            ['icon' => '🎮', 'label' => '总场次',   'value' => $data['total_matches']],
            ['icon' => '🏆', 'label' => '胜场数',   'value' => $data['wins']],
            ['icon' => '💀', 'label' => '死亡数',   'value' => $data['death_count']],
            ['icon' => '🥊', 'label' => '击倒次数', 'value' => $data['dbnos']],
            ['icon' => '🔥', 'label' => '最高击杀', 'value' => $data['killStreaks']],
            ['icon' => '🤝', 'label' => '助攻数',   'value' => $data['assists']],
            ['icon' => '🚑', 'label' => $reviveLabel, 'value' => $data['revives']],
            ['icon' => '🎯', 'label' => '爆头击杀', 'value' => $data['headshotKills']],
            ['icon' => '📏', 'label' => '最远击杀', 'value' => $data['longestKill'] . 'm'],
            ['icon' => '⏱️', 'label' => '场均存活', 'value' => $data['avgTimeSurvived']],
            ['icon' => '🔝', 'label' => '前十次数', 'value' => $data['top10s']],
            ['icon' => '💣', 'label' => '摧毁载具', 'value' => $data['vehicleDestroys']],
            ['icon' => '💥', 'label' => '场均伤害', 'value' => $data['avgDamageDealt']],
            ['icon' => '🤡', 'label' => '痛击队友', 'value' => '<span style="color:var(--danger-color);">' . $data['teamKills'] . '</span>'],
            ['icon' => '🤕', 'label' => '自杀次数', 'value' => $data['suicides']],
        ];
        
        foreach ($statsMapping as $stat) {
            echo sprintf('<div class="stat-item"><div class="stat-icon">%s</div><div class="stat-label">%s</div><div class="stat-value">%s</div></div>', $stat['icon'], $stat['label'], $stat['value']);
        }
    }
}

if (!function_exists('renderRankedStatsHtml')) {
    function renderRankedStatsHtml(array $data) {
        $kd  = $data['deaths'] == 0 ? (float)$data['kills'] : ($data['kills'] / $data['deaths']);
        $kpm = $data['total_matches'] == 0 ? 0 : ($data['kills'] / $data['total_matches']);

        $statsMapping = [
            ['icon' => '🔥', 'label' => '平均淘汰', 'value' => number_format(round($kpm, 1), 1, '.', '')],
            ['icon' => '⚔️', 'label' => 'K/D',      'value' => number_format(round($kd, 1), 1, '.', '')],
            ['icon' => '💥', 'label' => '平均伤害', 'value' => $data['avg_damage']],
            ['icon' => '📈', 'label' => '胜率',     'value' => $data['win_ratio']],
            ['icon' => '🏆', 'label' => '吃鸡数',   'value' => $data['wins']],
            ['icon' => '🔫', 'label' => '淘汰数',   'value' => $data['kills']],
            ['icon' => '🤝', 'label' => '助攻数',   'value' => $data['assists']],
            ['icon' => '🥊', 'label' => '击倒数',   'value' => $data['dbnos']],
            ['icon' => '💀', 'label' => '死亡数',   'value' => $data['deaths']],
            ['icon' => '🔝', 'label' => '前十率',   'value' => $data['top10_ratio']],
            ['icon' => '📍', 'label' => '平均排名', 'value' => $data['avg_rank']],
            ['icon' => '🎮', 'label' => '总场次',   'value' => $data['total_matches']],
        ];

        foreach ($statsMapping as $stat) {
            echo sprintf('<div class="stat-item"><div class="stat-icon">%s</div><div class="stat-label">%s</div><div class="stat-value">%s</div></div>', $stat['icon'], $stat['label'], $stat['value']);
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'steam_vac_status') {
    header('Content-Type: application/json; charset=utf-8');
    $steamAccountId = normalizeSteamHelpAccountId($_GET['account_id'] ?? '');
    if ($steamAccountId === '') {
        echo json_encode(['status' => 'error', 'vac_state' => 'unknown', 'label' => '缺少 accountId', 'detail' => '请求上下文为空或 accountId 无效'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    echo json_encode(getSteamVacStatus($steamAccountId, $force), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'steam_vac_cron') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isValidSteamVacCronToken($_GET['token'] ?? '')) {
        http_response_code(403);
        echo json_encode([
            'status' => 'forbidden',
            'detail' => 'Steam VAC cron token missing or invalid',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(runSteamVacCronRefresh(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$seasonConfig    = getDynamicSeasons($apiConfig, $accurateSeasonMonths);
$seasonIds       = array_keys($seasonConfig);

/**
 * 遍历匹配准确的当前活跃赛季索引，提升多平台（如 Kakao/Console）对于前瞻赛季未发布数据的兼容度
 */
$currentSeasonId = '';
foreach ($seasonConfig as $id => $cfg) {
    if ($cfg['isCurrent']) {
        $currentSeasonId = $id;
        break;
    }
}
if (empty($currentSeasonId) && !empty($seasonIds)) {
    $currentSeasonId = $seasonIds[0];
}

/**
 * 后台异步队列处理器
 * 分发负责渲染视图所需的组件级 API 通信，包含队友解析队列、并发历史扫描与静默玩家状态批处理查询
 */
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $accId = (string)($_GET['account_id'] ?? '');
    
    if ($_GET['action'] === 'fetch_match_stats') {
        $matchId = (string)($_GET['match_id'] ?? '');
        if (!isValidPubgAccountId($accId) || !isValidPubgMatchId($matchId)) { echo json_encode(['status' => 'error']); exit; }
        
        $url = "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/matches/" . rawurlencode($matchId);
        $res = pubgApiRequest($url, 0); 
        
        if ($res['code'] === 429) { echo json_encode(['status' => 'rate_limit']); exit; }
        if ($res['code'] !== 200 || empty($res['data']['included'])) { echo json_encode(['status' => 'error']); exit; }

        $partMap  = []; 
        $myPartId = null;
        foreach ($res['data']['included'] as $item) {
            if ($item['type'] === 'participant') {
                $stats                = $item['attributes']['stats']; 
                $partMap[$item['id']] = $stats;
                if ($stats['playerId'] === $accId) { $myPartId = $item['id']; }
            }
        }
        
        $teammates = [];
        if ($myPartId) {
            foreach ($res['data']['included'] as $item) {
                if ($item['type'] === 'roster') {
                    $rosterParts = $item['relationships']['participants']['data'] ?? [];
                    $isMyRoster  = false;
                    foreach ($rosterParts as $rp) { if ($rp['id'] === $myPartId) { $isMyRoster = true; break; } }
                    if ($isMyRoster) {
                        foreach ($rosterParts as $rp) {
                            if ($rp['id'] !== $myPartId && isset($partMap[$rp['id']])) {
                                $teammates[] = ['name' => $partMap[$rp['id']]['name']];
                            }
                        }
                        break;
                    }
                }
            }
        }
        echo json_encode(['status' => 'success', 'teammates' => $teammates], JSON_UNESCAPED_UNICODE); 
        exit;
    }

    if ($_GET['action'] === 'fetch_history') {
        $sId  = (string)($_GET['season_id'] ?? '');
        $type = (string)($_GET['type'] ?? 'normal');
        if (!isValidPubgAccountId($accId) || !isValidPubgSeasonId($sId)) { echo json_encode(['status' => 'success']); exit; }
        if (!in_array($type, ['normal', 'ranked'], true)) { echo json_encode(['status' => 'error']); exit; }
        
        $url = $type === 'ranked' 
            ? "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/" . rawurlencode($accId) . "/seasons/" . rawurlencode($sId) . "/ranked"
            : "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/" . rawurlencode($accId) . "/seasons/" . rawurlencode($sId);
            
        $isCurrent = ($sId === $currentSeasonId);
        $ttlValue  = $isCurrent ? 900 : 31536000;
            
        $res = pubgApiRequest($url, $ttlValue); 
        if ($res['code'] === 429) { echo json_encode(['status' => 'rate_limit']); exit; }
        
        echo json_encode(['status' => 'success']); 
        exit;
    }

    if ($_GET['action'] === 'batch_query_player') {
        $accId = (string)($_GET['account_id'] ?? '');
        $shard = (string)($_GET['shard'] ?? 'steam');
        if (!isValidPubgAccountId($accId) || !in_array($shard, $allowedShards, true)) { echo json_encode(['status' => 'error']); exit; }

        $encodedAccId = rawurlencode($accId);
        $playerUrl = "{$apiConfig['baseUrl']}/shards/{$shard}/players?filter[playerIds]={$encodedAccId}";
        $playerRes = pubgApiRequest($playerUrl, 120); 
        if ($playerRes['code'] !== 200 || empty($playerRes['data']['data'])) { echo json_encode(['status' => 'error']); exit; }

        $pInfo       = $playerRes['data']['data'][0];
        $banType     = $pInfo['attributes']['banType'] ?? 'Unknown';

        $masteryUrl  = "{$apiConfig['baseUrl']}/shards/{$shard}/players/{$encodedAccId}/survival_mastery";
        $masteryRes  = pubgApiRequest($masteryUrl, 120);
        $level       = 0; 
        $tier        = 1;
        $totalLevel  = 0;
        
        if ($masteryRes['code'] === 200 && isset($masteryRes['data']['data']['attributes']['level'])) {
            $level = intval($masteryRes['data']['data']['attributes']['level']);
            $tier  = max(1, intval($masteryRes['data']['data']['attributes']['tier'] ?? 1));
            // 复刻主页公式：(段位 - 1) * 500 + 基础等级
            $totalLevel = (($tier - 1) * 500) + $level; 
        }

        $banTypeMap = [
            'Innocent'     => '未封禁', 
            'TemporaryBan' => '临时封禁', 
            'Permanent'    => '永久封禁',
            'PermanentBan' => '永久封禁', 
            'Suspended'    => '临时封禁', 
            'Warning'      => '未封禁',
        ];
        
        echo json_encode([
            'status'    => 'success',
            'level'     => $totalLevel,
            'tier'      => $tier,
            'banStatus' => $banTypeMap[$banType] ?? '未知'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
