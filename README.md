# PUBG 匹配与竞技战绩查询引擎

一个面向个人部署的 PUBG 战绩查询工具，支持玩家基础信息、赛季数据、历史记录、Steam VAC 状态校验、宝塔计划任务自动刷新，以及本地油猴脚本辅助生成 Steam Cookie 配置片段。

> 本项目不会也不应该公开你的 PUBG API Key、Steam Cookie、Steam 登录态或计划任务 token。真实配置文件已在 `.gitignore` 中忽略，请只提交示例配置。

## 功能概览

- 查询 PUBG 官方 API 玩家数据，支持 Steam、Kakao、Xbox、PSN 平台。
- 支持多个 PUBG API Key 轮询调用，按 `1 个 Key = 10 RPM` 控制速率。
- 支持历史记录、收藏夹、批量刷新和封禁状态变化提示。
- 支持 Steam 客服 VAC 页面校验，用于降低 PUBG API 封禁状态延迟带来的误差。
- 支持宝塔计划任务每 5 分钟批量刷新 Steam VAC 缓存。
- 支持临时封禁首次发现时间记录，并在前端显示类似 `临时封禁 · 5月31日 19:01:31解禁`。
- 附带油猴脚本，用于手动粘贴 Cookie 后生成 `steam_help.php` 配置片段。

## 运行环境

推荐环境：

- Debian / Ubuntu / CentOS
- 宝塔面板
- Nginx 或 Apache
- PHP 7.4+，推荐 PHP 8.1+
- PHP 扩展：`curl`、`json`

项目是纯 PHP + 原生前端，不依赖 Node、Composer 或数据库。

## 目录说明

```text
.
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── config/
│   ├── pubg_keys.example.php
│   └── steam_help.example.php
├── core/
│   └── engine.php
├── userscripts/
│   └── steam-vac-cookie-helper.user.js
├── 404.html
├── index.php
└── README.md
```

运行后会自动生成 `cache/` 目录，用于保存 API 缓存、速率限制状态和 Steam VAC 校验缓存。`cache/` 不应提交到 GitHub。

## 安装步骤

1. 将项目上传到服务器网站目录。

2. 复制 PUBG API Key 示例配置：

```bash
cp config/pubg_keys.example.php config/pubg_keys.php
```

编辑 `config/pubg_keys.php`：

```php
<?php
return [
    '你的 PUBG API KEY 1',
    '你的 PUBG API KEY 2',
];
```

3. 如果需要 Steam VAC 校验，复制 Steam 配置：

```bash
cp config/steam_help.example.php config/steam_help.php
```

编辑 `config/steam_help.php`：

```php
<?php
return [
    'sessions' => [
        'account.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' => [
            'cookie' => 'steamLoginSecure=xxx; sessionid=xxx',
        ],
    ],
    'poll_seconds' => 180,
    'timeout' => 8,
    'cron_token' => '请改成至少24位的随机字符串',
];
```

4. 确保 PHP 进程可写缓存目录。如果目录不存在，程序会尝试自动创建：

```bash
mkdir -p cache
chmod -R 755 cache
```

## Nginx 安全配置

如果使用宝塔 Nginx，请在站点配置里禁止访问 `config/` 和 `cache/`：

```nginx
location ^~ /config/ {
    deny all;
}

location ^~ /cache/ {
    deny all;
}
```

Apache 可以使用项目中的 `.htaccess`，但 Nginx 不会读取 `.htaccess`，必须在宝塔站点配置里单独添加。

## 宝塔计划任务

Steam VAC 自动刷新建议使用宝塔计划任务。

任务类型选择：

```text
访问URL-GET
```

执行周期：

```text
每 5 分钟
```

URL 推荐使用服务器本机地址：

```text
http://127.0.0.1:3333/core/engine.php?action=steam_vac_cron&token=你的cron_token
```

不建议使用公网 IP 调用计划任务接口，因为如果没有 HTTPS，`cron_token` 会经过公网明文传输。

如果 `访问URL-GET` 超时，可以改成 Shell 脚本：

```bash
curl -fsS --max-time 280 "http://127.0.0.1:3333/core/engine.php?action=steam_vac_cron&token=你的cron_token"
```

## Steam VAC 校验逻辑

Steam VAC 校验只影响页面中的 Steam VAC 校验模块和历史记录中的 Steam VAC 状态展示，不会覆盖主查询区域的 PUBG API 原始状态。

状态规则：

- Steam 客服页显示 VAC 声誉良好：`未封禁`
- Steam 客服页出现 PUBG 且有解禁日期：`临时封禁`
- Steam 客服页出现 PUBG 但没有日期：`永久封禁`
- 没有绑定 Cookie 或 Cookie 失效：回退 PUBG API 状态

临时封禁首次发现时间：

- 宝塔计划任务或手动刷新第一次查到临时封禁时，会记录当前北京时间。
- 同一个账号、同一个解禁日期后续刷新不会覆盖首次发现时间。
- 前端显示示例：

```text
临时封禁 · 5月31日 19:01:31解禁
```

该时间来自“首次发现时间 + Steam 返回的月份和日期”，实际解禁时间可能存在约 5 分钟误差。

## 油猴脚本

油猴脚本位置：

```text
userscripts/steam-vac-cookie-helper.user.js
```

用途：

- 打开 Steam VAC 页面后显示一个本地工具面板。
- 手动粘贴浏览器复制到的 Cookie。
- 自动提取 `steamLoginSecure` 和 `sessionid`。
- 一键生成 `config/steam_help.php` 可用的配置片段。

脚本不会自动读取浏览器 Cookie，不会上传数据，也不会联网。

使用页面：

```text
https://help.steampowered.com/zh-cn/wizard/VacBans/
```

生成示例：

```php
'account.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' => [
    'cookie' => 'steamLoginSecure=xxx; sessionid=xxx',
],
```

## 安全注意事项

以下内容绝对不要提交到 GitHub：

- `config/pubg_keys.php`
- `config/steam_help.php`
- `cache/`
- Steam Cookie
- PUBG API Key
- 宝塔计划任务 `cron_token`

如果不小心泄露 Steam Cookie，请立刻退出 Steam 网页登录、撤销已授权设备或修改密码，让旧登录态失效。

如果不小心泄露 PUBG API Key，请到 PUBG Developer Portal 重新生成或吊销旧 Key。

## 常见问题

### 为什么 Steam VAC 校验需要 Cookie？

Steam 客服 VAC 页面需要登录态才能看到当前账号的 VAC 与游戏封禁详情。项目只保存你手动提供的 Cookie，不保存 Steam 账号密码。

### 为什么历史记录有时还是显示 PUBG API 状态？

如果该 accountId 没有绑定 Steam Cookie，或者 Steam Cookie 失效，历史记录会回退显示 PUBG API 状态。

### 为什么建议计划任务使用 127.0.0.1？

计划任务在服务器本机执行，使用 `127.0.0.1` 不经过公网，减少 token 泄露风险，也更稳定。

### Steam Cookie 多久失效？

Steam 官方没有公开固定有效期。Cookie 可能因为过期、退出登录、清理浏览器 Cookie、修改密码、Steam 风控或撤销设备授权而失效。失效后需要重新采集。

## 开源发布前检查

发布到 GitHub 前，请确认：

- `.gitignore` 已包含真实配置和缓存目录。
- 仓库中只有 `*.example.php`，没有真实 `pubg_keys.php` 或 `steam_help.php`。
- 没有任何 `steamLoginSecure=`、真实 `sessionid=`、真实 PUBG API Key。
- 没有把宝塔计划任务 token 写进 README 或代码。
