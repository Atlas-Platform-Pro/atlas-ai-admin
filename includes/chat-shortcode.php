<?php
/**
 * Atlas Explorer Chat — Shortcode & AJAX handler
 * Shortcode: [atlas_chat]
 *
 * Attributes:
 *   instruction_set - which system instruction to use (default: 'default')
 *   height          - chat height in px (default: 600)
 *   welcome         - welcome message (default: 'Szia! Miben segithetek?')
 *   endpoint        - n8n webhook URL override
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================
// 1. Configuration
// ============================================

if (!defined('ATLAS_CHAT_ENDPOINT')) {
    define('ATLAS_CHAT_ENDPOINT', 'https://n8n.jovokepzo.hu/webhook/claude-chat');
}

// ============================================
// 2. AJAX Handler (proxy to n8n)
// ============================================

add_action('wp_ajax_atlas_chat', 'atlas_chat_handler');
add_action('wp_ajax_nopriv_atlas_chat', 'atlas_chat_handler');

function atlas_chat_handler() {
    check_ajax_referer('atlas_chat_nonce', '_ajax_nonce');

    $ip         = $_SERVER['REMOTE_ADDR'];
    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    $message    = wp_unslash($_POST['message'] ?? '');
    $instruction_set = sanitize_text_field($_POST['instruction_set'] ?? 'default');
    $endpoint   = esc_url_raw($_POST['endpoint'] ?? ATLAS_CHAT_ENDPOINT);

    if (empty($message)) {
        wp_send_json_error(array('message' => 'No message provided.'), 400);
    }

    // Rate limiting: 3 responses per session per hour (skip for admins)
    $is_admin = current_user_can('manage_options');
    $rl_key = 'atlas_chat_rl_' . md5($ip . '_' . $session_id);
    $count  = get_transient($rl_key);

    if (!$is_admin && $count !== false && (int) $count >= 3) {
        wp_send_json_error(array(
            'message'   => 'limit_reached',
            'remaining' => 0,
        ), 429);
    }

    // Call n8n
    $response = wp_remote_post($endpoint, array(
        'timeout' => 120,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'session_id'      => $session_id,
            'message'         => $message,
            'instruction_set' => $instruction_set,
        )),
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Connection error: ' . $response->get_error_message()), 502);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        wp_send_json_error(array('message' => $body['message'] ?? 'Backend error.'), $code);
    }

    // Increment counter only on successful response (skip for admins)
    if (!$is_admin) {
        $new_count = ($count === false) ? 1 : (int) $count + 1;
        set_transient($rl_key, $new_count, HOUR_IN_SECONDS);
        $body['remaining'] = 3 - $new_count;
    } else {
        $body['remaining'] = -1; // unlimited
    }

    wp_send_json_success($body);
}

// ============================================
// 3. Shortcode
// ============================================

add_shortcode('atlas_chat', 'atlas_chat_shortcode');

function atlas_chat_shortcode($atts) {
    $a = shortcode_atts(array(
        'endpoint'        => ATLAS_CHAT_ENDPOINT,
        'instruction_set' => 'default',
        'height'          => '600',
        'welcome'         => 'Szia! Miben segithetek?',
    ), $atts);

    $nonce   = wp_create_nonce('atlas_chat_nonce');
    $ajaxUrl = admin_url('admin-ajax.php');
    $height  = intval($a['height']);
    $welcome = esc_html($a['welcome']);
    $endpoint = esc_url($a['endpoint']);
    $instructionSet = esc_attr($a['instruction_set']);

    ob_start();
    ?>

<!-- Atlas Explorer Chat v<?php echo esc_html(ATLAS_AI_ADMIN_VERSION); ?> -->
<style>
/* ── Atlas Chat — Claude palette ── */
.ac-wrap {
    --ac-accent: #D97757;
    --ac-accent-hover: #C4683E;
    --ac-accent-light: rgba(217,119,87,.10);
    --ac-bg: #FAF9F7;
    --ac-surface: #FFFFFF;
    --ac-user-bg: #F5F0EA;
    --ac-text: #1A1915;
    --ac-text-muted: #5A5549;
    --ac-border: #E8DDD3;
    --ac-code-bg: #F5F3EF;
    --ac-radius: 16px;
    --ac-radius-sm: 10px;
    --ac-shadow: 0 2px 12px rgba(0,0,0,.06);
    --ac-transition: .2s cubic-bezier(.4,0,.2,1);
}

.ac-wrap {
    max-width: 800px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    height: <?php echo $height; ?>px;
    max-height: 85vh;
    background: var(--ac-bg);
    border: 1px solid var(--ac-border);
    border-radius: var(--ac-radius);
    overflow: hidden;
    font-family: 'Söhne', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 15px;
    line-height: 1.6;
    color: var(--ac-text);
    box-shadow: var(--ac-shadow);
    position: relative;
}

/* ── Header ── */
.ac-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: var(--ac-surface);
    border-bottom: 1px solid var(--ac-border);
    flex-shrink: 0;
}

.ac-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ac-logo {
    width: 32px;
    height: 32px;
    border-radius: var(--ac-radius-sm);
    background: var(--ac-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.ac-logo svg {
    width: 18px;
    height: 18px;
}

.ac-header-info {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.ac-header-title {
    font-weight: 600;
    font-size: 15px;
    color: var(--ac-text);
}

.ac-header-sub {
    font-size: 12px;
    color: var(--ac-text-muted);
}

.ac-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ac-btn-icon {
    width: 34px;
    height: 34px;
    border-radius: var(--ac-radius-sm);
    border: 1px solid var(--ac-border);
    background: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--ac-transition);
    color: var(--ac-text-muted);
    padding: 0;
}

.ac-btn-icon:hover {
    background: var(--ac-user-bg);
    border-color: var(--ac-text-muted);
    color: var(--ac-text);
}

.ac-btn-icon svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

/* ── Remaining counter ── */
.ac-remaining {
    font-size: 11px;
    color: var(--ac-text-muted);
    background: var(--ac-user-bg);
    padding: 3px 10px;
    border-radius: 12px;
    white-space: nowrap;
}

.ac-remaining-warn {
    color: #B5573A;
    background: rgba(217,119,87,.12);
    font-weight: 600;
}

/* ── Messages ── */
.ac-messages {
    flex: 1;
    overflow-y: auto;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    scroll-behavior: smooth;
}

.ac-messages::-webkit-scrollbar { width: 4px; }
.ac-messages::-webkit-scrollbar-track { background: transparent; }
.ac-messages::-webkit-scrollbar-thumb {
    background: var(--ac-border);
    border-radius: 2px;
}

/* ── Message ── */
.ac-msg {
    display: flex;
    gap: 10px;
    animation: ac-fadein .3s ease;
    max-width: 100%;
}

.ac-msg-user { flex-direction: row-reverse; }

.ac-avatar {
    width: 30px;
    height: 30px;
    border-radius: var(--ac-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 2px;
}

.ac-avatar-ai {
    background: var(--ac-accent);
}

.ac-avatar-ai svg {
    width: 16px;
    height: 16px;
}

.ac-avatar-user {
    background: var(--ac-border);
}

.ac-avatar-user svg {
    width: 14px;
    height: 14px;
    stroke: var(--ac-text-muted);
    fill: none;
    stroke-width: 2;
}

.ac-bubble {
    max-width: 78%;
    padding: 10px 14px;
    border-radius: var(--ac-radius);
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.ac-msg-assistant .ac-bubble {
    background: var(--ac-surface);
    border: 1px solid var(--ac-border);
    border-top-left-radius: 4px;
}

.ac-msg-user .ac-bubble {
    background: var(--ac-accent);
    color: #fff;
    border-top-right-radius: 4px;
}

/* ── Markdown ── */
.ac-bubble p { margin: 0 0 8px; }
.ac-bubble p:last-child { margin-bottom: 0; }
.ac-bubble strong { font-weight: 600; }
.ac-bubble em { font-style: italic; }

.ac-bubble code {
    background: var(--ac-code-bg);
    padding: 2px 5px;
    border-radius: 4px;
    font-family: 'SF Mono', 'Fira Code', Consolas, monospace;
    font-size: 13px;
}

.ac-bubble pre {
    background: var(--ac-code-bg);
    padding: 12px;
    border-radius: var(--ac-radius-sm);
    overflow-x: auto;
    margin: 8px 0;
    border: 1px solid var(--ac-border);
}

.ac-bubble pre code {
    background: none;
    padding: 0;
    font-size: 13px;
}

.ac-bubble ul, .ac-bubble ol {
    margin: 6px 0;
    padding-left: 20px;
}

.ac-bubble li { margin: 3px 0; }

.ac-bubble a {
    color: var(--ac-accent);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.ac-bubble blockquote {
    border-left: 3px solid var(--ac-accent);
    padding-left: 12px;
    margin: 8px 0;
    color: var(--ac-text-muted);
}

.ac-bubble h1, .ac-bubble h2, .ac-bubble h3 {
    margin: 12px 0 4px;
    font-weight: 600;
}
.ac-bubble h1 { font-size: 18px; }
.ac-bubble h2 { font-size: 17px; }
.ac-bubble h3 { font-size: 16px; }

/* ── Typing ── */
.ac-typing {
    display: flex;
    gap: 4px;
    padding: 10px 14px;
    align-items: center;
}

.ac-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--ac-accent);
    opacity: .4;
    animation: ac-bounce 1.4s infinite;
}
.ac-dot:nth-child(2) { animation-delay: .15s; }
.ac-dot:nth-child(3) { animation-delay: .3s; }

/* ── Starters ── */
.ac-starters {
    padding: 0 20px 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.ac-starters.ac-hidden { display: none; }

.ac-starter {
    background: var(--ac-surface);
    border: 1px solid var(--ac-border);
    border-radius: 20px;
    padding: 7px 14px;
    font-size: 13px;
    color: var(--ac-text-muted);
    cursor: pointer;
    transition: all var(--ac-transition);
    font-family: inherit;
    line-height: 1.3;
}

.ac-starter:hover {
    background: var(--ac-accent);
    color: #fff;
    border-color: var(--ac-accent);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(217,119,87,.25);
}

/* ── Input ── */
.ac-input-area {
    padding: 12px 20px 16px;
    background: var(--ac-surface);
    border-top: 1px solid var(--ac-border);
    flex-shrink: 0;
}

.ac-input-wrap {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    background: var(--ac-bg);
    border: 2px solid var(--ac-border);
    border-radius: var(--ac-radius);
    padding: 4px 4px 4px 14px;
    transition: border-color var(--ac-transition), box-shadow var(--ac-transition);
}

.ac-input-wrap:focus-within {
    border-color: var(--ac-accent);
    box-shadow: 0 0 0 3px var(--ac-accent-light);
}

.ac-input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 15px;
    font-family: inherit;
    line-height: 1.5;
    resize: none;
    background: transparent;
    color: var(--ac-text);
    padding: 8px 0;
    min-height: 24px;
    max-height: 120px;
}

.ac-input::placeholder { color: #B8B3AD; }
.ac-input:disabled { opacity: .5; }

.ac-send {
    background: var(--ac-accent);
    border: none;
    width: 36px;
    height: 36px;
    border-radius: var(--ac-radius-sm);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--ac-transition);
    flex-shrink: 0;
    padding: 0;
}

.ac-send:hover:not(:disabled) {
    background: var(--ac-accent-hover);
    transform: scale(1.05);
}

.ac-send:disabled {
    opacity: .35;
    cursor: not-allowed;
    transform: none;
}

.ac-send svg {
    width: 18px;
    height: 18px;
}

/* ── Limit overlay ── */
.ac-limit-overlay {
    position: absolute;
    inset: 0;
    background: rgba(250,249,247,.92);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    animation: ac-fadein .3s ease;
}

.ac-limit-box {
    text-align: center;
    max-width: 320px;
    padding: 32px;
}

.ac-limit-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--ac-accent-light);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.ac-limit-icon svg {
    width: 24px;
    height: 24px;
    stroke: var(--ac-accent);
    fill: none;
    stroke-width: 2;
}

.ac-limit-title {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--ac-text);
}

.ac-limit-text {
    font-size: 14px;
    color: var(--ac-text-muted);
    line-height: 1.5;
    margin-bottom: 20px;
}

.ac-limit-btn {
    display: inline-block;
    background: var(--ac-accent);
    color: #fff;
    border: none;
    border-radius: var(--ac-radius-sm);
    padding: 10px 24px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--ac-transition);
    text-decoration: none;
    font-family: inherit;
}

.ac-limit-btn:hover {
    background: var(--ac-accent-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(217,119,87,.3);
}

/* ── Error ── */
.ac-error-bubble {
    color: #9B2C2C;
    font-size: 13px;
    padding: 8px 14px;
    background: #FFF5F5;
    border: 1px solid #FED7D7;
    border-radius: var(--ac-radius-sm);
}

/* ── Mobile close btn ── */
.ac-close-mobile {
    display: none;
}

/* ── Animations ── */
@keyframes ac-fadein {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes ac-bounce {
    0%, 60%, 100% { transform: translateY(0); opacity: .4; }
    30%           { transform: translateY(-6px); opacity: 1; }
}

/* ── Responsive ── */
@media (max-width: 768px) {
    .ac-wrap {
        position: fixed;
        inset: 0;
        z-index: 999999;
        border-radius: 0;
        border: none;
        height: 100%;
        max-height: 100%;
        box-shadow: none;
    }

    .ac-close-mobile {
        display: flex;
    }

    .ac-bubble {
        max-width: 88%;
        font-size: 14px;
    }

    .ac-starter {
        font-size: 12px;
        padding: 6px 11px;
    }

    .ac-header {
        padding: 10px 14px;
    }

    .ac-messages {
        padding: 16px 14px;
    }

    .ac-input-area {
        padding: 10px 14px 14px;
        /* iOS safe area */
        padding-bottom: max(14px, env(safe-area-inset-bottom));
    }
}
</style>

<div class="ac-wrap" id="atlasChat">
    <!-- Header -->
    <div class="ac-header">
        <div class="ac-header-left">
            <div class="ac-logo">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M15.673 2.328a1.262 1.262 0 0 0-2.408-.14L6.05 18.398a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863L15.673 2.328Z" fill="#fff"/>
                    <path d="M18.364 9.476a1.262 1.262 0 0 0-2.409-.14l-3.085 7.315a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863l.958-7.039Z" fill="#fff" opacity=".5"/>
                </svg>
            </div>
            <div class="ac-header-info">
                <span class="ac-header-title">Atlas Explorer</span>
                <span class="ac-header-sub">Powered by Claude</span>
            </div>
        </div>
        <div class="ac-header-actions">
            <span class="ac-remaining" id="acRemaining" title="Fennmarado valaszok ebben a munkamenetben"></span>
            <button class="ac-btn-icon" id="acNewChat" title="Uj beszelgetes">
                <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            </button>
            <button class="ac-btn-icon ac-close-mobile" id="acClose" title="Bezaras">
                <svg viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <!-- Messages -->
    <div class="ac-messages" id="acMessages">
        <div class="ac-msg ac-msg-assistant">
            <div class="ac-avatar ac-avatar-ai">
                <svg viewBox="0 0 24 24" fill="none"><path d="M15.673 2.328a1.262 1.262 0 0 0-2.408-.14L6.05 18.398a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863L15.673 2.328Z" fill="#fff"/><path d="M18.364 9.476a1.262 1.262 0 0 0-2.409-.14l-3.085 7.315a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863l.958-7.039Z" fill="#fff" opacity=".5"/></svg>
            </div>
            <div class="ac-bubble"><?php echo $welcome; ?></div>
        </div>
    </div>

    <!-- Starters -->
    <div class="ac-starters" id="acStarters">
        <button class="ac-starter" data-msg="From which integral level was this written, and what might the next-level response sound like?">From which integral level was this written?</button>
        <button class="ac-starter" data-msg="Soften this text to align with a Green relational culture.">Soften this text for Green culture</button>
        <button class="ac-starter" data-msg="Show how this problem would be seen from Orange, Green, and Teal perspectives.">Show Orange, Green, Teal perspectives</button>
        <button class="ac-starter" data-msg="Diagnose the following statements using Reinventing color logic.">Diagnose using Reinventing color logic</button>
    </div>

    <!-- Input -->
    <div class="ac-input-area">
        <div class="ac-input-wrap">
            <textarea class="ac-input" id="acInput" placeholder="Ird ide az uzeneted..." rows="1"></textarea>
            <button class="ac-send" id="acSend" disabled>
                <svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    /* ── Config ── */
    var AJAX_URL  = <?php echo wp_json_encode($ajaxUrl); ?>;
    var NONCE     = <?php echo wp_json_encode($nonce); ?>;
    var ENDPOINT  = <?php echo wp_json_encode($endpoint); ?>;
    var INSTR_SET = <?php echo wp_json_encode($instructionSet); ?>;
    var WELCOME   = <?php echo wp_json_encode($welcome); ?>;
    var MAX_FREE  = 3;

    /* ── State ── */
    var SESSION_KEY = 'atlas_chat_sid';
    var COUNT_KEY   = 'atlas_chat_count';
    var sessionId   = localStorage.getItem(SESSION_KEY);
    var remaining   = MAX_FREE;
    var isSending   = false;

    if (!sessionId) {
        sessionId = uuid();
        localStorage.setItem(SESSION_KEY, sessionId);
    }

    // Restore remaining from localStorage
    var stored = localStorage.getItem(COUNT_KEY);
    if (stored) {
        try {
            var parsed = JSON.parse(stored);
            if (parsed.sid === sessionId && Date.now() < parsed.expires) {
                remaining = Math.max(0, MAX_FREE - parsed.count);
            }
        } catch(e) {}
    }

    /* ── DOM ── */
    var wrap       = document.getElementById('atlasChat');
    var messagesEl = document.getElementById('acMessages');
    var inputEl    = document.getElementById('acInput');
    var sendBtn    = document.getElementById('acSend');
    var newChatBtn = document.getElementById('acNewChat');
    var closeBtn   = document.getElementById('acClose');
    var startersEl = document.getElementById('acStarters');
    var remainEl   = document.getElementById('acRemaining');

    updateRemaining();

    /* ── Events ── */

    sendBtn.addEventListener('click', send);

    inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });

    inputEl.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        sendBtn.disabled = !this.value.trim();
    });

    newChatBtn.addEventListener('click', newChat);

    closeBtn.addEventListener('click', function() {
        wrap.style.display = 'none';
    });

    document.querySelectorAll('.ac-starter').forEach(function(btn) {
        btn.addEventListener('click', function() {
            inputEl.value = this.getAttribute('data-msg');
            sendBtn.disabled = false;
            send();
        });
    });

    /* ── Core ── */

    function send() {
        var text = inputEl.value.trim();
        if (!text || isSending) return;

        if (remaining <= 0) {
            showLimitOverlay();
            return;
        }

        startersEl.classList.add('ac-hidden');
        addMessage(text, true);
        inputEl.value = '';
        inputEl.style.height = 'auto';
        sendBtn.disabled = true;

        isSending = true;
        inputEl.disabled = true;

        var typingEl = showTyping();

        var fd = new URLSearchParams();
        fd.append('action', 'atlas_chat');
        fd.append('_ajax_nonce', NONCE);
        fd.append('message', text);
        fd.append('session_id', sessionId);
        fd.append('instruction_set', INSTR_SET);
        fd.append('endpoint', ENDPOINT);

        fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            typingEl.remove();

            if (data.success && data.data) {
                var d = data.data;
                // Handle array-wrapped responses from n8n
                if (Array.isArray(d)) d = d[0] || {};
                var reply = d.response || d.output || d.text || '';
                if (reply) {
                    addMessage(reply, false);
                } else {
                    console.log('Atlas Explorer: empty response, full data:', JSON.stringify(data));
                    addError('Oops! The AI seems to be on a coffee break. Try again in a moment.');
                }

                // Update remaining
                if (typeof d.remaining === 'number' && d.remaining >= 0) {
                    remaining = d.remaining;
                } else {
                    remaining = Math.max(0, remaining - 1);
                }
                saveCount();
                updateRemaining();

                if (remaining <= 0) {
                    showLimitOverlay();
                }
            } else {
                var msg = (data.data && data.data.message) || '';
                if (msg === 'limit_reached') {
                    remaining = 0;
                    saveCount();
                    updateRemaining();
                    showLimitOverlay();
                } else {
                    addError('Well, that didn\'t work. Even AIs have bad days. ' + (msg ? '(' + msg + ')' : ''));
                }
            }
        })
        .catch(function(err) {
            typingEl.remove();
            addError('Houston, we have a connection problem. Check your internet and try again.');
            console.error('Atlas Explorer:', err);
        })
        .finally(function() {
            isSending = false;
            inputEl.disabled = false;
            sendBtn.disabled = !inputEl.value.trim();
            inputEl.focus();
        });
    }

    function newChat() {
        sessionId = uuid();
        localStorage.setItem(SESSION_KEY, sessionId);
        remaining = MAX_FREE;
        saveCount();
        updateRemaining();
        messagesEl.innerHTML = '';
        addMessage(WELCOME, false);
        startersEl.classList.remove('ac-hidden');
        removeLimitOverlay();
        inputEl.focus();
    }

    /* ── UI helpers ── */

    var AI_SVG = '<svg viewBox="0 0 24 24" fill="none"><path d="M15.673 2.328a1.262 1.262 0 0 0-2.408-.14L6.05 18.398a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863L15.673 2.328Z" fill="#fff"/><path d="M18.364 9.476a1.262 1.262 0 0 0-2.409-.14l-3.085 7.315a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863l.958-7.039Z" fill="#fff" opacity=".5"/></svg>';
    var USER_SVG = '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>';

    function addMessage(content, isUser) {
        var div = document.createElement('div');
        div.className = 'ac-msg ' + (isUser ? 'ac-msg-user' : 'ac-msg-assistant');

        var avatarClass = isUser ? 'ac-avatar-user' : 'ac-avatar-ai';
        var avatarSvg   = isUser ? USER_SVG : AI_SVG;
        var rendered    = isUser ? escapeHtml(content) : renderMarkdown(content);

        div.innerHTML =
            '<div class="ac-avatar ' + avatarClass + '">' + avatarSvg + '</div>' +
            '<div class="ac-bubble">' + rendered + '</div>';

        messagesEl.appendChild(div);
        scrollBottom();
    }

    function addError(msg) {
        var div = document.createElement('div');
        div.className = 'ac-msg ac-msg-assistant';
        div.innerHTML =
            '<div class="ac-avatar ac-avatar-ai">' + AI_SVG + '</div>' +
            '<div class="ac-bubble ac-error-bubble">' + escapeHtml(msg) + '</div>';
        messagesEl.appendChild(div);
        scrollBottom();
    }

    function showTyping() {
        var div = document.createElement('div');
        div.className = 'ac-msg ac-msg-assistant';
        div.innerHTML =
            '<div class="ac-avatar ac-avatar-ai">' + AI_SVG + '</div>' +
            '<div class="ac-typing"><div class="ac-dot"></div><div class="ac-dot"></div><div class="ac-dot"></div></div>';
        messagesEl.appendChild(div);
        scrollBottom();
        return div;
    }

    function showLimitOverlay() {
        if (document.getElementById('acLimitOverlay')) return;
        var ol = document.createElement('div');
        ol.className = 'ac-limit-overlay';
        ol.id = 'acLimitOverlay';
        ol.innerHTML =
            '<div class="ac-limit-box">' +
                '<div class="ac-limit-icon"><svg viewBox="0 0 24 24"><path d="M12 8v4M12 16h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg></div>' +
                '<div class="ac-limit-title">You\'ve reached the free limit</div>' +
                '<div class="ac-limit-text">Come back in an hour for 3 more questions, or sign in to Atlas for unlimited access.</div>' +
                '<a href="https://app.atlas-platform.pro" class="ac-limit-btn" target="_blank">Sign in to Atlas</a>' +
            '</div>';
        wrap.appendChild(ol);
    }

    function removeLimitOverlay() {
        var ol = document.getElementById('acLimitOverlay');
        if (ol) ol.remove();
    }

    function updateRemaining() {
        if (remaining <= 0) {
            remainEl.textContent = '0/' + MAX_FREE;
            remainEl.className = 'ac-remaining ac-remaining-warn';
        } else {
            remainEl.textContent = remaining + '/' + MAX_FREE;
            remainEl.className = 'ac-remaining' + (remaining === 1 ? ' ac-remaining-warn' : '');
        }
    }

    function saveCount() {
        localStorage.setItem(COUNT_KEY, JSON.stringify({
            sid: sessionId,
            count: MAX_FREE - remaining,
            expires: Date.now() + 3600000
        }));
    }

    function scrollBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    /* ── Markdown ── */

    function renderMarkdown(text) {
        if (!text) return '';

        var codeBlocks = [];
        text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, function(_, lang, code) {
            var i = codeBlocks.length;
            codeBlocks.push('<pre><code>' + escapeHtml(code.trim()) + '</code></pre>');
            return '\x00CB' + i + '\x00';
        });

        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

        var lines = text.split('\n');
        var html = [];
        var inList = false;
        var listType = '';

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];

            var cbMatch = line.match(/^\x00CB(\d+)\x00$/);
            if (cbMatch) {
                if (inList) { html.push('</' + listType + '>'); inList = false; }
                html.push(codeBlocks[parseInt(cbMatch[1])]);
                continue;
            }

            if (/^### (.+)/.test(line)) {
                if (inList) { html.push('</' + listType + '>'); inList = false; }
                html.push('<h3>' + inlineFmt(line.slice(4)) + '</h3>');
                continue;
            }
            if (/^## (.+)/.test(line)) {
                if (inList) { html.push('</' + listType + '>'); inList = false; }
                html.push('<h2>' + inlineFmt(line.slice(3)) + '</h2>');
                continue;
            }
            if (/^# (.+)/.test(line)) {
                if (inList) { html.push('</' + listType + '>'); inList = false; }
                html.push('<h1>' + inlineFmt(line.slice(2)) + '</h1>');
                continue;
            }

            if (/^> (.+)/.test(line)) {
                if (inList) { html.push('</' + listType + '>'); inList = false; }
                html.push('<blockquote>' + inlineFmt(line.slice(2)) + '</blockquote>');
                continue;
            }

            if (/^[\-\*] (.+)/.test(line)) {
                if (!inList || listType !== 'ul') {
                    if (inList) html.push('</' + listType + '>');
                    html.push('<ul>'); inList = true; listType = 'ul';
                }
                html.push('<li>' + inlineFmt(line.replace(/^[\-\*] /, '')) + '</li>');
                continue;
            }

            if (/^\d+\. (.+)/.test(line)) {
                if (!inList || listType !== 'ol') {
                    if (inList) html.push('</' + listType + '>');
                    html.push('<ol>'); inList = true; listType = 'ol';
                }
                html.push('<li>' + inlineFmt(line.replace(/^\d+\. /, '')) + '</li>');
                continue;
            }

            if (inList) { html.push('</' + listType + '>'); inList = false; }
            if (line.trim() === '') continue;
            html.push('<p>' + inlineFmt(line) + '</p>');
        }

        if (inList) html.push('</' + listType + '>');
        return html.join('\n');
    }

    function inlineFmt(t) {
        t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        t = t.replace(/__(.+?)__/g, '<strong>$1</strong>');
        t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
        t = t.replace(/_(.+?)_/g, '<em>$1</em>');
        t = t.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        return t;
    }

    /* ── Utilities ── */

    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML.replace(/\n/g, '<br>');
    }

    function uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

})();
</script>

    <?php
    return ob_get_clean();
}
