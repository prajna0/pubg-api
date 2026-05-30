// ==UserScript==
// @name         Steam VAC Cookie 配置助手
// @namespace    pubg-vac-cookie-helper
// @version      1.0.0
// @description  手动粘贴 Steam Cookie 后，一键生成 steam_help.php 配置片段。不自动读取 Cookie，不联网上传。
// @match        https://help.steampowered.com/zh-cn/wizard/VacBans*
// @match        https://help.steampowered.com/*/wizard/VacBans*
// @grant        GM_setClipboard
// ==/UserScript==

(function () {
    'use strict';

    const css = `
        #vacCookieHelper {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 999999;
            width: 380px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #e5f2ff;
        }
        #vacCookieHelper * { box-sizing: border-box; }
        .vch-card {
            border: 1px solid rgba(96, 165, 250, .34);
            border-radius: 12px;
            background: rgba(8, 14, 26, .96);
            box-shadow: 0 18px 52px rgba(0, 0, 0, .48);
            overflow: hidden;
        }
        .vch-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 14px 10px;
            border-bottom: 1px solid rgba(148, 163, 184, .14);
        }
        .vch-title {
            font-size: 15px;
            font-weight: 850;
            color: #7dd3fc;
        }
        .vch-sub {
            margin-top: 3px;
            font-size: 12px;
            color: #94a3b8;
        }
        .vch-close {
            width: 28px;
            height: 28px;
            border: 1px solid rgba(148, 163, 184, .24);
            border-radius: 8px;
            background: transparent;
            color: #cbd5e1;
            cursor: pointer;
            font-size: 18px;
        }
        .vch-body { padding: 14px; }
        .vch-label {
            display: block;
            margin: 0 0 6px;
            font-size: 12px;
            font-weight: 750;
            color: #cbd5e1;
        }
        .vch-input,
        .vch-textarea {
            width: 100%;
            border: 1px solid rgba(148, 163, 184, .26);
            border-radius: 8px;
            background: rgba(15, 23, 42, .84);
            color: #e5f2ff;
            outline: none;
            font-size: 12px;
        }
        .vch-input {
            height: 34px;
            padding: 0 10px;
            margin-bottom: 10px;
        }
        .vch-textarea {
            height: 88px;
            padding: 10px;
            resize: vertical;
            line-height: 1.45;
            margin-bottom: 10px;
        }
        .vch-input:focus,
        .vch-textarea:focus {
            border-color: rgba(125, 211, 252, .72);
        }
        .vch-preview {
            min-height: 74px;
            max-height: 130px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-all;
            padding: 10px;
            border: 1px solid rgba(148, 163, 184, .18);
            border-radius: 8px;
            background: rgba(2, 6, 23, .58);
            color: #bae6fd;
            font-size: 12px;
            line-height: 1.45;
        }
        .vch-status {
            min-height: 18px;
            margin: 8px 0 10px;
            font-size: 12px;
            color: #fbbf24;
        }
        .vch-status.ok { color: #34d399; }
        .vch-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .vch-btn {
            height: 34px;
            border: 1px solid rgba(125, 211, 252, .36);
            border-radius: 8px;
            background: rgba(14, 165, 233, .12);
            color: #7dd3fc;
            cursor: pointer;
            font-size: 12px;
            font-weight: 850;
        }
        .vch-btn:hover {
            background: rgba(14, 165, 233, .22);
        }
    `;

    const html = `
        <div class="vch-card">
            <div class="vch-head">
                <div>
                    <div class="vch-title">Steam VAC Cookie 配置助手</div>
                    <div class="vch-sub">本地处理，不读取 Cookie，不上传</div>
                </div>
                <button class="vch-close" title="关闭">×</button>
            </div>
            <div class="vch-body">
                <label class="vch-label">PUBG accountId</label>
                <input class="vch-input vch-account" placeholder="account.xxxxxxxxxxxxxxxxx">

                <label class="vch-label">粘贴完整 Cookie</label>
                <textarea class="vch-textarea vch-cookie" placeholder="粘贴从浏览器复制的 Cookie，例如：steamLoginSecure=...; sessionid=..."></textarea>

                <div class="vch-status">等待粘贴 Cookie</div>

                <label class="vch-label">配置片段预览</label>
                <div class="vch-preview"></div>

                <div class="vch-actions">
                    <button class="vch-btn vch-copy-cookie">复制精简 Cookie</button>
                    <button class="vch-btn vch-copy-config">复制配置片段</button>
                </div>
            </div>
        </div>
    `;

    const style = document.createElement('style');
    style.textContent = css;
    document.documentElement.appendChild(style);

    const root = document.createElement('div');
    root.id = 'vacCookieHelper';
    root.innerHTML = html;
    document.body.appendChild(root);

    const accountInput = root.querySelector('.vch-account');
    const cookieInput = root.querySelector('.vch-cookie');
    const status = root.querySelector('.vch-status');
    const preview = root.querySelector('.vch-preview');

    function setStatus(text, ok = false) {
        status.textContent = text;
        status.classList.toggle('ok', ok);
    }

    function parseCookie() {
        const raw = cookieInput.value.trim();
        const login = raw.match(/(?:^|;\s*)steamLoginSecure=([^;]+)/i);
        const session = raw.match(/(?:^|;\s*)sessionid=([^;]+)/i);

        if (!raw) {
            return { ok: false, message: '等待粘贴 Cookie' };
        }

        if (!login || !session) {
            return { ok: false, message: '缺少 steamLoginSecure 或 sessionid' };
        }

        const cookie = `steamLoginSecure=${login[1]}; sessionid=${session[1]}`;
        return { ok: true, cookie };
    }

    function buildConfig() {
        const parsed = parseCookie();
        const accountId = accountInput.value.trim();

        if (!parsed.ok) {
            preview.textContent = '';
            setStatus(parsed.message);
            return null;
        }

        if (!/^account\.[A-Za-z0-9_-]+$/.test(accountId)) {
            preview.textContent = '';
            setStatus('请先填写正确的 PUBG accountId');
            return null;
        }

        const safeCookie = parsed.cookie.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        const snippet = `'${accountId}' => [
    'cookie' => '${safeCookie}',
],`;

        preview.textContent = snippet;
        setStatus('已生成配置片段', true);

        return { cookie: parsed.cookie, snippet };
    }

    function refreshPreview() {
        const parsed = parseCookie();

        if (!parsed.ok) {
            preview.textContent = '';
            setStatus(parsed.message);
            return;
        }

        if (!accountInput.value.trim()) {
            preview.textContent = parsed.cookie;
            setStatus('Cookie 已识别，填写 accountId 后可生成配置片段', true);
            return;
        }

        buildConfig();
    }

    accountInput.addEventListener('input', refreshPreview);
    cookieInput.addEventListener('input', refreshPreview);

    root.querySelector('.vch-copy-cookie').addEventListener('click', () => {
        const parsed = parseCookie();
        if (!parsed.ok) {
            setStatus(parsed.message);
            return;
        }
        GM_setClipboard(parsed.cookie, 'text');
        setStatus('已复制精简 Cookie', true);
    });

    root.querySelector('.vch-copy-config').addEventListener('click', () => {
        const built = buildConfig();
        if (!built) return;
        GM_setClipboard(built.snippet, 'text');
        setStatus('已复制配置片段', true);
    });

    root.querySelector('.vch-close').addEventListener('click', () => {
        root.remove();
    });
})();
