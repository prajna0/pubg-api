<?php
/**
 * Steam 客服 VAC 页面私有配置示例。
 *
 * 部署时复制为 config/steam_help.php，并只在服务器端保存。
 * 不要把 Steam 用户名、密码、Cookie 或 cron_token 暴露到前端页面。
 */
return [
    'sessions' => [
        'account.YOUR_PUBG_ACCOUNT_ID' => [
            'cookie' => 'steamLoginSecure=YOUR_VALUE; sessionid=YOUR_VALUE',
        ],
    ],
    'poll_seconds' => 180,
    'timeout' => 8,
    'cron_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_TOKEN_32_CHARS_MIN',
];
