# PUBG 匹配与竞技战绩查询引擎

一个可自行部署的 PUBG 战绩查询工具，基于 PUBG 官方 API 与可选的 Steam VAC 客服页校验，提供玩家资料、等级进度、赛季数据、历史记录、收藏记录、封禁状态辅助校验等功能。

本仓库是公开仓库，不包含任何真实 PUBG API Key、Steam Cookie、Steam 登录凭据、宝塔计划任务 token 或运行缓存。部署时请使用示例配置文件复制出本地私有配置，并只在自己的服务器保存真实密钥。

## 功能特性

- 查询 PUBG 官方 API 玩家信息，支持 Steam、Kakao、Xbox、PSN 平台。
- 支持多个 PUBG API Key 轮询调用，按 `1 个 Key = 10 RPM` 控制调用速率。
- 展示玩家等级、升级经验条、满级总进度、加入时间、平台信息与常用统计数据。
- 展示当前赛季、生涯赛季、最高记录、竞技模式与普通匹配模式数据。
- 支持历史记录、收藏记录、批量刷新和封禁状态变化提示。
- 可选接入 Steam 客服 VAC 页面，用于辅助校验 PUBG API 可能存在延迟的封禁状态。
- 支持宝塔计划任务定时刷新 Steam VAC 缓存。
- 附带第一版油猴脚本，用于手动粘贴 Cookie 后生成 `steam_help.php` 配置片段。

## 技术栈

- PHP 7.4+，推荐 PHP 8.1+
- 原生 HTML / CSS / JavaScript
- 不依赖 Node.js、Composer、数据库
- 适合部署在宝塔面板、Nginx 或 Apache 环境

需要的 PHP 扩展：

```text
curl
json
```

## 目录结构

```text
.
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── config/
│   ├── .htaccess
│   ├── pubg_keys.example.php
│   └── steam_help.example.php
├── core/
│   └── engine.php
├── userscripts/
│   └── steam-vac-cookie-helper.user.js
├── .gitignore
├── .htaccess
├── 404.html
├── index.php
└── README.md
```

运行后程序会自动生成 `cache/` 目录，用于保存接口缓存、速率限制状态、历史数据和 Steam VAC 校验缓存。`cache/` 不应提交到 GitHub。

## 快速部署

1. 上传项目文件到网站目录。

2. 复制 PUBG API Key 示例配置：

```bash
cp config/pubg_keys.example.php config/pubg_keys.php
```

3. 编辑 `config/pubg_keys.php`：

```php
<?php
return [
    '你的 PUBG API Key 1',
    '你的 PUBG API Key 2',
];
```

4. 确认 PHP 进程可写缓存目录：

```bash
mkdir -p cache
chmod -R 755 cache
```

5. 浏览器访问 `index.php` 或站点根目录。

## PUBG API Key 配置

公开仓库只提供示例文件：

```text
config/pubg_keys.example.php
```

部署时复制为：

```text
config/pubg_keys.php
```

`config/pubg_keys.php` 已被 `.gitignore` 忽略，请不要提交到公开仓库。

多个 Key 会组成 Key 池。项目会按 PUBG API 的常见限制 `1 个 Key = 10 RPM` 进行轮询与限速，例如 20 个 Key 理论上可提供约 200 RPM 的请求能力。

## Steam VAC 校验

Steam VAC 校验是可选功能，用于辅助判断 PUBG API 封禁状态延迟。它不会覆盖主查询区域的 PUBG API 原始状态，只影响页面中的 Steam VAC 校验模块和历史记录中的辅助封禁展示。

复制示例配置：

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
    'cron_token' => '请改成足够长的随机字符串',
];
```

说明：

- `sessions` 的键是 PUBG `accountId`。
- `cookie` 只保存对应 Steam 账号的 `steamLoginSecure` 与 `sessionid`。
- `cron_token` 用于保护计划任务接口，必须使用足够长的随机字符串。
- `config/steam_help.php` 已被 `.gitignore` 忽略，请不要提交。

## Steam VAC 状态规则

- Steam 客服页显示 VAC 声誉良好：显示 `未封禁`。
- Steam 客服页出现 PUBG 且包含解禁日期：显示 `临时封禁`。
- Steam 客服页出现 PUBG 但没有解禁日期：显示 `永久封禁`。
- 未绑定 Cookie、Cookie 失效或接口失败：回退 PUBG API 状态。

临时封禁首次被计划任务或手动刷新发现时，会记录当前北京时间。后续同一账号、同一解禁日期不会覆盖首次发现时间。

前端示例：

```text
临时封禁 · 5月31日 19:01:31解禁
```

该时间由 Steam 返回的月份日期和首次发现时分秒补全，可能存在约 5 分钟误差。

## 宝塔计划任务

建议使用宝塔计划任务定时刷新 Steam VAC 缓存。

任务类型：

```text
访问URL-GET
```

执行周期：

```text
每 5 分钟
```

推荐 URL：

```text
http://127.0.0.1:3333/core/engine.php?action=steam_vac_cron&token=你的cron_token
```

如果使用 Shell 脚本：

```bash
curl -fsS --max-time 280 "http://127.0.0.1:3333/core/engine.php?action=steam_vac_cron&token=你的cron_token"
```

如果站点未启用 HTTPS，建议计划任务使用服务器本机地址 `127.0.0.1`，避免 token 经过公网明文传输。

## 油猴脚本

脚本位置：

```text
userscripts/steam-vac-cookie-helper.user.js
```

这是第一版公开脚本，不内置任何 PUBG accountId。使用时需要用户自行填写 PUBG `accountId`，并手动粘贴浏览器中复制到的 Cookie。

脚本能力：

- 在 Steam VAC 页面显示本地工具面板。
- 从手动粘贴的 Cookie 中提取 `steamLoginSecure` 与 `sessionid`。
- 一键复制精简 Cookie。
- 一键生成 `config/steam_help.php` 可用配置片段。

脚本不会自动读取浏览器 Cookie，不会上传数据，也不会向任何第三方发送请求。

适用页面：

```text
https://help.steampowered.com/zh-cn/wizard/VacBans/
```

生成示例：

```php
'account.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' => [
    'cookie' => 'steamLoginSecure=xxx; sessionid=xxx',
],
```

## Nginx 安全配置

如果使用 Nginx，请在站点配置中禁止直接访问私有配置与缓存目录：

```nginx
location ^~ /config/ {
    deny all;
}

location ^~ /cache/ {
    deny all;
}
```

Apache 可使用仓库内的 `.htaccess`。Nginx 不读取 `.htaccess`，必须在站点配置中单独设置。

## 安全说明

以下内容绝对不要提交到公开仓库：

- `config/pubg_keys.php`
- `config/steam_help.php`
- `cache/`
- PUBG API Key
- Steam Cookie
- Steam 账号密码
- 宝塔计划任务 `cron_token`

如果 Steam Cookie 不慎泄露，请立即退出 Steam 网页登录、撤销已授权设备或修改密码，使旧登录态失效。

如果 PUBG API Key 不慎泄露，请前往 PUBG Developer Portal 重新生成或吊销旧 Key。

## 常见问题

### 为什么需要多个 PUBG API Key？

PUBG API 对单个 Key 有速率限制。多个 Key 可以组成 Key 池，在合规范围内提高批量查询和历史追溯效率。

### Steam VAC 校验为什么需要 Cookie？

Steam 客服 VAC 页面需要登录态才能查看当前 Steam 账号的 VAC 与游戏封禁详情。项目只读取你手动提供的 Cookie，不保存 Steam 账号密码。

### 没有配置 Steam Cookie 是否还能使用？

可以。未配置 Steam Cookie 时，项目仍然以 PUBG API 返回的数据为准。

### 为什么公开仓库没有真实配置？

真实 Key、Cookie 和 token 都属于高敏感凭据，公开后可能导致账号风险、接口滥用或服务被攻击。仓库只保留示例配置。

## 发布前检查

公开发布前请确认：

- 仓库中只有 `*.example.php` 示例配置。
- 没有真实 `config/pubg_keys.php`。
- 没有真实 `config/steam_help.php`。
- 没有 `cache/` 运行缓存。
- 没有真实 `steamLoginSecure`、`sessionid`、PUBG API Key 或计划任务 token。
