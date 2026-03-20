<?php
/**
 * Plugin Name: Atlas AI Admin
 * Plugin URI:  https://github.com/Atlas-Platform-Pro/atlas-ai-admin
 * Description: Admin panel for managing Atlas AI system instructions via n8n CRUD API.
 * Version:     1.2.0
 * Author:      Atlas Platform
 * Author URI:  https://atlas-platform.pro
 * License:     GPL-2.0+
 * Text Domain: atlas-ai-admin
 *
 * Requires: n8n "System Instructions CRUD" workflow active.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ATLAS_AI_ADMIN_VERSION', '1.2.0');
define('ATLAS_AI_ADMIN_FILE', __FILE__);

// ============================================
// 1. GitHub Auto-Updater
// ============================================

class Atlas_AI_GitHub_Updater {

    private $slug;
    private $plugin_file;
    private $github_repo;
    private $plugin_data;
    private $github_response;

    public function __construct($plugin_file, $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->slug = plugin_basename(dirname($plugin_file));
        $this->github_repo = $github_repo;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    private function get_plugin_data() {
        if (!$this->plugin_data) {
            $this->plugin_data = get_plugin_data($this->plugin_file);
        }
        return $this->plugin_data;
    }

    private function get_github_release() {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'Atlas-AI-Admin-Updater',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->github_response = false;
            return false;
        }

        $this->github_response = json_decode(wp_remote_retrieve_body($response));
        return $this->github_response;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $transient;
        }

        $github_version = ltrim($release->tag_name, 'v');
        $plugin_data = $this->get_plugin_data();
        $plugin_basename = plugin_basename($this->plugin_file);

        if (version_compare($github_version, $plugin_data['Version'], '>')) {
            $download_url = $release->zipball_url;

            $transient->response[$plugin_basename] = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $plugin_basename,
                'new_version' => $github_version,
                'url'         => $plugin_data['PluginURI'],
                'package'     => $download_url,
                'icons'       => array(),
                'banners'     => array(),
            );
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $result;
        }

        $plugin_data = $this->get_plugin_data();

        return (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => $this->slug,
            'version'       => ltrim($release->tag_name, 'v'),
            'author'        => $plugin_data['AuthorName'],
            'homepage'      => $plugin_data['PluginURI'],
            'download_link' => $release->zipball_url,
            'sections'      => array(
                'description' => $plugin_data['Description'],
                'changelog'   => nl2br($release->body ?? 'No changelog.'),
            ),
        );
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename($this->plugin_file)) {
            return $result;
        }

        // GitHub zip extracts to a folder like "User-Repo-hash", rename to our slug
        $plugin_dir = WP_PLUGIN_DIR . '/' . $this->slug;
        $wp_filesystem->move($result['destination'], $plugin_dir);
        $result['destination'] = $plugin_dir;

        activate_plugin(plugin_basename($this->plugin_file));

        return $result;
    }
}

// Initialize updater — change repo if needed
new Atlas_AI_GitHub_Updater(
    ATLAS_AI_ADMIN_FILE,
    'Atlas-Platform-Pro/atlas-ai-admin' // GitHub: owner/repo
);

// ============================================
// 2. Chat Shortcode
// ============================================

require_once plugin_dir_path(__FILE__) . 'includes/chat-shortcode.php';

// ============================================
// Configuration
// ============================================

if (!defined('ATLAS_INSTR_ENDPOINT')) {
    if (defined('ATLAS_CHAT_ENDPOINT')) {
        define('ATLAS_INSTR_ENDPOINT', str_replace('claude-chat', 'system-instructions', ATLAS_CHAT_ENDPOINT));
    } else {
        define('ATLAS_INSTR_ENDPOINT', 'https://n8n.jovokepzo.hu/webhook/system-instructions');
    }
}

// ============================================
// 3. Admin Menu
// ============================================

add_action('admin_menu', function () {
    add_menu_page(
        'Atlas AI Instructions',
        'Atlas AI',
        'manage_options',
        'atlas-instructions',
        'atlas_ai_admin_page',
        'dashicons-format-chat',
        80
    );
});

// ============================================
// 4. AJAX Handlers
// ============================================

add_action('wp_ajax_atlas_instr_list_sets', 'atlas_ai_ajax_list_sets');
add_action('wp_ajax_atlas_instr_list', 'atlas_ai_ajax_list');
add_action('wp_ajax_atlas_instr_create', 'atlas_ai_ajax_create');
add_action('wp_ajax_atlas_instr_set_active', 'atlas_ai_ajax_set_active');
add_action('wp_ajax_atlas_instr_get_active', 'atlas_ai_ajax_get_active');

function atlas_ai_check_auth() {
    check_ajax_referer('atlas_instr_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }
}

function atlas_ai_call_n8n($payload) {
    $response = wp_remote_post(ATLAS_INSTR_ENDPOINT, array(
        'timeout' => 30,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($payload),
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Connection error: ' . $response->get_error_message()), 502);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
        wp_send_json_error(array('message' => $data['message'] ?? 'Backend error (' . $code . ')'), $code);
    }

    wp_send_json_success($data);
}

function atlas_ai_ajax_list_sets() {
    atlas_ai_check_auth();
    atlas_ai_call_n8n(array('action' => 'list_sets'));
}

function atlas_ai_ajax_list() {
    atlas_ai_check_auth();
    $set = sanitize_text_field($_POST['instruction_set'] ?? 'default');
    atlas_ai_call_n8n(array('action' => 'list', 'instruction_set' => $set));
}

function atlas_ai_ajax_create() {
    atlas_ai_check_auth();
    $set     = sanitize_text_field($_POST['instruction_set'] ?? '');
    $content = wp_unslash($_POST['content'] ?? '');
    $active  = ($_POST['set_active'] ?? '0') === '1';

    if (empty($set) || empty($content)) {
        wp_send_json_error(array('message' => 'Instruction set and content are required.'), 400);
    }

    atlas_ai_call_n8n(array(
        'action'          => 'create',
        'instruction_set' => $set,
        'content'         => $content,
        'set_active'      => $active,
    ));
}

function atlas_ai_ajax_set_active() {
    atlas_ai_check_auth();
    $id = sanitize_text_field($_POST['id'] ?? '');
    if (empty($id)) {
        wp_send_json_error(array('message' => 'ID is required.'), 400);
    }
    atlas_ai_call_n8n(array('action' => 'set_active', 'id' => $id));
}

function atlas_ai_ajax_get_active() {
    atlas_ai_check_auth();
    $set = sanitize_text_field($_POST['instruction_set'] ?? 'default');
    atlas_ai_call_n8n(array('action' => 'get_active', 'instruction_set' => $set));
}

// ============================================
// 5. Admin Page
// ============================================

function atlas_ai_admin_page() {
    $nonce = wp_create_nonce('atlas_instr_nonce');
    $ajaxUrl = admin_url('admin-ajax.php');
    ?>
    <div class="wrap">
        <h1>Atlas AI — System Instructions
            <span style="font-size:12px;color:#646970;font-weight:normal;margin-left:10px;">
                v<?php echo esc_html(ATLAS_AI_ADMIN_VERSION); ?>
            </span>
        </h1>

        <style>
            .ai-admin-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-top: 15px;
            }
            .ai-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: 600;
            }
            .ai-badge-active {
                background: #d4edda;
                color: #155724;
            }
            .ai-badge-inactive {
                background: #f0f0f1;
                color: #50575e;
            }
            .ai-preview {
                color: #646970;
                font-size: 13px;
                max-width: 500px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .ai-editor textarea {
                width: 100%;
                min-height: 400px;
                font-family: 'SF Mono', 'Consolas', 'Courier New', monospace;
                font-size: 13px;
                line-height: 1.6;
                padding: 12px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                resize: vertical;
            }
            .ai-toolbar {
                display: flex;
                gap: 10px;
                align-items: center;
                margin-bottom: 15px;
                flex-wrap: wrap;
            }
            .ai-toolbar .button { margin: 0; }
            .ai-set-input {
                padding: 4px 8px;
                font-size: 14px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .ai-status {
                padding: 8px 12px;
                border-radius: 4px;
                margin-top: 10px;
                display: none;
            }
            .ai-status-ok { background: #d4edda; color: #155724; }
            .ai-status-err { background: #f8d7da; color: #721c24; }
            .ai-loading { opacity: 0.6; pointer-events: none; }
            #aiView { min-height: 200px; }
            .ai-shortcode-help {
                background: #f0f6fc;
                border: 1px solid #c5d9ed;
                border-radius: 4px;
                padding: 10px 14px;
                margin-bottom: 15px;
                font-size: 13px;
                line-height: 1.6;
            }
            .ai-shortcode-help code {
                background: #e8f0fe;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }

            /* Chat test modal */
            .ai-chat-overlay {
                position: fixed;
                inset: 0;
                z-index: 100000;
                background: rgba(0,0,0,.4);
                display: flex;
                align-items: center;
                justify-content: center;
                animation: ai-fadein .2s ease;
            }
            .ai-chat-modal {
                width: 520px;
                max-width: 95vw;
                height: 600px;
                max-height: 85vh;
                background: #FAF9F7;
                border-radius: 16px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                box-shadow: 0 8px 40px rgba(0,0,0,.15);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                color: #1A1915;
            }
            .ai-chat-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 16px;
                background: #fff;
                border-bottom: 1px solid #E8DDD3;
                flex-shrink: 0;
            }
            .ai-chat-header-left {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ai-chat-logo {
                width: 28px; height: 28px;
                background: #D97757;
                border-radius: 8px;
                display: flex; align-items: center; justify-content: center;
            }
            .ai-chat-logo svg { width: 14px; height: 14px; }
            .ai-chat-htitle { font-weight: 600; font-size: 14px; }
            .ai-chat-hsub { font-size: 11px; color: #5A5549; }
            .ai-chat-counter {
                font-size: 11px;
                color: #5A5549;
                background: #F5F0EA;
                padding: 2px 8px;
                border-radius: 10px;
            }
            .ai-chat-counter-warn { color: #B5573A; background: rgba(217,119,87,.12); font-weight: 600; }
            .ai-chat-close {
                width: 30px; height: 30px;
                border: 1px solid #E8DDD3;
                border-radius: 8px;
                background: transparent;
                cursor: pointer;
                display: flex; align-items: center; justify-content: center;
                color: #5A5549;
                font-size: 18px;
                line-height: 1;
                transition: .15s;
            }
            .ai-chat-close:hover { background: #F5F0EA; color: #1A1915; }
            .ai-chat-messages {
                flex: 1;
                overflow-y: auto;
                padding: 16px;
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .ai-chat-messages::-webkit-scrollbar { width: 4px; }
            .ai-chat-messages::-webkit-scrollbar-thumb { background: #E8DDD3; border-radius: 2px; }
            .ai-chat-msg {
                display: flex;
                gap: 8px;
                animation: ai-fadein .25s ease;
            }
            .ai-chat-msg-user { flex-direction: row-reverse; }
            .ai-chat-av {
                width: 26px; height: 26px;
                border-radius: 8px;
                display: flex; align-items: center; justify-content: center;
                flex-shrink: 0;
                margin-top: 2px;
            }
            .ai-chat-av-ai { background: #D97757; }
            .ai-chat-av-ai svg { width: 14px; height: 14px; }
            .ai-chat-av-user { background: #E8DDD3; }
            .ai-chat-av-user svg { width: 12px; height: 12px; stroke: #5A5549; fill: none; stroke-width: 2; }
            .ai-chat-bub {
                max-width: 80%;
                padding: 8px 12px;
                border-radius: 12px;
                word-wrap: break-word;
                line-height: 1.5;
            }
            .ai-chat-msg-assistant .ai-chat-bub {
                background: #fff;
                border: 1px solid #E8DDD3;
                border-top-left-radius: 4px;
            }
            .ai-chat-msg-user .ai-chat-bub {
                background: #D97757;
                color: #fff;
                border-top-right-radius: 4px;
            }
            .ai-chat-bub p { margin: 0 0 6px; }
            .ai-chat-bub p:last-child { margin: 0; }
            .ai-chat-bub strong { font-weight: 600; }
            .ai-chat-bub code { background: #F5F3EF; padding: 1px 4px; border-radius: 3px; font-size: 12px; }
            .ai-chat-bub pre { background: #F5F3EF; padding: 10px; border-radius: 8px; overflow-x: auto; margin: 6px 0; border: 1px solid #E8DDD3; }
            .ai-chat-bub pre code { background: none; padding: 0; }
            .ai-chat-bub ul, .ai-chat-bub ol { margin: 4px 0; padding-left: 18px; }
            .ai-chat-bub li { margin: 2px 0; }
            .ai-chat-bub a { color: #D97757; }
            .ai-chat-bub blockquote { border-left: 3px solid #D97757; padding-left: 10px; margin: 6px 0; color: #5A5549; }
            .ai-chat-typing { display: flex; gap: 4px; padding: 8px 12px; align-items: center; }
            .ai-chat-dot {
                width: 5px; height: 5px; border-radius: 50%;
                background: #D97757; opacity: .4;
                animation: ai-bounce 1.4s infinite;
            }
            .ai-chat-dot:nth-child(2) { animation-delay: .15s; }
            .ai-chat-dot:nth-child(3) { animation-delay: .3s; }
            .ai-chat-input-area {
                padding: 10px 16px 14px;
                background: #fff;
                border-top: 1px solid #E8DDD3;
                flex-shrink: 0;
            }
            .ai-chat-input-wrap {
                display: flex;
                align-items: flex-end;
                gap: 6px;
                background: #FAF9F7;
                border: 2px solid #E8DDD3;
                border-radius: 12px;
                padding: 3px 3px 3px 12px;
                transition: border-color .15s;
            }
            .ai-chat-input-wrap:focus-within { border-color: #D97757; box-shadow: 0 0 0 3px rgba(217,119,87,.1); }
            .ai-chat-input {
                flex: 1; border: none; outline: none;
                font-size: 14px; font-family: inherit;
                line-height: 1.5; resize: none;
                background: transparent; color: #1A1915;
                padding: 6px 0; min-height: 20px; max-height: 100px;
            }
            .ai-chat-input::placeholder { color: #B8B3AD; }
            .ai-chat-input:disabled { opacity: .5; }
            .ai-chat-send {
                background: #D97757; border: none;
                width: 32px; height: 32px;
                border-radius: 8px; cursor: pointer;
                display: flex; align-items: center; justify-content: center;
                transition: .15s; flex-shrink: 0; padding: 0;
            }
            .ai-chat-send:hover:not(:disabled) { background: #C4683E; }
            .ai-chat-send:disabled { opacity: .35; cursor: not-allowed; }
            .ai-chat-send svg { width: 16px; height: 16px; }
            .ai-chat-err { color: #9B2C2C; font-size: 12px; padding: 6px 12px; background: #FFF5F5; border: 1px solid #FED7D7; border-radius: 8px; }
            .ai-chat-limit {
                text-align: center;
                padding: 24px 16px;
                color: #5A5549;
                font-size: 13px;
                line-height: 1.5;
            }
            .ai-chat-limit strong { color: #D97757; }
            @keyframes ai-fadein { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes ai-bounce { 0%,60%,100% { transform: translateY(0); opacity: .4; } 30% { transform: translateY(-5px); opacity: 1; } }
        </style>

        <div id="aiStatus" class="ai-status"></div>
        <div class="ai-admin-card">
            <div id="aiView"></div>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        var AJAX = <?php echo wp_json_encode($ajaxUrl); ?>;
        var NONCE = <?php echo wp_json_encode($nonce); ?>;
        var viewEl = document.getElementById('aiView');
        var statusEl = document.getElementById('aiStatus');

        var currentSet = null;

        // ---- Navigation ----

        showSetsList();

        function showSetsList() {
            currentSet = null;
            viewEl.innerHTML =
                '<div class="ai-toolbar">' +
                    '<h2 style="margin:0">Instruction Sets</h2>' +
                    '<button class="button button-primary" id="btnNewSet">+ Új instruction set</button>' +
                '</div>' +
                '<p class="description">Válassz egy instruction set-et a verziók megtekintéséhez, vagy hozz létre újat.</p>' +
                '<div class="ai-shortcode-help">' +
                    '<strong>Beágyazás shortcode-dal:</strong> <code>[atlas_chat]</code> — alapértelmezett instruction set &nbsp;|&nbsp; ' +
                    '<code>[atlas_chat instruction_set="sales-agent"]</code> — egyedi instruction set' +
                '</div>' +
                '<table class="widefat striped" id="setsTable"><thead><tr>' +
                    '<th>Instruction Set</th><th>Műveletek</th>' +
                '</tr></thead><tbody id="setsBody"><tr><td colspan="2">Betöltés...</td></tr></tbody></table>';

            document.getElementById('btnNewSet').addEventListener('click', function() {
                showEditor('', '');
            });

            loadSetsList();
        }

        function loadSetsList() {
            ajax('atlas_instr_list_sets', {}, function(data) {
                var body = document.getElementById('setsBody');
                if (!data || data.length === 0) {
                    body.innerHTML = '<tr><td colspan="2">Nincs instruction set. Hozz létre egyet!</td></tr>';
                    return;
                }

                var html = '';
                data.forEach(function(row) {
                    var setName = row.instruction_set;
                    var activeVer = row.active_version ? 'v' + row.active_version : '—';
                    var verCount = row.version_count || 0;
                    html += '<tr>' +
                        '<td><strong><a href="#" class="ai-set-link" data-set="' + esc(setName) + '">' + esc(setName) + '</a></strong>' +
                            ' <span class="description">(' + verCount + ' verzió, aktív: ' + activeVer + ')</span></td>' +
                        '<td><button class="button ai-set-link" data-set="' + esc(setName) + '">Verziók</button></td>' +
                    '</tr>';
                });
                body.innerHTML = html;

                document.querySelectorAll('.ai-set-link').forEach(function(el) {
                    el.addEventListener('click', function(e) {
                        e.preventDefault();
                        showVersions(this.getAttribute('data-set'));
                    });
                });
            });
        }

        function showVersions(setName) {
            currentSet = setName;
            viewEl.innerHTML =
                '<div class="ai-toolbar">' +
                    '<button class="button" id="btnBack">← Vissza</button>' +
                    '<h2 style="margin:0">' + esc(setName) + '</h2>' +
                    '<button class="button button-primary" id="btnNewVer">+ Új verzió</button>' +
                '</div>' +
                '<table class="widefat striped"><thead><tr>' +
                    '<th>Verzió</th><th>Státusz</th><th>Létrehozva</th><th>Tartalom</th><th>Művelet</th>' +
                '</tr></thead><tbody id="versBody"><tr><td colspan="5">Betöltés...</td></tr></tbody></table>';

            document.getElementById('btnBack').addEventListener('click', showSetsList);
            document.getElementById('btnNewVer').addEventListener('click', function() {
                // Load active version's full content as starting point
                ajax('atlas_instr_get_active', { instruction_set: setName }, function(data) {
                    var active = Array.isArray(data) ? data[0] : data;
                    var content = (active && active.content) ? active.content : '';
                    showEditor(setName, content, null);
                });
            });

            loadVersions(setName);
        }

        // Cache for full content from list response
        var versionCache = {};

        function loadVersions(setName) {
            ajax('atlas_instr_list', { instruction_set: setName }, function(data) {
                var body = document.getElementById('versBody');
                if (!data || data.length === 0) {
                    body.innerHTML = '<tr><td colspan="5">Nincs verzió.</td></tr>';
                    return;
                }

                // Cache full content for each version
                versionCache = {};
                data.forEach(function(row) {
                    versionCache[row.id] = row.content || '';
                });

                var html = '';
                data.forEach(function(row) {
                    var active = row.is_active ? true : false;
                    var badge = active
                        ? '<span class="ai-badge ai-badge-active">Aktív</span>'
                        : '<span class="ai-badge ai-badge-inactive">Inaktív</span>';
                    var date = row.created_at ? new Date(row.created_at).toLocaleString('hu-HU') : '-';
                    var preview = esc(row.content_preview || row.content || '').substring(0, 150);
                    var activateBtn = active ? '' :
                        '<button class="button ai-activate-btn" data-id="' + esc(row.id) + '">Aktiválás</button> ';
                    var viewBtn = '<button class="button ai-view-btn" data-id="' + esc(row.id) + '" data-ver="' + (row.version || '?') + '">Megtekintés</button>';
                    var chatBtn = '<button class="button ai-chat-btn" data-set="' + esc(setName) + '" data-ver="' + (row.version || '?') + '" style="color:#D97757;border-color:#D97757;">Chat</button>';

                    html += '<tr>' +
                        '<td><strong>v' + (row.version || '?') + '</strong></td>' +
                        '<td>' + badge + '</td>' +
                        '<td>' + date + '</td>' +
                        '<td class="ai-preview">' + preview + '</td>' +
                        '<td>' + activateBtn + viewBtn + ' ' + chatBtn + '</td>' +
                    '</tr>';
                });

                body.innerHTML = html;

                document.querySelectorAll('.ai-activate-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.getAttribute('data-id');
                        if (confirm('Biztosan aktiválod ezt a verziót?')) {
                            ajax('atlas_instr_set_active', { id: id }, function() {
                                showStatus('Verzió aktiválva!', false);
                                loadVersions(setName);
                            });
                        }
                    });
                });

                document.querySelectorAll('.ai-view-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.getAttribute('data-id');
                        var ver = this.getAttribute('data-ver');
                        var content = versionCache[id] || '';
                        showEditor(setName, content, 'v' + ver);
                    });
                });

                document.querySelectorAll('.ai-chat-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var set = this.getAttribute('data-set');
                        var ver = this.getAttribute('data-ver');
                        openChatTest(set, ver);
                    });
                });
            });
        }

        function showEditor(setName, prefillContent, versionLabel) {
            var isNew = !setName;
            var title = isNew ? 'Új Instruction Set'
                : versionLabel ? esc(setName) + ' — ' + esc(versionLabel) + ' (új verzió készítése)'
                : esc(setName) + ' — Új verzió';
            viewEl.innerHTML =
                '<div class="ai-toolbar">' +
                    '<button class="button" id="btnEdBack">← Vissza</button>' +
                    '<h2 style="margin:0">' + title + '</h2>' +
                '</div>' +
                '<div class="ai-editor">' +
                    '<table class="form-table"><tbody>' +
                        '<tr><th><label for="edSet">Instruction Set neve</label></th>' +
                            '<td><input type="text" id="edSet" class="regular-text ai-set-input" value="' + esc(setName) + '"' +
                                (isNew ? '' : ' readonly') + ' placeholder="pl. default, sales-agent"></td></tr>' +
                        '<tr><th><label for="edContent">System Instruction</label></th>' +
                            '<td><textarea id="edContent" placeholder="Írd ide a system promptot...">' + esc(prefillContent) + '</textarea></td></tr>' +
                    '</tbody></table>' +
                    '<div class="ai-toolbar" style="margin-top:10px">' +
                        '<button class="button button-primary" id="btnSaveActive">Mentés és aktiválás</button>' +
                        '<button class="button" id="btnSaveDraft">Mentés piszkozatként</button>' +
                    '</div>' +
                '</div>';

            document.getElementById('btnEdBack').addEventListener('click', function() {
                if (currentSet) showVersions(currentSet);
                else showSetsList();
            });

            document.getElementById('btnSaveActive').addEventListener('click', function() { saveInstruction(true); });
            document.getElementById('btnSaveDraft').addEventListener('click', function() { saveInstruction(false); });
        }

        function saveInstruction(setActive) {
            var set = document.getElementById('edSet').value.trim();
            var content = document.getElementById('edContent').value.trim();

            if (!set || !content) {
                showStatus('Instruction set név és tartalom kötelező!', true);
                return;
            }

            currentSet = set;

            ajax('atlas_instr_create', {
                instruction_set: set,
                content: content,
                set_active: setActive ? '1' : '0'
            }, function() {
                showStatus('Verzió mentve!' + (setActive ? ' (aktív)' : ' (piszkozat)'), false);
                showVersions(set);
            });
        }

        // ---- AJAX helper ----

        function ajax(action, params, onSuccess) {
            viewEl.classList.add('ai-loading');

            var formData = new URLSearchParams();
            formData.append('action', action);
            formData.append('_ajax_nonce', NONCE);
            for (var k in params) {
                formData.append(k, params[k]);
            }

            fetch(AJAX, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                viewEl.classList.remove('ai-loading');
                if (resp.success) {
                    if (onSuccess) onSuccess(resp.data);
                } else {
                    var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
                    showStatus('Hiba: ' + msg, true);
                }
            })
            .catch(function(err) {
                viewEl.classList.remove('ai-loading');
                showStatus('Kapcsolódási hiba: ' + err.message, true);
            });
        }

        // ---- Utilities ----

        function showStatus(msg, isError) {
            statusEl.textContent = msg;
            statusEl.className = 'ai-status ' + (isError ? 'ai-status-err' : 'ai-status-ok');
            statusEl.style.display = 'block';
            setTimeout(function() { statusEl.style.display = 'none'; }, 5000);
        }

        function esc(str) {
            var d = document.createElement('div');
            d.textContent = str || '';
            return d.innerHTML;
        }

        // ---- Chat Test Modal ----

        var CHAT_NONCE = <?php echo wp_json_encode(wp_create_nonce('atlas_chat_nonce')); ?>;
        var CHAT_ENDPOINT = <?php echo wp_json_encode(defined('ATLAS_CHAT_ENDPOINT') ? ATLAS_CHAT_ENDPOINT : 'https://n8n.jovokepzo.hu/webhook/claude-chat'); ?>;
        var AI_ICON = '<svg viewBox="0 0 24 24" fill="none"><path d="M15.673 2.328a1.262 1.262 0 0 0-2.408-.14L6.05 18.398a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863L15.673 2.328Z" fill="#fff"/><path d="M18.364 9.476a1.262 1.262 0 0 0-2.409-.14l-3.085 7.315a.56.56 0 0 0 .534.727h2.802a1.26 1.26 0 0 0 1.2-.863l.958-7.039Z" fill="#fff" opacity=".5"/></svg>';
        var USER_ICON = '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>';

        function openChatTest(instrSet, version) {
            // Remove existing modal
            var old = document.getElementById('aiChatOverlay');
            if (old) old.remove();

            var chatState = {
                sessionId: chatUuid(),
                remaining: 3,
                sending: false
            };

            var overlay = document.createElement('div');
            overlay.className = 'ai-chat-overlay';
            overlay.id = 'aiChatOverlay';
            overlay.innerHTML =
                '<div class="ai-chat-modal">' +
                    '<div class="ai-chat-header">' +
                        '<div class="ai-chat-header-left">' +
                            '<div class="ai-chat-logo">' + AI_ICON + '</div>' +
                            '<div><div class="ai-chat-htitle">Chat teszt</div>' +
                            '<div class="ai-chat-hsub">' + esc(instrSet) + ' v' + esc(version) + '</div></div>' +
                        '</div>' +
                        '<div style="display:flex;align-items:center;gap:8px;">' +
                            '<span class="ai-chat-counter" id="aiChatCounter">3/3</span>' +
                            '<button class="ai-chat-close" id="aiChatClose">&times;</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="ai-chat-messages" id="aiChatMsgs">' +
                        '<div class="ai-chat-msg ai-chat-msg-assistant">' +
                            '<div class="ai-chat-av ai-chat-av-ai">' + AI_ICON + '</div>' +
                            '<div class="ai-chat-bub">Szia! Chat teszt mód — ' + esc(instrSet) + ' v' + esc(version) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="ai-chat-input-area">' +
                        '<div class="ai-chat-input-wrap">' +
                            '<textarea class="ai-chat-input" id="aiChatInput" placeholder="Ird ide az uzeneted..." rows="1"></textarea>' +
                            '<button class="ai-chat-send" id="aiChatSend" disabled>' +
                                '<svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(overlay);

            var msgsEl = document.getElementById('aiChatMsgs');
            var inputEl = document.getElementById('aiChatInput');
            var sendBtn = document.getElementById('aiChatSend');
            var counterEl = document.getElementById('aiChatCounter');

            // Close
            document.getElementById('aiChatClose').addEventListener('click', function() {
                overlay.remove();
            });
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) overlay.remove();
            });

            // Input
            inputEl.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
                sendBtn.disabled = !this.value.trim();
            });
            inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatSend();
                }
            });
            sendBtn.addEventListener('click', chatSend);
            inputEl.focus();

            function chatSend() {
                var text = inputEl.value.trim();
                if (!text || chatState.sending || chatState.remaining <= 0) return;

                chatAddMsg(text, true);
                inputEl.value = '';
                inputEl.style.height = 'auto';
                sendBtn.disabled = true;
                chatState.sending = true;
                inputEl.disabled = true;

                var typingEl = chatShowTyping();

                var fd = new URLSearchParams();
                fd.append('action', 'atlas_chat');
                fd.append('_ajax_nonce', CHAT_NONCE);
                fd.append('message', text);
                fd.append('session_id', chatState.sessionId);
                fd.append('instruction_set', instrSet);
                fd.append('endpoint', CHAT_ENDPOINT);

                fetch(AJAX, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: fd.toString()
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    typingEl.remove();
                    if (data.success && data.data) {
                        var reply = data.data.response || data.data.output || '';
                        if (reply) {
                            chatAddMsg(reply, false);
                        } else {
                            chatAddErr('Nem erkezett valasz.');
                        }
                    } else {
                        chatAddErr((data.data && data.data.message) || 'Hiba tortent.');
                    }

                    // Client-side counter (server skips limit for admins)
                    chatState.remaining--;
                    chatUpdateCounter();

                    if (chatState.remaining <= 0) {
                        var limitDiv = document.createElement('div');
                        limitDiv.className = 'ai-chat-limit';
                        limitDiv.innerHTML = '<strong>3 kerdes limit elerte.</strong><br>Zard be es nyisd ujra a chat tesztet az ujrakezdéshez.';
                        msgsEl.appendChild(limitDiv);
                        inputEl.disabled = true;
                        msgsEl.scrollTop = msgsEl.scrollHeight;
                    }
                })
                .catch(function(err) {
                    typingEl.remove();
                    chatAddErr('Kapcsolodasi hiba.');
                })
                .finally(function() {
                    chatState.sending = false;
                    if (chatState.remaining > 0) {
                        inputEl.disabled = false;
                        sendBtn.disabled = !inputEl.value.trim();
                        inputEl.focus();
                    }
                });
            }

            function chatAddMsg(content, isUser) {
                var div = document.createElement('div');
                div.className = 'ai-chat-msg ' + (isUser ? 'ai-chat-msg-user' : 'ai-chat-msg-assistant');
                var avClass = isUser ? 'ai-chat-av-user' : 'ai-chat-av-ai';
                var avSvg = isUser ? USER_ICON : AI_ICON;
                var rendered = isUser ? chatEscHtml(content) : chatRenderMd(content);
                div.innerHTML =
                    '<div class="ai-chat-av ' + avClass + '">' + avSvg + '</div>' +
                    '<div class="ai-chat-bub">' + rendered + '</div>';
                msgsEl.appendChild(div);
                msgsEl.scrollTop = msgsEl.scrollHeight;
            }

            function chatAddErr(msg) {
                var div = document.createElement('div');
                div.className = 'ai-chat-msg ai-chat-msg-assistant';
                div.innerHTML =
                    '<div class="ai-chat-av ai-chat-av-ai">' + AI_ICON + '</div>' +
                    '<div class="ai-chat-bub ai-chat-err">' + chatEscHtml(msg) + '</div>';
                msgsEl.appendChild(div);
                msgsEl.scrollTop = msgsEl.scrollHeight;
            }

            function chatShowTyping() {
                var div = document.createElement('div');
                div.className = 'ai-chat-msg ai-chat-msg-assistant';
                div.innerHTML =
                    '<div class="ai-chat-av ai-chat-av-ai">' + AI_ICON + '</div>' +
                    '<div class="ai-chat-typing"><div class="ai-chat-dot"></div><div class="ai-chat-dot"></div><div class="ai-chat-dot"></div></div>';
                msgsEl.appendChild(div);
                msgsEl.scrollTop = msgsEl.scrollHeight;
                return div;
            }

            function chatUpdateCounter() {
                var r = Math.max(0, chatState.remaining);
                counterEl.textContent = r + '/3';
                counterEl.className = 'ai-chat-counter' + (r <= 1 ? ' ai-chat-counter-warn' : '');
            }

            function chatEscHtml(t) {
                var d = document.createElement('div');
                d.textContent = t;
                return d.innerHTML.replace(/\n/g, '<br>');
            }

            function chatRenderMd(text) {
                if (!text) return '';
                var cb = [];
                text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, function(_, l, c) {
                    var i = cb.length;
                    cb.push('<pre><code>' + chatEscHtml(c.trim()) + '</code></pre>');
                    return '\x00C' + i + '\x00';
                });
                text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
                var lines = text.split('\n'), html = [], inL = false, lt = '';
                for (var i = 0; i < lines.length; i++) {
                    var ln = lines[i];
                    var m = ln.match(/^\x00C(\d+)\x00$/);
                    if (m) { if (inL) { html.push('</' + lt + '>'); inL = false; } html.push(cb[parseInt(m[1])]); continue; }
                    if (/^### (.+)/.test(ln)) { if (inL) { html.push('</' + lt + '>'); inL = false; } html.push('<h3>' + chatInl(ln.slice(4)) + '</h3>'); continue; }
                    if (/^## (.+)/.test(ln)) { if (inL) { html.push('</' + lt + '>'); inL = false; } html.push('<h2>' + chatInl(ln.slice(3)) + '</h2>'); continue; }
                    if (/^# (.+)/.test(ln)) { if (inL) { html.push('</' + lt + '>'); inL = false; } html.push('<h1>' + chatInl(ln.slice(2)) + '</h1>'); continue; }
                    if (/^> (.+)/.test(ln)) { if (inL) { html.push('</' + lt + '>'); inL = false; } html.push('<blockquote>' + chatInl(ln.slice(2)) + '</blockquote>'); continue; }
                    if (/^[\-\*] (.+)/.test(ln)) { if (!inL || lt !== 'ul') { if (inL) html.push('</' + lt + '>'); html.push('<ul>'); inL = true; lt = 'ul'; } html.push('<li>' + chatInl(ln.replace(/^[\-\*] /, '')) + '</li>'); continue; }
                    if (/^\d+\. (.+)/.test(ln)) { if (!inL || lt !== 'ol') { if (inL) html.push('</' + lt + '>'); html.push('<ol>'); inL = true; lt = 'ol'; } html.push('<li>' + chatInl(ln.replace(/^\d+\. /, '')) + '</li>'); continue; }
                    if (inL) { html.push('</' + lt + '>'); inL = false; }
                    if (ln.trim() === '') continue;
                    html.push('<p>' + chatInl(ln) + '</p>');
                }
                if (inL) html.push('</' + lt + '>');
                return html.join('\n');
            }

            function chatInl(t) {
                t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                t = t.replace(/__(.+?)__/g, '<strong>$1</strong>');
                t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
                t = t.replace(/_(.+?)_/g, '<em>$1</em>');
                t = t.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
                return t;
            }
        }

        function chatUuid() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        }

    })();
    </script>
    <?php
}
