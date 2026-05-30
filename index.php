<?php
/**
 * 页面入口和服务端渲染层。
 *
 * 负责读取查询参数、组合 PUBG API 返回值，并输出首屏所需的玩家概览、赛季表现和前端队列上下文。
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'engine.php';

$errorMsg   = '';
$resultData = null;
$loading    = false;
$nickname   = '';

if (isset($_GET['nickname'])) {
    $nickname = trim((string)$_GET['nickname']);
    $loading  = true;

    if (empty($nickname)) {
        $errorMsg = '🎯 游戏昵称不能为空';
        $loading  = false;
    } elseif (empty($apiKeys)) {
        $errorMsg = '🔑 服务器未配置 PUBG API Key，请先在环境变量 PUBG_API_KEYS 或 config/pubg_keys.php 中配置密钥。';
        $loading  = false;
    } elseif (empty($currentSeasonId)) {
        $errorMsg = '🔴 官方赛季接口通信失败，请稍后重试。';
        $loading  = false;
    } else {
        $encodedNickname = rawurlencode($nickname);
        $playerUrl       = "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players?filter[playerNames]={$encodedNickname}";
        $playerRes       = pubgApiRequest($playerUrl, 120); 
        $loading         = false;

        if ($playerRes['code'] !== 200) {
            $errorMsg = $playerRes['code'] === 404 ? '❌ 未找到该玩家，请检查游戏昵称拼写' : "⚠️ 请求失败(HTTP {$playerRes['code']})";
        } elseif (empty($playerRes['data']['data'])) {
            $errorMsg = '🔍 未查询到玩家信息';
        } else {
            $playerInfo  = $playerRes['data']['data'][0];
            $accountId   = $playerInfo['id'];
            $banType     = $playerInfo['attributes']['banType'] ?? 'Unknown';
            $clanId      = $playerInfo['attributes']['clanId'] ?? null;
            $matches     = $playerInfo['relationships']['matches']['data'] ?? [];
            $lastMatchId = !empty($matches) ? $matches[0]['id'] : null;

            $masteryRes    = pubgApiRequest("{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/{$accountId}/survival_mastery", 120);
            $currRes       = pubgApiRequest("{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/{$accountId}/seasons/{$currentSeasonId}", 900);
            $currRankedRes = pubgApiRequest("{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/{$accountId}/seasons/{$currentSeasonId}/ranked", 900); 
            $lifeRes       = pubgApiRequest("{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/{$accountId}/seasons/lifetime", 900);
            
            $clanRes  = ['code' => 0];
            $matchRes = ['code' => 0];
            
            if ($clanId) {
                $clanRes = pubgApiRequest("{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/clans/{$clanId}", 3600);
            }
            if ($lastMatchId) {
                $matchRes = pubgApiRequest("{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/matches/{$lastMatchId}", 900);
            }

            $clanHtml = '';
            if ($clanId && $clanRes['code'] === 200 && isset($clanRes['data']['data']['attributes'])) {
                $clanAttrs = $clanRes['data']['data']['attributes'];
                $clanTag   = $clanAttrs['clanTag'] ?? '';
                if ($clanTag) {
                    $hoverTitle = htmlspecialchars(
                        "公会名称: " . ($clanAttrs['clanName'] ?? '未知') . "\n公会等级: Lv." . ($clanAttrs['clanLevel'] ?? 1) . "\n公会人数: " . ($clanAttrs['clanMemberCount'] ?? 0) . " 人",
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $clanTagSafe = htmlspecialchars($clanTag, ENT_QUOTES, 'UTF-8');
                    $clanHtml    = "<span class='clan-badge' title='{$hoverTitle}'>[{$clanTagSafe}]</span>";
                }
            }

            $baseLevel  = 0;
            $playerTier = 1;
            $playerXP   = 0;
            $totalLevel = 0;
            $xpPercent  = 0;
            $xpPerLevel = 2000;
            $currentLevelProgressXP = 0;
            $nextLevelPercent = 0;
            $nextTier = 1;
            $nextLevel = 1;
            $isMaxSurvivalLevel = false;

            if ($masteryRes['code'] === 200 && isset($masteryRes['data']['data']['attributes']['level'])) {
                $baseLevel  = intval($masteryRes['data']['data']['attributes']['level']);
                $playerTier = max(1, intval($masteryRes['data']['data']['attributes']['tier'] ?? 1));
                $playerXP   = intval($masteryRes['data']['data']['attributes']['xp'] ?? 0);
                $xpPercent  = ($playerXP / 4500000) * 100;
                $totalLevel = (($playerTier - 1) * 500) + $baseLevel;
                $isMaxSurvivalLevel = $totalLevel >= 2500;

                $getXpRequirement = function($lvl) {
                    if ($lvl < 10) return 100;
                    if ($lvl < 20) return 300;
                    if ($lvl < 30) return 500;
                    if ($lvl < 40) return 700;
                    if ($lvl < 50) return 900;
                    if ($lvl < 60) return 1100;
                    if ($lvl < 70) return 1300;
                    if ($lvl < 80) return 1500;
                    return 2000;
                };

                $simXp    = $playerXP;
                $simLevel = 1;
                while ($simXp > 0) {
                    $req = $getXpRequirement($simLevel);
                    if ($simXp >= $req) {
                        $simXp -= $req;
                        $simLevel++;
                        if ($simLevel > 500) {
                            $simLevel = 1;
                        }
                    } else {
                        break;
                    }
                }

                $xpPerLevel             = $getXpRequirement($baseLevel);
                $currentLevelProgressXP = max(0, $simXp);
                $currentLevelProgressXP = min($currentLevelProgressXP, $xpPerLevel);
                $nextLevelPercent       = ($xpPerLevel > 0) ? ($currentLevelProgressXP / $xpPerLevel) * 100 : 100;
                $nextTier               = $baseLevel >= 500 ? min(5, $playerTier + 1) : $playerTier;
                $nextLevel              = $baseLevel >= 500 ? 1 : $baseLevel + 1;
            }

            $recentMatchIds = [];
            $lastMatchTime  = '暂无对局 (可能超14天未游戏)';
            if (!empty($matches)) {
                $latest20Matches = array_slice($matches, 0, 20);
                foreach ($latest20Matches as $mItem) { $recentMatchIds[] = $mItem['id']; }
                if ($lastMatchId && $matchRes['code'] === 200 && isset($matchRes['data']['data']['attributes']['createdAt'])) {
                    $dt = new DateTime($matchRes['data']['data']['attributes']['createdAt']);
                    $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
                    $lastMatchTime = $dt->format('Y-m-d H:i:s');
                }
            }

            $banTypeMap = [
                'Innocent'     => ['text' => '未封禁',     'class' => 'normal'], 
                'TemporaryBan' => ['text' => '临时封禁',   'class' => 'temporary'],
                'Permanent'    => ['text' => '永久封禁',   'class' => 'permanent'], 
                'PermanentBan' => ['text' => '永久封禁',   'class' => 'permanent'],
                'Suspended'    => ['text' => '临时封禁',   'class' => 'temporary'], 
                'Warning'      => ['text' => '未封禁',     'class' => 'normal'],
            ];
            $banInfo = $banTypeMap[$banType] ?? ['text' => '未知状态', 'class' => 'unknown'];

            $currExtracted       = extractSeasonData($currRes['data']['data']['attributes']['gameModeStats'] ?? [], $gameModes);
            $lifeExtracted       = extractSeasonData($lifeRes['data']['data']['attributes']['gameModeStats'] ?? [], $gameModes);
            $currRankedExtracted = extractRankedSeasonData($currRankedRes['data']['data']['attributes']['rankedGameModeStats'] ?? [], $gameModes, $tierLabels);
            
            $realLifeStats = [
                'walk' => 0, 'ride' => 0, 'swim' => 0, 'heals' => 0, 'boosts' => 0,
                'damage' => 0, 'time' => 0, 'revives' => 0, 'weapons' => 0, 'roadKills' => 0
            ];
            
            if (isset($lifeRes['data']['data']['attributes']['gameModeStats'])) {
                foreach ($lifeRes['data']['data']['attributes']['gameModeStats'] as $modeStats) {
                    $realLifeStats['walk']      += $modeStats['walkDistance'] ?? 0;
                    $realLifeStats['ride']      += $modeStats['rideDistance'] ?? 0;
                    $realLifeStats['swim']      += $modeStats['swimDistance'] ?? 0;
                    $realLifeStats['heals']     += $modeStats['heals'] ?? 0;
                    $realLifeStats['boosts']    += $modeStats['boosts'] ?? 0;
                    $realLifeStats['damage']    += $modeStats['damageDealt'] ?? 0;
                    $realLifeStats['time']      += $modeStats['timeSurvived'] ?? 0;
                    $realLifeStats['revives']   += $modeStats['revives'] ?? 0;
                    $realLifeStats['weapons']   += $modeStats['weaponsAcquired'] ?? 0;
                    $realLifeStats['roadKills'] += $modeStats['roadKills'] ?? 0;
                }
            }

            $highestKD           = [];
            $highestRankedByMode = [];
            $missingScanQueue    = [];
            $playedSeasons       = [];
            $rankedPlayedSeasons = [];
            $totalHistoryTargets = 0;
            $resolvedTargets     = 0;
            $normalMissingCount  = 0;
            $highestRankScore    = 0;
            $lifetimeHighestRank = null;
            $earliestSeasonId    = null;
            
            if ($currExtracted['has_data']) {
                $earliestSeasonId = $currentSeasonId;
                $playedSeasons[]  = $seasonConfig[$currentSeasonId]['name'] . ' (' . $seasonConfig[$currentSeasonId]['month'] . ')';
                foreach ($gameModes as $mk => $mn) {
                    $kdInfo = $currExtracted['data'][$mk];
                    if ($kdInfo['kills'] > 0 || $kdInfo['total_matches'] > 0) {
                        $highestKD[$mk]                 = $kdInfo; 
                        $highestKD[$mk]['season_name']  = $seasonConfig[$currentSeasonId]['name']; 
                        $highestKD[$mk]['season_month'] = $seasonConfig[$currentSeasonId]['month'];
                    }
                }
            }

            if ($currRankedExtracted['has_data']) {
                $rankedPlayedSeasons[] = $seasonConfig[$currentSeasonId]['name'] . ' (' . $seasonConfig[$currentSeasonId]['month'] . ')';
                updateHighestRankedModeData($currRankedExtracted['data'], $currentSeasonId, $highestRankedByMode, $seasonConfig);
            }
            
            foreach ($seasonIds as $idx => $sId) {
                if ($sId !== $currentSeasonId) {
                    $totalHistoryTargets++;
                    $sUrl       = "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/{$accountId}/seasons/{$sId}";
                    $sCacheFile = findApiCacheFile($sUrl);
                    
                    if (file_exists($sCacheFile)) {
                        $resolvedTargets++;
                        $cData      = json_decode(file_get_contents($sCacheFile), true);
                        $cData      = is_array($cData) ? $cData : [];
                        $sStats     = $cData['data']['attributes']['gameModeStats'] ?? [];
                        $sExtracted = extractSeasonData($sStats, $gameModes);
                        
                        if ($sExtracted['has_data']) {
                            $earliestSeasonId = $sId; 
                            $playedSeasons[]  = $seasonConfig[$sId]['name'] . ' (' . $seasonConfig[$sId]['month'] . ')';
                            foreach ($gameModes as $mk => $mn) {
                                $kdInfo = $sExtracted['data'][$mk];
                                if ($kdInfo['kills'] > 0 || $kdInfo['total_matches'] > 0) {
                                    if (!isset($highestKD[$mk]) || $kdInfo['kd_value'] > $highestKD[$mk]['kd_value']) {
                                        $highestKD[$mk]                 = $kdInfo; 
                                        $highestKD[$mk]['season_name']  = $seasonConfig[$sId]['name']; 
                                        $highestKD[$mk]['season_month'] = $seasonConfig[$sId]['month'];
                                    }
                                }
                            }
                        }
                    } else { 
                        $normalMissingCount++;
                        $missingScanQueue[] = ['type' => 'normal', 'id' => $sId]; 
                    }
                }

                $sNum = (int) substr($sId, strrpos($sId, '-') + 1);
                if ($sNum >= 7) {
                    if ($sId === $currentSeasonId) {
                        updateHighestRankScore($currRankedRes['data'] ?? [], $sId, $highestRankScore, $lifetimeHighestRank, $seasonConfig, $gameModes);
                    } else {
                        $totalHistoryTargets++;
                        $rUrl       = "{$apiConfig['baseUrl']}/shards/{$apiConfig['shard']}/players/{$accountId}/seasons/{$sId}/ranked";
                        $rCacheFile = findApiCacheFile($rUrl);
                        
                        if (file_exists($rCacheFile)) {
                            $resolvedTargets++;
                            $rData      = json_decode(file_get_contents($rCacheFile), true);
                            $rData      = is_array($rData) ? $rData : [];
                            $rExtracted = extractRankedSeasonData($rData['data']['attributes']['rankedGameModeStats'] ?? [], $gameModes, $tierLabels);
                            updateHighestRankScore($rData ?? [], $sId, $highestRankScore, $lifetimeHighestRank, $seasonConfig, $gameModes);
                            if ($rExtracted['has_data']) {
                                $rankedPlayedSeasons[] = $seasonConfig[$sId]['name'] . ' (' . $seasonConfig[$sId]['month'] . ')';
                                updateHighestRankedModeData($rExtracted['data'], $sId, $highestRankedByMode, $seasonConfig);
                            }
                        } else {
                            $missingScanQueue[] = ['type' => 'ranked', 'id' => $sId];
                        }
                    }
                }
            }

            $scanProgressPercent = $totalHistoryTargets > 0
                ? floor(($resolvedTargets / $totalHistoryTargets) * 100)
                : 100;
                
            $joinTimeFormatted = null;
            if ($normalMissingCount === 0 && $earliestSeasonId) {
                 $eNum              = (int) substr($earliestSeasonId, strrpos($earliestSeasonId, '-') + 1);
                 $eMonth            = $seasonConfig[$earliestSeasonId]['month'];
                 $eName             = $eNum . '赛季';
                 $joinTimeFormatted = ($eNum === 1) ? "{$eMonth} 或之前 ({$eName})" : "{$eMonth} ({$eName})";
            }

            $rankedPlayedSeasons = array_values(array_unique($rankedPlayedSeasons));

            if (!$currExtracted['has_data'] && !$lifeExtracted['has_data'] && !$currRankedExtracted['has_data'] && empty($highestKD) && empty($highestRankedByMode) && empty($playedSeasons)) {
                $errorMsg = '🔴 该玩家账号内没有任何有效对局数据';
            } else {
                $resultData = [
                    'name'             => $playerInfo['attributes']['name'],
                    'clan_html'        => $clanHtml,
                    'base_level'       => $baseLevel,
                    'level'            => $totalLevel, 
                    'tier'             => $playerTier,
                    'xp'               => $playerXP,
                    'xp_percent'       => $xpPercent,
                    'level_progress_xp'=> $currentLevelProgressXP,
                    'xp_per_level'     => $xpPerLevel,
                    'next_level_pct'   => $nextLevelPercent,
                    'next_tier'        => $nextTier,
                    'next_base_level'  => $nextLevel,
                    'is_max_level'     => $isMaxSurvivalLevel,
                    'join_time'        => $joinTimeFormatted,
                    'account_id'       => $accountId,
                    'last_match'       => $lastMatchTime,
                    'recent_match_ids' => $recentMatchIds,
                    'real_life_stats'  => $realLifeStats, 
                    'shard'            => $selectedShard,
                    'ban_status'       => $banInfo['text'],
                    'ban_class'        => $banInfo['class'],
                    'ban_type_raw'     => $banType,
                    'current_season'   => getSeasonInfo($currentSeasonId, $seasonConfig),
                    'current_data'     => $currExtracted['data'],
                    'has_curr_data'    => $currExtracted['has_data'],
                    'ranked_data'      => $currRankedExtracted['data'],
                    'has_ranked_data'  => $currRankedExtracted['has_data'],
                    'highest_ranked'   => $highestRankedByMode,
                    'lifetime_data'    => $lifeExtracted['data'],
                    'has_life_data'    => $lifeExtracted['has_data'],
                    'highest_kd'       => $highestKD,
                    'played_seasons'   => $playedSeasons,
                    'played_count'     => count($playedSeasons),
                    'ranked_seasons'   => $rankedPlayedSeasons,
                    'ranked_count'     => count($rankedPlayedSeasons),
                    'game_modes'       => $gameModes,
                    'progress'         => $scanProgressPercent,
                    'peak_rank'        => $lifetimeHighestRank
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>PUBG 战绩深度查询引擎</title>
    <script>
        document.documentElement.setAttribute('data-theme', 'dark');
    </script>
    <link rel="stylesheet" href="assets/css/style.css?v=39.4" charset="UTF-8">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1 class="title">🎮 PUBG 匹配与竞技战绩查询引擎</h1>
            <div class="subtitle">收录第 1 赛季至今 <?php echo count($seasonConfig); ?> 个匹配赛季，并支持第 7 赛季后的官方竞技数据</div>
        </div>
        
        <form class="query-form" method="GET" autocomplete="off">
            <div class="form-container">
                <div class="platform-tabs">
                    <label>
                        <input type="radio" name="shard" value="steam" <?php echo $selectedShard === 'steam' ? 'checked' : ''; ?>>
                        <span class="plat-btn"><img src="https://p0.meituan.net/poiugc/620e3c83b0c01d086334433e0185ae772320.png" alt="Steam" width="18" height="18" decoding="async"> Steam</span>
                    </label>
                    <label>
                        <input type="radio" name="shard" value="kakao" <?php echo $selectedShard === 'kakao' ? 'checked' : ''; ?>>
                        <span class="plat-btn"><img src="https://p0.meituan.net/poiugc/b3bb75065fd06aac94e9157e66244a7e2120.png" alt="Kakao" width="18" height="18" decoding="async"> Kakao</span>
                    </label>
                    <label>
                        <input type="radio" name="shard" value="xbox" <?php echo $selectedShard === 'xbox' ? 'checked' : ''; ?>>
                        <span class="plat-btn"><img src="https://p0.meituan.net/poiugc/8cd37b70df73fb9d97fbde54848e73e22101.png" alt="Xbox" width="18" height="18" decoding="async"> Xbox</span>
                    </label>
                    <label>
                        <input type="radio" name="shard" value="psn" <?php echo $selectedShard === 'psn' ? 'checked' : ''; ?>>
                        <span class="plat-btn"><img src="https://p0.meituan.net/poiugc/c1017cfed28479f0680276efeee49f903117.png" alt="PSN" width="18" height="18" decoding="async"> PSN</span>
                    </label>
                </div>
                
                <div class="search-box-wrapper">
                    <button type="button" id="historyBtn" class="history-toggle" title="查看历史搜索记录">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="18" height="18">
                            <path fill="currentColor" d="M512 896a384 384 0 1 0 0-768 384 384 0 0 0 0 768m0 64a448 448 0 1 1 0-896 448 448 0 0 1 0 896"></path>
                            <path fill="currentColor" d="M480 256a32 32 0 0 1 32 32v256a32 32 0 0 1-64 0V288a32 32 0 0 1 32-32"></path>
                            <path fill="currentColor" d="M480 512h256q32 0 32 32t-32 32H480q-32 0-32-32t32-32"></path>
                        </svg>
                    </button>
                    <input type="text" id="nickname" name="nickname" class="form-input" value="<?php echo htmlspecialchars((string)$nickname); ?>" placeholder="输入玩家游戏昵称" autocomplete="off" required>
                    <button type="submit" class="submit-btn">查询</button>
                </div>
            </div>
        </form>

        <div class="status-box loading <?php echo $loading ? 'show' : ''; ?>">⌛ 正在极速拉取近期数据，请稍候…</div>
        <div class="status-box error <?php echo $errorMsg ? 'show' : ''; ?>"><?php echo htmlspecialchars($errorMsg); ?></div>

        <?php if (isset($resultData) && $resultData): ?>
        <div class="result-card show">
            
            <h2 class="result-title">📊 玩家基础档案</h2>
            
            <div class="dashboard-header">
                <div class="dash-card profile-card">
                    <div class="dash-label">游戏昵称</div>
                    <div class="dash-val-main" style="display: flex; align-items: center;">
                        <?php echo $resultData['clan_html']; ?>
                        <span><?php echo htmlspecialchars($resultData['name']); ?></span>
                    </div>
                    <div class="dash-label" style="margin-top: 16px;">账号状态</div>
                    <div id="accountBanStatusText" style="font-weight: 700; font-size: 15px;" class="ban-<?php echo $resultData['ban_class']; ?>" data-source="pubg-api">
                        <?php 
                            $statusParts = explode(' ', trim($resultData['ban_status']));
                            echo array_pop($statusParts) . ' ' . implode(' ', $statusParts); 
                        ?>
                    </div>
                    <div id="accountBanRawText" style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; font-family: monospace;" data-pubg-raw="<?php echo htmlspecialchars($resultData['ban_type_raw'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo $resultData['ban_type_raw']; ?>
                    </div>
                    <?php if ($selectedShard === 'steam' && hasSteamHelpSessionForAccount($resultData['account_id'])): ?>
                    <div class="steam-vac-check" id="steamVacCheck" data-account-id="<?php echo htmlspecialchars($resultData['account_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="steam-vac-head">
                            <span>Steam VAC校验</span>
                            <button type="button" id="steamVacRefreshBtn" aria-label="刷新 Steam VAC校验" title="刷新 Steam VAC校验">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M20 12a8 8 0 0 1-13.66 5.66M4 12A8 8 0 0 1 17.66 6.34"></path>
                                    <path d="M7 18H4v-3M17 6h3v3"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="steam-vac-result" id="steamVacResult" data-state="loading">
                            正在校验 Steam VAC接口...
                        </div>
                        <div class="steam-vac-meta" id="steamVacMeta">
                            每 3 分钟自动校验一次
                        </div>
                        <div class="steam-vac-next" id="steamVacNextRefresh"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="dash-card level-card">
                    <div class="dash-label">生存等级与成长进度</div>
                    <div class="level-display">
                        <?php 
                            $tierImgs = [
                                1 => 'https://p0.meituan.net/poiugc/f70becfa1ea550a489019df69aa59b4616960.png',
                                2 => 'https://p0.meituan.net/poiugc/0fc06ee9c68a02183bd9ecde6c5a8a1e14562.png',
                                3 => 'https://p0.meituan.net/poiugc/698daaa5eafb158fee673121b1612ea111742.png',
                                4 => 'https://p0.meituan.net/poiugc/3471fc609b25c733311f1ba72fd230dd14357.png',
                                5 => 'https://p0.meituan.net/poiugc/4d431da878d0f778d495f01f03d053c813035.png'
                            ];
                            $tLevel   = (int)$resultData['tier'];
                            $imgLevel = $tLevel > 5 ? 5 : ($tLevel < 1 ? 1 : $tLevel);
                            $tierImgSrc = $tierImgs[$imgLevel];
                        ?>
                        <img src="<?php echo $tierImgSrc; ?>" class="tier-icon" onerror="this.style.display='none'">
                        <div class="lvl-text">
                            <span class="lvl-main">Lv.<?php echo $resultData['level']; ?></span>
                            <span class="lvl-sub">(<?php echo $resultData['tier']; ?>段 <?php echo $resultData['base_level']; ?>级)</span>
                        </div>
                    </div>
                    
                    <div class="xp-wrapper">
                        <div class="xp-info">
                            <div class="xp-numbers">
                                <?php echo number_format($resultData['xp']); ?> / 4,500,000 XP
                            </div>
                            <div style="text-align: right;">
                                <div class="xp-percent"><?php echo number_format($resultData['xp_percent'], 2); ?>%</div>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">满级进度</div>
                            </div>
                        </div>
                        <div class="xp-bar-bg" title="4,500,000 XP 基准总进程">
                            <div class="xp-bar-fill" style="width: <?php echo max(0, $resultData['xp_percent']); ?>%;"></div>
                        </div>

                        <?php if ($resultData['level'] < 2500): ?>
                        <div class="next-level-box" style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed rgba(148, 163, 184, 0.15);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px;">
                                <div style="font-size: 12px; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-color);"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg>
                                    升级进度 (Lv.<?php echo $resultData['base_level']; ?> &rarr; Lv.<?php echo $resultData['base_level'] + 1; ?>)
                                </div>
                                <div style="font-size: 12px; font-weight: 700; color: var(--text-color); font-family: monospace;">
                                    <?php echo number_format($resultData['level_progress_xp']); ?> / <?php echo number_format($resultData['xp_per_level']); ?> XP
                                </div>
                            </div>
                            <div class="xp-bar-bg" style="height: 4px; border-radius: 2px; background: rgba(129, 140, 248, 0.15); margin-bottom: 0;">
                                <div class="xp-bar-fill" style="width: <?php echo min(100, $resultData['next_level_pct']); ?>%; border-radius: 2px; background: linear-gradient(90deg, #6366f1, #a5b4fc); box-shadow: 0 0 8px rgba(99,102,241,0.3); transition: width 1s ease-in-out;"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="join-time" id="joinTimeContainer" style="margin-top: 12px;">
                            入坑时间: 
                            <?php if ($resultData['join_time']): ?>
                                <?php echo $resultData['join_time']; ?>
                            <?php else: ?>
                                <a id="showJoinTimeModal">点击查询</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="dash-card platform-card">
                    <div class="dash-label">所属平台</div>
                    <div class="platform-chip">
                        <img src="<?php echo $platIcons[$selectedShard] ?? $platIcons['steam']; ?>" alt="Platform" width="34" height="34" decoding="async">
                        <div class="platform-name">
                            <?php 
                                $shardDisplayMap = ['steam' => 'Steam', 'kakao' => 'Kakao', 'xbox' => 'Xbox', 'psn' => 'PSN'];
                                echo htmlspecialchars($shardDisplayMap[$resultData['shard']] ?? ucfirst($resultData['shard'])); 
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-footer footer-count-<?php echo $resultData['peak_rank'] ? '3' : '2'; ?>">
                <div class="df-card">
                    <div class="dash-label">唯一ID</div>
                    <div style="font-size: 14px; font-weight: 600; word-break: break-all; font-family: monospace;">
                        <?php echo htmlspecialchars($resultData['account_id']); ?>
                    </div>
                </div>
                <div class="df-card">
                    <div class="dash-label">⏱️ 最近比赛时间</div>
                    <div style="font-size: 15px; font-weight: 700; color: var(--info-color);">
                        <?php echo htmlspecialchars($resultData['last_match']); ?>
                    </div>
                </div>
                <?php if ($resultData['peak_rank']): 
                    $peakRank   = $resultData['peak_rank'];
                    $tName      = $peakRank['tier'];
                    $sName      = $peakRank['subTier'];
                    $bestRP     = $peakRank['bestRP'];
                    $currRP     = $peakRank['currentRP'];
                    $pSeasonId  = $peakRank['season_id'];
                    $isPeakCurr = ($pSeasonId === $currentSeasonId);

                    $imgKey = in_array($tName, ['Master', 'Survivor', 'Grandmaster']) ? $tName : "{$tName}_{$sName}";
                    if ($tName === 'Grandmaster') {
                        $imgKey = 'Survivor';
                    }
                    $imgSrc = $rankImages[$imgKey] ?? '';
                    $label  = $tierLabels[$tName] . ($sName ? $sName : '');
                    if (in_array($tName, ['Master', 'Survivor', 'Grandmaster'])) {
                        $label = $tierLabels[$tName];
                    }
                ?>
                <div class="df-card">
                    <div class="dash-label">🏆 生涯最高段位</div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-top: auto; margin-bottom: 8px;">
                        <img src="<?php echo $imgSrc; ?>" alt="<?php echo $label; ?>" style="width: 42px; height: 42px; object-fit: contain; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));">
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-size: 20px; font-weight: 800; color: var(--warning-color); line-height: 1.2;">
                                <?php echo $label; ?>
                            </span>
                            <span style="font-size: 13px; font-weight: 600; color: var(--text-color); background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.15);">
                                <?php echo $peakRank['mode']; ?>
                            </span>
                            <?php if (in_array($tName, ['Master', 'Survivor', 'Grandmaster'])): ?>
                            <span style="font-size: 13px; font-weight: 700; color: #fcd34d; background: rgba(252, 211, 77, 0.1); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(252, 211, 77, 0.2);">
                                <?php echo $isPeakCurr ? "当前 {$currRP} <span style='opacity:0.5; margin:0 2px;'>/</span> 最高 {$bestRP} RP" : "最高 {$bestRP} RP"; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size: 12px; color: var(--text-secondary); text-align: left; font-weight: 500;">
                        <?php echo $peakRank['season_month'] . ' (' . $peakRank['season_name'] . ')'; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($resultData['has_curr_data']): 
                $currValidCount = 0;
                foreach ($resultData['game_modes'] as $mk => $mn) {
                    if ($resultData['current_data'][$mk]['kills'] > 0 || $resultData['current_data'][$mk]['total_matches'] > 0) $currValidCount++;
                }
            ?>
            <div class="data-panel primary-match-panel">
                <div class="section-title section-header">
                    📊 当前匹配表现 (<?php echo $resultData['current_season']['name'] . ' · ' . $resultData['current_season']['month']; ?>)
                    <span class="section-count-badge"><?php echo $currValidCount; ?> 模式</span>
                </div>
                <div class="mode-grid match-grid grid-count-<?php echo $currValidCount; ?>">
                <?php foreach ($resultData['game_modes'] as $modeKey => $modeName): 
                    $currentData = $resultData['current_data'][$modeKey];
                    if ($currentData['kills'] > 0 || $currentData['total_matches'] > 0): 
                ?>
                    <div class="mode-card current-season match-card">
                        <div class="mode-header">
                            <div class="mode-name"><?php echo $modeName; ?></div>
                            <div class="mode-kd">平均淘汰: <?php echo $currentData['kpm']; ?> <span style="opacity:0.5;margin:0 4px;">|</span> K/D: <?php echo $currentData['kd']; ?></div>
                        </div>
                        <div class="mode-stats"><?php renderStatsHtml($currentData); ?></div>
                    </div>
                <?php endif; endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($resultData['has_ranked_data']): 
                $rankedValidCount = 0;
                foreach ($resultData['ranked_data'] as $rk => $rankedData) {
                    if (!empty($rankedData['has_data'])) $rankedValidCount++;
                }
            ?>
            <div class="data-panel ranked-panel">
                <div class="section-title section-header" style="border-left-color: var(--warning-color);">
                    ⚔️ 当前竞技表现 (<?php echo $resultData['current_season']['name'] . ' · ' . $resultData['current_season']['month']; ?>)
                    <span class="section-count-badge"><?php echo $rankedValidCount; ?> 模式</span>
                </div>
                <div class="ranked-grid grid-count-<?php echo $rankedValidCount; ?>">
                    <?php foreach ($resultData['ranked_data'] as $modeKey => $rankedData): 
                        if (empty($rankedData['has_data'])) continue;
                        $modeName = $resultData['game_modes'][$modeKey] ?? $modeKey;
                        $rankKey  = $rankedData['current_rank_image_key'] ?: $rankedData['best_rank_image_key'];
                        $rankImg  = $rankImages[$rankKey] ?? '';
                    ?>
                        <div class="mode-card ranked-card">
                            <div class="rank-showcase">
                                <?php if ($rankImg): ?>
                                    <img src="<?php echo $rankImg; ?>" alt="<?php echo $rankedData['current_rank_label']; ?>" class="rank-emblem" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="rank-emblem rank-emblem-empty"></div>
                                <?php endif; ?>
                                <div class="rank-meta">
                                    <div class="mode-name"><?php echo $modeName; ?></div>
                                    <div class="rank-title"><?php echo $rankedData['current_rank_label']; ?></div>
                                    <div class="rank-sub">当前 <?php echo number_format($rankedData['current_rank_point']); ?> RP · 最高 <?php echo $rankedData['best_rank_label']; ?> <?php echo number_format($rankedData['best_rank_point']); ?> RP</div>
                                </div>
                            </div>
                            <div class="mode-stats ranked-stats"><?php renderRankedStatsHtml($rankedData); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="section-title section-header" style="border-left-color: var(--success-color);">👥 核心组队玩家 (最近20场对局提取)</div>
            <div class="teammates-box" id="teammatesBox">
                <div id="teammateInitUI" style="padding: 40px 20px; text-align: center;">
                    <div style="font-size: 36px; margin-bottom: 12px; opacity:0.8;">🕵️‍♂️</div>
                    <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 24px; max-width: 400px; margin-left: auto; margin-right: auto; line-height: 1.5;">逐场解析该玩家最近 20 场对局的队友名单，并生成高频互动排行榜。</p>
                    <button id="startAnalyzeBtn" class="analyze-btn">开始解析</button>
                </div>
                <div id="teammateProgressUI" style="display: none; padding: 40px 20px; text-align: center; color: var(--text-secondary);">
                    <div style="font-size: 15px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;">
                        正在解析数据... <span id="analyzeProgressText">(0/<?php echo count($resultData['recent_match_ids']); ?>)</span>
                    </div>
                    <div class="progress-container"><div class="progress-bar" id="analyzeProgressBar"></div></div>
                </div>
                <div id="teammateResultUI" style="display: none;"></div>
            </div>

            <?php if (!empty($resultData['highest_ranked'])): 
                $highestRankedCount = count($resultData['highest_ranked']);
            ?>
            <details class="data-panel ranked-panel">
                <summary class="section-title section-header" style="border-left-color: #f97316;">
                    🏆 历史竞技峰值
                    <span class="section-count-badge"><?php echo $highestRankedCount; ?> 模式</span>
                </summary>
                <div class="ranked-grid grid-count-<?php echo $highestRankedCount; ?>">
                    <?php foreach ($resultData['highest_ranked'] as $modeKey => $rankedData): 
                        $modeName = $resultData['game_modes'][$modeKey] ?? $modeKey;
                        $rankKey  = $rankedData['best_rank_image_key'] ?: $rankedData['current_rank_image_key'];
                        $rankImg  = $rankImages[$rankKey] ?? '';
                    ?>
                        <div class="mode-card ranked-card peak-ranked">
                            <div class="rank-showcase">
                                <?php if ($rankImg): ?>
                                    <img src="<?php echo $rankImg; ?>" alt="<?php echo $rankedData['best_rank_label']; ?>" class="rank-emblem" onerror="this.style.display='none'">
                                <?php else: ?>
                                    <div class="rank-emblem rank-emblem-empty"></div>
                                <?php endif; ?>
                                <div class="rank-meta">
                                    <div class="mode-name"><?php echo $modeName; ?></div>
                                    <div class="rank-title"><?php echo $rankedData['best_rank_label']; ?></div>
                                    <div class="rank-sub">最高 <?php echo number_format($rankedData['best_rank_point']); ?> RP · <?php echo $rankedData['season_name'] . ' (' . $rankedData['season_month'] . ')'; ?></div>
                                </div>
                            </div>
                            <div class="mode-stats ranked-stats"><?php renderRankedStatsHtml($rankedData); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>

            <?php if ($resultData['has_life_data']): 
                $lifeValidCount = 0;
                foreach ($resultData['game_modes'] as $mk => $mn) {
                    if ($resultData['lifetime_data'][$mk]['kills'] > 0 || $resultData['lifetime_data'][$mk]['total_matches'] > 0) $lifeValidCount++;
                }
            ?>
            <details class="data-panel">
                <summary class="section-title section-header" style="border-left-color: var(--warning-color);">
                    🌟 匹配生涯综合 (全赛季场次累加总和)
                    <span class="section-count-badge"><?php echo $lifeValidCount; ?> 模式</span>
                </summary>
                <div class="mode-grid grid-count-<?php echo $lifeValidCount; ?>">
                <?php foreach ($resultData['game_modes'] as $modeKey => $modeName): 
                    $lifeData = $resultData['lifetime_data'][$modeKey];
                    if ($lifeData['kills'] > 0 || $lifeData['total_matches'] > 0): 
                ?>
                    <div class="mode-card lifetime-season">
                        <div class="mode-header">
                            <div class="mode-name"><?php echo $modeName; ?></div>
                            <div class="mode-kd">平均淘汰: <?php echo $lifeData['kpm']; ?> <span style="opacity:0.5;margin:0 4px;">|</span> K/D: <?php echo $lifeData['kd']; ?></div>
                        </div>
                        <div class="mode-stats"><?php renderStatsHtml($lifeData); ?></div>
                        <div class="season-info">数据来源: Lifetime 接口</div>
                    </div>
                <?php endif; endforeach; ?>
                </div>
            </details>
            <?php endif; ?>

            <?php if (!empty($resultData['real_life_stats'])): $rs = $resultData['real_life_stats']; ?>
            <details class="data-panel">
                <summary class="section-title section-header" style="border-left-color: #8b5cf6;">🎒 匹配生涯总计</summary>
                <div class="mini-stat-grid">
                <div class="ms-card"><div class="ms-icon">🏃</div><div class="ms-val"><?php echo round($rs['walk']/1000, 1); ?> km</div><div class="ms-lbl">奔跑总里程</div></div>
                <div class="ms-card"><div class="ms-icon">🚗</div><div class="ms-val"><?php echo round($rs['ride']/1000, 1); ?> km</div><div class="ms-lbl">驾驶总里程</div></div>
                <div class="ms-card"><div class="ms-icon">🏊</div><div class="ms-val"><?php echo round($rs['swim']/1000, 1); ?> km</div><div class="ms-lbl">游泳总里程</div></div>
                <div class="ms-card"><div class="ms-icon">🔫</div><div class="ms-val"><?php echo number_format($rs['weapons']); ?> 把</div><div class="ms-lbl">总拾取武器</div></div>
                <div class="ms-card"><div class="ms-icon">💀</div><div class="ms-val"><?php echo number_format($rs['roadKills']); ?> 次</div><div class="ms-lbl">载具碾杀敌人</div></div>
                <div class="ms-card"><div class="ms-icon">⚡</div><div class="ms-val"><?php echo number_format($rs['boosts']); ?> 个</div><div class="ms-lbl">使用能量饮料</div></div>
                <div class="ms-card"><div class="ms-icon">🚑</div><div class="ms-val"><?php echo number_format($rs['revives']); ?> 次</div><div class="ms-lbl">扶起倒地队友</div></div>
                <div class="ms-card"><div class="ms-icon">💊</div><div class="ms-val"><?php echo number_format($rs['heals']); ?> 次</div><div class="ms-lbl">打药回复次数</div></div>
                <div class="ms-card"><div class="ms-icon">💥</div><div class="ms-val" style="color:var(--danger-color);"><?php echo number_format(round($rs['damage'])); ?></div><div class="ms-lbl">累计造成伤害</div></div>
                <div class="ms-card"><div class="ms-icon">⏱️</div><div class="ms-val" style="color:var(--warning-color);"><?php echo round($rs['time']/3600, 1); ?> h</div><div class="ms-lbl">生涯存活时长</div></div>
                </div>
            </details>
            <?php endif; ?>

            <details class="data-panel">
                <summary class="section-title section-header" style="border-left-color: var(--info-color);">
                    🏅 活跃赛季分布
                    <span class="section-count-badge">匹配 <?php echo $resultData['played_count']; ?> · 竞技 <?php echo $resultData['ranked_count']; ?></span>
                </summary>
                <div class="season-group-title">匹配赛季</div>
                <div class="season-badges compact">
                    <?php if ($resultData['played_count'] > 0): ?>
                        <?php foreach ($resultData['played_seasons'] as $ps): ?>
                            <span class="season-badge"><?php echo $ps; ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="season-badge empty">暂无记录</span>
                    <?php endif; ?>
                </div>
                <div class="season-group-title">竞技赛季</div>
                <div class="season-badges compact">
                    <?php if ($resultData['ranked_count'] > 0): ?>
                        <?php foreach ($resultData['ranked_seasons'] as $ps): ?>
                            <span class="season-badge ranked"><?php echo $ps; ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="season-badge empty">暂无记录</span>
                    <?php endif; ?>
                </div>
            </details>

            <details class="data-panel">
                <summary class="section-title section-header" style="border-left-color: var(--danger-color);">
                    🔥 匹配历史峰值
                    <span class="section-count-badge">已收录 <?php echo $resultData['progress']; ?>%</span>
                </summary>
            <?php if (!empty($resultData['highest_kd'])): 
                $highestValidCount = count($resultData['highest_kd']);
            ?>
                <div class="mode-grid grid-count-<?php echo $highestValidCount; ?>">
                    <?php foreach ($resultData['game_modes'] as $modeKey => $modeName): 
                        if (isset($resultData['highest_kd'][$modeKey])): $highest = $resultData['highest_kd'][$modeKey];
                    ?>
                        <div class="mode-card highest-season">
                            <div class="mode-header">
                                <div class="mode-name"><?php echo $modeName; ?></div>
                                <div class="mode-kd">平均淘汰: <?php echo $highest['kpm']; ?> <span style="opacity:0.5;margin:0 4px;">|</span> K/D: <?php echo $highest['kd']; ?></div>
                            </div>
                            <div class="mode-stats"><?php renderStatsHtml($highest); ?></div>
                            <div class="season-info">数据来源: <?php echo $highest['season_name'] . ' (' . $highest['season_month'] . ')'; ?></div>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
            <?php else: ?>
                <div style="padding: 24px; text-align: center; color: var(--text-secondary); background: var(--light-bg); border-radius: 8px; border: 1px solid var(--border-color);">暂无历史对局峰值记录。</div>
            <?php endif; ?>
            </details>

            <?php if (!empty($missingScanQueue)): ?>
            <div id="syncPanel" class="sync-panel" style="display: none;">
                <div class="sync-spinner"></div>
                <div class="sync-content">
                    <strong>📡 正在后台多线程追溯历史接口...</strong>
                    <span>发现该账号有 <b><span id="syncLeft"><?php echo count($missingScanQueue); ?></span></b> 个匹配/竞技历史节点未被归档。</span><br>
                    <span style="color:var(--text-secondary); font-size:12px;">已启动并发补全机制，请等待刷新。</span>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">📅 PUBG 官方数据直连</div>

    <div id="historyModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3>历史记录</h3>
                <span class="modal-close" id="closeHistoryModal">&times;</span>
            </div>
            <div class="history-tabs">
                <div class="history-tab-list">
                    <button class="ht-tab active" data-target="history-list">历史</button>
                    <button class="ht-tab" data-target="fav-list">收藏</button>
                </div>
                <label id="favFilterToggle" class="history-filter-toggle">
                    <input type="checkbox" id="onlyChangedCheck" class="history-filter-checkbox"> 仅显示有变化的玩家
                </label>
            </div>
            <div class="history-body" id="historyScrollContainer">
                <div id="history-list" class="ht-pane active"></div>
                <div id="fav-list" class="ht-pane"></div>
            </div>
            <div class="modal-footer" id="modalFooterCtrl">
                <div id="footerDefault" class="modal-footer-row modal-footer-default">
                    <button class="modal-btn btn-warning-outline" id="batchQueryBtn">批量查询</button>
                    <button class="modal-btn btn-danger" id="enterEditModeBtn">删除历史</button>
                </div>
                <div id="footerEdit" class="modal-footer-row modal-footer-edit">
                    <button class="modal-btn btn-outline" id="selectAllBtn">全选</button>
                    <div class="modal-footer-actions">
                        <button class="modal-btn btn-outline" id="cancelSelectBtn">取消</button>
                        <button class="modal-btn btn-danger" id="deleteSelectedBtn">删除选中</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script charset="UTF-8">
        window.PUBG_APP_CONTEXT = {
            hasData:         <?php echo isset($resultData) ? 'true' : 'false'; ?>,
            apiKeyCount:     <?php echo count($apiKeys); ?>,
            apiKeyRpm:       <?php echo PUBG_KEY_RPM; ?>,
            <?php if (isset($resultData)): ?>
            accountId:       <?php echo json_encode($resultData['account_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            shard:           <?php echo json_encode($selectedShard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            name:            <?php echo json_encode($resultData['name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            level:           <?php echo json_encode((string)$resultData['level'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            tier:            <?php echo json_encode((string)$resultData['tier'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            rawBanStatus:    <?php echo json_encode(trim($resultData['ban_status']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            recentMatchIds:  <?php echo empty($resultData['recent_match_ids']) ? '[]' : json_encode($resultData['recent_match_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            missingQueue:    <?php echo empty($missingScanQueue) ? '[]' : json_encode($missingScanQueue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            seasonConfigMap: <?php echo json_encode($seasonConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            allSeasonIds:    <?php echo json_encode(array_values(array_reverse($seasonIds)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            <?php endif; ?>
        };
    </script>
    <script charset="UTF-8">
        (() => {
            function bootSteamVacCheck() {
                const panel = document.getElementById('steamVacCheck');
                if (!panel || window.__PUBG_STEAM_VAC_STARTED__) return;
                window.__PUBG_STEAM_VAC_STARTED__ = true;

                const button = document.getElementById('steamVacRefreshBtn');
                const resultEl = document.getElementById('steamVacResult');
                const metaEl = document.getElementById('steamVacMeta');
                const nextEl = document.getElementById('steamVacNextRefresh');
                const accountId = panel.dataset.accountId || (window.PUBG_APP_CONTEXT || {}).accountId || '';
                let autoTimer = null;

                function durationText(seconds) {
                    const safeSeconds = Math.max(0, Math.floor(Number(seconds || 0)));
                    return `${String(Math.floor(safeSeconds / 60)).padStart(2, '0')}m${String(safeSeconds % 60).padStart(2, '0')}s`;
                }

                function steamVacUnlockLabel(data) {
                    if (!data || !data.temporary_detected_at) return '';
                    const label = String(data.unlock_label || '').trim();
                    return label && !/^\d+(?:\.\d+)?$/.test(label) ? label : '';
                }

                function renderSteamVacResultText(text, showAccuracyHint = false) {
                    resultEl.textContent = text;
                    if (!showAccuracyHint) return;

                    const hint = document.createElement('span');
                    hint.className = 'steam-vac-hint';
                    hint.tabIndex = 0;
                    hint.title = '解禁时间有5分钟误差，可点击上方刷新按钮获取接口实时数据';
                    hint.setAttribute('aria-label', hint.title);
                    hint.textContent = '!';
                    resultEl.appendChild(hint);
                }

                function checkedText(timestamp) {
                    const value = Number(timestamp || 0) * 1000;
                    if (!value) return '';
                    const date = new Date(value);
                    return `${String(date.getMinutes()).padStart(2, '0')}分${String(date.getSeconds()).padStart(2, '0')}秒`;
                }

                function renderCountdown() {
                    if (!nextEl || !panel.dataset.nextRefreshAt) return;
                    const left = Number(panel.dataset.nextRefreshAt) - Math.floor(Date.now() / 1000);
                    nextEl.textContent = durationText(left);
                }

                function scheduleNext(data) {
                    const checkedAt = Number(data.checked_at || Math.floor(Date.now() / 1000));
                    const interval = Math.max(180, Number(data.poll_seconds || 180));
                    const nextRefreshAt = checkedAt + interval;
                    panel.dataset.nextRefreshAt = String(nextRefreshAt);
                    if (autoTimer) clearTimeout(autoTimer);
                    autoTimer = setTimeout(() => refreshSteamVac(false), Math.max(1000, (nextRefreshAt - Math.floor(Date.now() / 1000)) * 1000));
                    renderCountdown();
                }

                function renderStatus(data) {
                    const state = data.vac_state || data.status || 'unknown';
                    const unlockLabel = steamVacUnlockLabel(data);
                    resultEl.dataset.state = state;
                    if (state === 'temporary' && unlockLabel) {
                        renderSteamVacResultText(`临时封禁 · ${unlockLabel}解禁`, true);
                    } else {
                        renderSteamVacResultText(data.label || '无法确认 Steam VAC 状态');
                    }

                    const checked = data.checked_at ? `校验时间 ${checkedText(data.checked_at)}` : '尚未校验';
                    const sourceType = data.from_cache ? '缓存' : '实时';
                    const latency = data.latency_ms ? ` · 接口耗时 ${data.latency_ms}ms` : '';
                    metaEl.textContent = `${checked} · ${sourceType}${latency}`;

                    scheduleNext(data);
                }

                async function refreshSteamVac(force) {
                    if (!accountId || !resultEl || !metaEl) {
                        panel.hidden = true;
                        return;
                    }
                    if (button) button.disabled = true;
                    resultEl.dataset.state = 'loading';
                    resultEl.textContent = force ? '正在手动刷新 Steam VAC接口...' : '正在校验 Steam VAC接口...';
                    try {
                        const response = await fetch(`core/engine.php?action=steam_vac_status&account_id=${encodeURIComponent(accountId)}&force=${force ? '1' : '0'}`, { cache: 'no-store' });
                        renderStatus(await response.json());
                    } catch (error) {
                        renderStatus({
                            status: 'error',
                            vac_state: 'error',
                            label: 'Steam 客服校验失败',
                            checked_at: Math.floor(Date.now() / 1000),
                            poll_seconds: 180
                        });
                    } finally {
                        if (button) button.disabled = false;
                    }
                }

                if (button) button.addEventListener('click', () => refreshSteamVac(true));
                setInterval(renderCountdown, 1000);
                refreshSteamVac(false);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bootSteamVacCheck, { once: true });
            } else {
                bootSteamVacCheck();
            }
        })();
    </script>
    <script src="assets/js/app.js?v=40.6" charset="UTF-8"></script>
</body>
</html>
