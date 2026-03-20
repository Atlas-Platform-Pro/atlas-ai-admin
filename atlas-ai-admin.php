<?php
/**
 * Plugin Name: Atlas AI Admin
 * Plugin URI:  https://github.com/Atlas-Platform-Pro/atlas-ai-admin
 * Description: Admin panel for managing Atlas AI system instructions via n8n CRUD API.
 * Version:     1.1.0
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

define('ATLAS_AI_ADMIN_VERSION', '1.1.0');
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

                    html += '<tr>' +
                        '<td><strong>v' + (row.version || '?') + '</strong></td>' +
                        '<td>' + badge + '</td>' +
                        '<td>' + date + '</td>' +
                        '<td class="ai-preview">' + preview + '</td>' +
                        '<td>' + activateBtn + viewBtn + '</td>' +
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

    })();
    </script>
    <?php
}
