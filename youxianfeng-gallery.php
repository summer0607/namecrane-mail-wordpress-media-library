<?php
/**
 * Plugin Name: 游先锋图库
 * Description: 将媒体上传至游先锋邮箱存储，自动生成公开链接，并替换 WordPress 与主题的媒体选择入口。
 * Version: 1.0.1
 * Update URI: https://github.com/summer0607/youxianfeng-gallery
 * Author: 游先锋
 */

defined('ABSPATH') || exit;

final class YouXianFeng_Gallery {
    const VERSION = '1.0.1';
    const GITHUB_REPOSITORY = 'summer0607/youxianfeng-gallery';
    const RELEASE_ASSET = 'youxianfeng-gallery.zip';
    const UPDATE_CACHE_KEY = 'yxf_gallery_github_release';
    const OPTION = 'yxf_gallery_settings';
    const USERNAME_META = 'yxf_gallery_username';
    const SECRET_META = 'yxf_gallery_secret';
    const CAPABILITY = 'use_yxf_gallery';
    const CAPS_OPTION = 'yxf_gallery_caps_version';
    const TABLE_SUFFIX = 'yxf_gallery_items';
    const DB_OPTION = 'yxf_gallery_db_version';
    const DB_VERSION = '6';

    public static function init() {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_upgrade'));
        add_action('admin_init', array(__CLASS__, 'enforce_login_gate'), 1);
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
        add_action('wp_ajax_yxf_gallery_upload_image', array(__CLASS__, 'ajax_upload_image'));
        add_action('wp_ajax_yxf_gallery_delete_remote_item', array(__CLASS__, 'ajax_delete_remote_item'));
        // 保留后台 AJAX 入口，供后台环境兼容使用。
        add_action('wp_ajax_yxf_gallery_media_frame', array(__CLASS__, 'ajax_media_iframe'));
        // 前台 iframe 不能依赖 admin-ajax，否则普通用户会得到 WordPress 默认的“0”。
        add_action('template_redirect', array(__CLASS__, 'frontend_media_iframe'), 0);
        add_action('admin_post_yxf_gallery_check_update', array(__CLASS__, 'check_update_now'));
        add_action('admin_notices', array(__CLASS__, 'update_check_notice'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_media_replacement'), 999);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_media_replacement'), 999);
        add_action('media_buttons', array(__CLASS__, 'classic_editor_button'), 20, 1);
        add_action('media_upload_yxf_gallery', array(__CLASS__, 'media_iframe'));
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'gallery_attachment_query'));
        add_action('pre_get_posts', array(__CLASS__, 'gallery_media_list_query'));
        add_filter('wp_get_attachment_url', array(__CLASS__, 'gallery_attachment_url'), 20, 2);
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'prepare_gallery_attachment'), 20, 3);
        add_filter('wp_handle_upload_prefilter', array(__CLASS__, 'prevent_native_image_upload'));
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 20, 3);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(__CLASS__, 'update_action_link'));
        add_filter('http_request_args', array(__CLASS__, 'github_http_headers'), 20, 2);
    }

    public static function activate() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            original_url text NOT NULL,
            output_url text NOT NULL,
            file_name varchar(255) NOT NULL DEFAULT '',
            mime_type varchar(100) NOT NULL DEFAULT '',
            remote_path varchar(500) NOT NULL DEFAULT '',
            file_hash char(64) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'ready',
            author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            storage_owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY author_id (author_id),
            KEY storage_owner_id (storage_owner_id),
            KEY author_file_hash (author_id, file_hash),
            KEY attachment_id (attachment_id),
            KEY created_at (created_at)
        ) {$charset};");
        add_option(self::OPTION, self::defaults());
        self::grant_capabilities();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function maybe_upgrade() {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::activate();
        }
        if (get_option(self::CAPS_OPTION) !== self::VERSION) {
            self::grant_capabilities();
        }
        self::remove_legacy_global_credentials();
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function defaults() {
        return array(
            'rewrite_enabled' => 0,
            'source_host'     => 'mail.youxianfeng.com',
            'target_base'     => 'https://img.youxianfeng.com',
            'storage_protocol'=> 'ftps',
            'sftp_host'       => 'mail.youxianfeng.com',
            'sftp_port'       => 8221,
            'sftp_remote_path'=> '',
            'api_base'        => 'https://mail.youxianfeng.com',
            'github_secret'   => '',
            'replace_media_library' => 0,
            'shared_account_user_id' => 0,
            'shared_account_roles' => array(),
        );
    }

    private static function settings() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    private static function remove_legacy_global_credentials() {
        $settings = get_option(self::OPTION, array());
        if (!is_array($settings) || (!array_key_exists('sftp_username', $settings) && !array_key_exists('sftp_secret', $settings) && !array_key_exists('api_username', $settings))) {
            return;
        }
        unset($settings['sftp_username'], $settings['sftp_secret'], $settings['api_username']);
        update_option(self::OPTION, $settings, false);
    }

    private static function settings_from_request($current) {
        $source = strtolower(trim((string) wp_unslash($_POST['source_host'] ?? '')));
        $source = preg_replace('#^https?://#', '', $source);
        $target = esc_url_raw(trim((string) wp_unslash($_POST['target_base'] ?? '')));
        $protocol = sanitize_key(wp_unslash($_POST['storage_protocol'] ?? $current['storage_protocol']));
        $protocol = in_array($protocol, array('ftps', 'sftp'), true) ? $protocol : 'ftps';
        $default_port = $protocol === 'ftps' ? 8221 : 22;
        $settings = array_merge($current, array(
            'rewrite_enabled'  => empty($_POST['rewrite_enabled']) ? 0 : 1,
            'replace_media_library' => empty($_POST['replace_media_library']) ? 0 : 1,
            'source_host'      => trim($source, '/'),
            'target_base'      => untrailingslashit($target),
            'storage_protocol' => $protocol,
            'sftp_host'        => trim((string) wp_unslash($_POST['sftp_host'] ?? '')),
            'sftp_port'        => max(1, min(65535, absint($_POST['sftp_port'] ?? $default_port) ?: $default_port)),
            'sftp_remote_path' => '/' . ltrim(trim((string) wp_unslash($_POST['sftp_remote_path'] ?? '')), '/'),
            'api_base'         => untrailingslashit(esc_url_raw(trim((string) wp_unslash($_POST['api_base'] ?? '')))),
        ));
        $shared_owner_id = absint($_POST['shared_account_user_id'] ?? 0);
        $requested_roles = isset($_POST['shared_account_roles']) && is_array($_POST['shared_account_roles'])
            ? array_map('sanitize_key', wp_unslash($_POST['shared_account_roles']))
            : array();
        $shared_roles = array_values(array_intersect(array('subscriber', 'contributor'), $requested_roles));
        // 只能选择一个已经独立保存过邮箱登录信息的站内用户作为共享账号。
        $settings['shared_account_user_id'] = ($shared_owner_id && get_userdata($shared_owner_id) && self::user_has_own_login($shared_owner_id)) ? $shared_owner_id : 0;
        $settings['shared_account_roles'] = $settings['shared_account_user_id'] ? $shared_roles : array();
        // 账号和密码只能属于具体用户，禁止再写入网站全局设置。
        unset($settings['sftp_username'], $settings['sftp_secret'], $settings['api_username']);
        return $settings;
    }

    private static function encrypt_secret($secret) {
        if (!function_exists('openssl_encrypt')) {
            return new WP_Error('missing_openssl', '服务器不支持安全保存存储密码。');
        }
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return new WP_Error('encrypt_failed', '无法安全保存存储密码。');
        }
        return base64_encode($iv . $ciphertext);
    }

    private static function decrypt_secret($stored) {
        if ($stored === '' || !function_exists('openssl_decrypt')) {
            return '';
        }
        $payload = base64_decode($stored, true);
        if ($payload === false || strlen($payload) <= 16) {
            return '';
        }
        return (string) openssl_decrypt(substr($payload, 16), 'AES-256-CBC', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($payload, 0, 16));
    }

    private static function grant_capabilities() {
        // 论坛发帖、商城评价等前台上传入口主要面向普通已登录会员；
        // 图库按用户隔离文件和登录信息，因此这些角色也只能操作自己的媒体。
        foreach (array('administrator', 'editor', 'author', 'contributor', 'subscriber') as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap(self::CAPABILITY);
            }
        }
        update_option(self::CAPS_OPTION, self::VERSION, false);
    }

    private static function can_use_gallery() {
        return current_user_can(self::CAPABILITY) || current_user_can('manage_options');
    }

    private static function can_administer() {
        return current_user_can('manage_options');
    }

    private static function can_delete_item($owner_id) {
        return self::can_administer() || (self::can_use_gallery() && (int) $owner_id === get_current_user_id());
    }

    /** 开关接管图片、视频、音频和普通附件；原始文件始终保存于用户自己的游先锋邮箱网盘。 */
    private static function media_replacement_enabled() {
        return !empty(self::settings()['replace_media_library']) && self::can_use_gallery();
    }

    public static function enqueue_media_replacement() {
        if (!self::media_replacement_enabled()) {
            return;
        }
        // 图库自身的上传页不能再被“接管上传按钮”的脚本二次拦截，
        // 否则文件选择控件会被打开图库的动作覆盖，造成页面无法操作。
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && strpos((string) $screen->id, 'yxf-gallery') !== false) {
                return;
            }
        }
        $dependencies = array('jquery');
        if (is_admin()) {
            wp_enqueue_media();
            $dependencies[] = 'media-views';
            $dependencies[] = 'thickbox';
        }
        $asset_path = plugin_dir_path(__FILE__) . 'assets/media-replacement.js';
        wp_enqueue_script(
            'yxf-gallery-media-replacement',
            plugin_dir_url(__FILE__) . 'assets/media-replacement.js',
            $dependencies,
            is_file($asset_path) ? (string) filemtime($asset_path) : self::VERSION,
            // 前台须在子比编辑器初始化前加载，才能让图片和附件对象从一开始使用图库。
            false
        );
        wp_localize_script('yxf-gallery-media-replacement', 'YXFGalleryReplacement', array(
            // 前台统一使用站点自身地址，不触发 wp-admin 的权限和 AJAX 默认响应。
            'iframeUrl' => home_url('/'),
            'loginUrl'  => self::login_url(),
            'hasLogin'  => self::user_has_login(),
            'enabled'   => true,
        ));
    }

    /** 让默认媒体库只列出图库生成的外链媒体记录，不会读取或上传网站本地原文件。 */
    public static function gallery_attachment_query($query) {
        if (!self::media_replacement_enabled()) {
            return $query;
        }
        $query['meta_query'] = isset($query['meta_query']) && is_array($query['meta_query']) ? $query['meta_query'] : array();
        $query['meta_query'][] = array('key' => '_yxf_gallery_item_id', 'compare' => 'EXISTS');
        if (!self::can_administer()) {
            $query['author'] = get_current_user_id();
        }
        return $query;
    }

    /** WordPress「媒体库」列表与弹窗保持同一份图库媒体，不展示网站本地 uploads 文件。 */
    public static function gallery_media_list_query($query) {
        if (!is_admin() || !$query->is_main_query() || !self::media_replacement_enabled() || $query->get('post_type') !== 'attachment') {
            return;
        }
        $query->set('meta_query', array(array('key' => '_yxf_gallery_item_id', 'compare' => 'EXISTS')));
        if (!self::can_administer()) {
            $query->set('author', get_current_user_id());
        }
    }

    public static function gallery_attachment_url($url, $attachment_id) {
        $external_url = get_post_meta($attachment_id, '_yxf_gallery_external_url', true);
        return $external_url ? esc_url_raw($external_url) : $url;
    }

    public static function prepare_gallery_attachment($response, $attachment, $meta) {
        $external_url = get_post_meta($attachment->ID, '_yxf_gallery_external_url', true);
        if (!$external_url) {
            return $response;
        }
        $response['url'] = esc_url_raw($external_url);
        $response['link'] = esc_url_raw($external_url);
        $response['icon'] = esc_url_raw($external_url);
        $mime = get_post_mime_type($attachment) ?: 'application/octet-stream';
        $response['type'] = strtok($mime, '/');
        $response['subtype'] = $mime;
        if (strpos($mime, 'image/') === 0) {
            $response['sizes'] = array(
                'thumbnail' => array('url' => esc_url_raw($external_url), 'width' => 150, 'height' => 150, 'orientation' => 'landscape'),
                'medium'    => array('url' => esc_url_raw($external_url), 'width' => 300, 'height' => 300, 'orientation' => 'landscape'),
                'full'      => array('url' => esc_url_raw($external_url), 'width' => 0, 'height' => 0, 'orientation' => 'landscape'),
            );
        }
        return $response;
    }

    /**
     * 仅限制 WordPress 自身的媒体上传请求。插件安装、主题安装、导入和更新
     * 同样会经过 wp_handle_upload_prefilter，不能在这里一并拦截。
     */
    public static function prevent_native_image_upload($file) {
        if (!self::media_replacement_enabled()) {
            return $file;
        }
        global $pagenow;
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        $is_media_request = in_array($pagenow, array('async-upload.php', 'media-new.php'), true)
            || ($pagenow === 'admin-ajax.php' && $action === 'upload-attachment');
        if (!$is_media_request) {
            return $file;
        }
        $file['error'] = self::user_has_login()
            ? '媒体上传已由游先锋图库接管，请点击“上传到游先锋图库”。'
            : '请先登录游先锋邮箱后再上传媒体。';
        return $file;
    }

    private static function account($user_id = 0) {
        $user_id = $user_id ?: get_current_user_id();
        return array(
            'username' => (string) get_user_meta($user_id, self::USERNAME_META, true),
            'secret'   => (string) get_user_meta($user_id, self::SECRET_META, true),
        );
    }

    private static function user_has_own_login($user_id = 0) {
        $account = self::account($user_id);
        return $account['username'] !== '' && self::decrypt_secret($account['secret']) !== '';
    }

    /** 当前用户没有自有账号时，按后台授权的角色使用指定的共享账号。 */
    private static function shared_account_owner_for_user($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || self::user_has_own_login($user_id)) {
            return 0;
        }
        $settings = self::settings();
        $owner_id = absint($settings['shared_account_user_id'] ?? 0);
        $roles = array_intersect(
            array('subscriber', 'contributor'),
            array_map('sanitize_key', (array) ($settings['shared_account_roles'] ?? array()))
        );
        $user = get_userdata($user_id);
        if (!$owner_id || !$roles || !$user || !array_intersect($roles, (array) $user->roles)) {
            return 0;
        }
        return self::user_has_own_login($owner_id) ? $owner_id : 0;
    }

    private static function effective_account_owner_id($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id) {
            return 0;
        }
        return self::user_has_own_login($user_id) ? $user_id : self::shared_account_owner_for_user($user_id);
    }

    private static function is_using_shared_account($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $owner_id = self::effective_account_owner_id($user_id);
        return $owner_id > 0 && $owner_id !== $user_id;
    }

    private static function storage_settings_for_user($user_id = 0) {
        $owner_id = self::effective_account_owner_id($user_id);
        $account = $owner_id ? self::account($owner_id) : array('username' => '', 'secret' => '');
        $settings = self::settings();
        $settings['sftp_username'] = $account['username'];
        $settings['api_username'] = $account['username'];
        return array($settings, self::decrypt_secret($account['secret']));
    }

    private static function user_has_login($user_id = 0) {
        return self::effective_account_owner_id($user_id) > 0;
    }

    private static function login_url() {
        return admin_url('admin.php?page=yxf-gallery-login');
    }

    private static function redirect_to_login() {
        wp_safe_redirect(add_query_arg('yxf_gallery_notice', 'login_required', self::login_url()));
        exit;
    }

    public static function enforce_login_gate() {
        if (!is_admin() || !self::can_use_gallery()) {
            return;
        }
        global $pagenow;
        if (self::media_replacement_enabled() && $pagenow === 'media-new.php') {
            if (!self::user_has_login()) {
                self::redirect_to_login();
            }
            wp_safe_redirect(admin_url('admin.php?page=yxf-gallery-upload'));
            exit;
        }
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, array('yxf-gallery', 'yxf-gallery-upload'), true) || self::user_has_login()) {
            return;
        }
        self::redirect_to_login();
    }

    private static function plugin_file() {
        return plugin_basename(__FILE__);
    }

    private static function github_token() {
        return self::decrypt_secret((string) (self::settings()['github_secret'] ?? ''));
    }

    private static function release_data($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::UPDATE_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $headers = array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'));
        $token = self::github_token();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $response = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPOSITORY . '/releases/latest', array(
            'timeout' => 12,
            'headers' => $headers,
        ));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || empty($release['tag_name'])) {
            return null;
        }
        set_site_transient(self::UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);
        return $release;
    }

    private static function release_package($release) {
        foreach ((array) ($release['assets'] ?? array()) as $asset) {
            if (($asset['name'] ?? '') === self::RELEASE_ASSET) {
                if (self::github_token() !== '' && !empty($asset['url'])) {
                    return esc_url_raw($asset['url']);
                }
                if (!empty($asset['browser_download_url'])) {
                    return esc_url_raw($asset['browser_download_url']);
                }
            }
        }
        return '';
    }

    public static function github_http_headers($args, $url) {
        $prefix = 'https://api.github.com/repos/' . self::GITHUB_REPOSITORY . '/releases/';
        if (strpos($url, $prefix) !== 0) {
            return $args;
        }
        $args['headers'] = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
        $args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/');
        $args['headers']['Accept'] = strpos($url, '/assets/') !== false ? 'application/octet-stream' : 'application/vnd.github+json';
        $token = self::github_token();
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        return $args;
    }

    public static function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }
        // WordPress 会暂存上一次检测到的版本。插件刚升级后，必须先移除
        // 该旧记录，否则后台仍会把已安装版本误显示为“有更新”。
        $plugin_file = self::plugin_file();
        unset($transient->response[$plugin_file]);
        $release = self::release_data();
        $package = is_array($release) ? self::release_package($release) : '';
        $version = is_array($release) ? ltrim((string) $release['tag_name'], 'vV') : '';
        if ($version === '' || $package === '' || !version_compare($version, self::VERSION, '>')) {
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$plugin_file] = (object) array(
                'slug'        => dirname($plugin_file),
                'plugin'      => $plugin_file,
                'new_version' => self::VERSION,
                'url'         => 'https://github.com/' . self::GITHUB_REPOSITORY,
                'package'     => '',
            );
            return $transient;
        }
        unset($transient->no_update[$plugin_file]);
        $transient->response[$plugin_file] = (object) array(
            'slug'        => dirname($plugin_file),
            'plugin'      => $plugin_file,
            'new_version' => $version,
            'url'         => 'https://github.com/' . self::GITHUB_REPOSITORY,
            'package'     => $package,
        );
        return $transient;
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(self::plugin_file())) {
            return $result;
        }
        $release = self::release_data();
        if (!is_array($release)) {
            return $result;
        }
        $version = ltrim((string) $release['tag_name'], 'vV');
        $package = self::release_package($release);
        return (object) array(
            'name'          => '游先锋图库',
            'slug'          => dirname(self::plugin_file()),
            'version'       => $version ?: self::VERSION,
            'homepage'      => 'https://github.com/' . self::GITHUB_REPOSITORY,
            'download_link' => $package,
            'sections'      => array('description' => '游先锋邮箱个人图库与文章图片插入工具。', 'changelog' => wp_kses_post((string) ($release['body'] ?? ''))),
        );
    }

    public static function update_action_link($links) {
        if (!self::can_administer()) {
            return $links;
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=yxf_gallery_check_update'), 'yxf_gallery_check_update');
        array_unshift($links, '<a href="' . esc_url($url) . '">检查更新</a>');
        return $links;
    }

    public static function check_update_now() {
        if (!self::can_administer()) {
            wp_die('无权检查插件更新。');
        }
        check_admin_referer('yxf_gallery_check_update');
        delete_site_transient(self::UPDATE_CACHE_KEY);
        // wp_update_plugins() 会在短时间内直接复用 WordPress 的全站更新结果。
        // 同时清除它，才能让“检查更新”确实重新读取 GitHub 的当前版本。
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(add_query_arg('yxf_gallery_update_checked', '1', admin_url('plugins.php')));
        exit;
    }

    public static function update_check_notice() {
        if (!self::can_administer() || empty($_GET['yxf_gallery_update_checked'])) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>游先锋图库已检查 GitHub 新版本。</p></div>';
    }

    /**
     * 为不同版本的 cURL 生成协议限制选项。
     * CURLOPT_PROTOCOLS_STR 是较新的写法；部分服务器仍使用旧版 cURL，
     * 此时可安全地回退至 CURLOPT_PROTOCOLS 位掩码。
     */
    private static function storage_protocol_options($protocol) {
        if (!function_exists('curl_init') || !function_exists('curl_version')) {
            return new WP_Error('storage_unsupported', '当前网站未启用文件存储所需的 cURL 扩展。');
        }

        $protocol = $protocol === 'sftp' ? 'sftp' : 'ftp';
        $curl_info = curl_version();
        $supported = array_map('strtolower', (array) ($curl_info['protocols'] ?? array()));
        if (!in_array($protocol, $supported, true)) {
            return new WP_Error('storage_unsupported', '当前网站环境未启用 ' . strtoupper($protocol) . ' 文件存储协议。');
        }

        // 某些服务器的 PHP 扩展会声明新选项，但底层 libcurl 并不接受它，
        // curl_setopt_array 会直接抛出致命错误。URL 协议由本插件固定生成，
        // 已完成协议可用性核验后，不再额外传入可能不兼容的限制选项。
        return array();
    }

    /** 个别主机的 cURL 扩展会对不支持的选项抛出 ValueError，不能让后台直接中断。 */
    private static function curl_set_options($curl, $options) {
        try {
            foreach ((array) $options as $option => $value) {
                curl_setopt($curl, $option, $value);
            }
        } catch (Throwable $error) {
            return new WP_Error('curl_option_unsupported', '当前服务器的 cURL 不支持所需的连接选项：' . $error->getMessage());
        }
        return true;
    }

    private static function storage_test($settings, $password) {
        foreach (array('sftp_host' => '存储服务器', 'sftp_username' => '存储用户名', 'sftp_remote_path' => '目标目录') as $key => $label) {
            if (empty($settings[$key])) {
                return new WP_Error('sftp_missing', '请填写' . $label . '。');
            }
        }
        if ($password === '') {
            return new WP_Error('sftp_password', '请填写存储密码后再测试。');
        }
        $protocol = $settings['storage_protocol'] === 'sftp' ? 'sftp' : 'ftp';
        $label = $protocol === 'sftp' ? 'SFTP' : 'FTPS';
        $protocol_options = self::storage_protocol_options($protocol);
        if (is_wp_error($protocol_options)) {
            return $protocol_options;
        }
        $curl = curl_init();
        $target = sprintf('%s://%s:%d%s', $protocol, $settings['sftp_host'], $settings['sftp_port'], rtrim($settings['sftp_remote_path'], '/') . '/');
        // cURL 选项是数字编号；array_merge 会重编号，必须用 array_replace 保持原编号。
        $configured = self::curl_set_options($curl, array_replace(array(
            CURLOPT_URL           => $target,
            // CURLOPT_USERPWD 的兼容范围远大于 USERNAME/PASSWORD 分拆选项。
            CURLOPT_USERPWD       => $settings['sftp_username'] . ':' . $password,
            CURLOPT_CONNECTTIMEOUT=> 15,
            CURLOPT_TIMEOUT       => 40,
            // 测试连接返回的目录内容不能直接输出，否则会污染 AJAX 的 JSON 结果。
            CURLOPT_RETURNTRANSFER => true,
        ), $protocol_options));
        if (is_wp_error($configured)) { curl_close($curl); return $configured; }
        if ($protocol === 'sftp') {
            curl_setopt($curl, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
        } else {
            curl_setopt($curl, CURLOPT_USE_SSL, CURLUSESSL_ALL);
        }
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        return $ok === false ? new WP_Error('storage_connection', $label . ' 连接失败：' . $error) : true;
    }

    private static function storage_url($settings, $remote_file) {
        // 游先锋邮箱的 FTPS 端口使用显式 TLS：地址必须以 ftp:// 打开，再由 cURL 升级为 TLS。
        $protocol = $settings['storage_protocol'] === 'sftp' ? 'sftp' : 'ftp';
        $parts = array_filter(explode('/', trim($settings['sftp_remote_path'], '/') . '/' . $remote_file), 'strlen');
        return sprintf('%s://%s:%d/%s', $protocol, $settings['sftp_host'], $settings['sftp_port'], implode('/', array_map('rawurlencode', $parts)));
    }

    /** 使用数据库保存的完整网盘路径构造删除请求地址。 */
    private static function storage_path_url($settings, $remote_path) {
        $protocol = $settings['storage_protocol'] === 'sftp' ? 'sftp' : 'ftp';
        $parts = array_filter(explode('/', trim((string) $remote_path, '/')), 'strlen');
        return sprintf('%s://%s:%d/%s', $protocol, $settings['sftp_host'], $settings['sftp_port'], implode('/', array_map('rawurlencode', $parts)));
    }

    private static function storage_upload($settings, $password, $local_file, $remote_file) {
        $available = self::storage_test($settings, $password);
        if (is_wp_error($available)) {
            return $available;
        }
        $stream = fopen($local_file, 'rb');
        if (!$stream) {
            return new WP_Error('local_file', '无法读取待上传的图片。');
        }
        $protocol = $settings['storage_protocol'] === 'sftp' ? 'sftp' : 'ftp';
        $protocol_options = self::storage_protocol_options($protocol);
        if (is_wp_error($protocol_options)) {
            fclose($stream);
            return $protocol_options;
        }
        $curl = curl_init();
        $configured = self::curl_set_options($curl, array_replace(array(
            CURLOPT_URL            => self::storage_url($settings, $remote_file),
            CURLOPT_USERPWD        => $settings['sftp_username'] . ':' . $password,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $stream,
            CURLOPT_INFILESIZE     => filesize($local_file),
        ), $protocol_options));
        if (is_wp_error($configured)) { fclose($stream); curl_close($curl); return $configured; }
        if ($protocol === 'sftp') {
            curl_setopt($curl, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
        } else {
            curl_setopt($curl, CURLOPT_USE_SSL, CURLUSESSL_ALL);
        }
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        fclose($stream);
        curl_close($curl);
        return $ok === false ? new WP_Error('upload_failed', '图片上传失败：' . $error) : true;
    }

    /** 管理员删除时，同时删除图片所属用户邮箱网盘中的原文件。 */
    private static function storage_delete($settings, $password, $remote_path) {
        if ($password === '' || trim((string) $remote_path, '/') === '') {
            return new WP_Error('delete_credentials', '该图片缺少可用的邮箱登录信息或网盘路径，未执行删除。');
        }
        $protocol = $settings['storage_protocol'] === 'sftp' ? 'sftp' : 'ftp';
        $protocol_options = self::storage_protocol_options($protocol);
        if (is_wp_error($protocol_options)) {
            return $protocol_options;
        }
        $curl = curl_init();
        $configured = self::curl_set_options($curl, array_replace(array(
            CURLOPT_URL            => self::storage_path_url($settings, $remote_path),
            CURLOPT_USERPWD        => $settings['sftp_username'] . ':' . $password,
            // 每次仅删除一张图片；设置较短超时，避免邮箱服务异常拖垮后台页面。
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR    => true,
        ), $protocol_options));
        if (is_wp_error($configured)) { curl_close($curl); return $configured; }
        if ($protocol === 'sftp') {
            curl_setopt($curl, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
            curl_setopt($curl, CURLOPT_NOBODY, true);
            curl_setopt($curl, CURLOPT_QUOTE, array('rm ' . (string) $remote_path));
        } else {
            curl_setopt($curl, CURLOPT_USE_SSL, CURLUSESSL_ALL);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELE');
        }
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        return $ok === false ? new WP_Error('remote_delete_failed', '邮箱网盘文件删除失败：' . $error) : true;
    }

    /** 仅从图库移除记录，保留用户游先锋邮箱网盘中的原文件。 */
    private static function remove_item_from_gallery($item) {
        global $wpdb;
        $wpdb->delete(self::table_name(), array('id' => (int) $item->id), array('%d'));
        if (!empty($item->attachment_id)) {
            wp_delete_attachment((int) $item->attachment_id, true);
        }
        return true;
    }

    /** 远端文件先成功删除，才清理网站中的记录和虚拟媒体。 */
    private static function delete_item_with_remote_file($item) {
        $storage_owner_id = absint($item->storage_owner_id ?? 0) ?: (int) $item->author_id;
        list($settings, $password) = self::storage_settings_for_user($storage_owner_id);
        $deleted = self::delete_remote_file_via_api($settings, $password, (string) $item->remote_path);
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        // 邮箱服务有时会对删除命令返回成功但未真正落盘。确认文件已消失后，
        // 才移除本地图库记录，避免用户误以为网盘文件已经删除。
        $exists = true;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $file = self::remote_file_record($settings, $password, (string) $item->remote_path);
            if (is_wp_error($file)) {
                return $file;
            }
            $exists = (bool) $file;
            if (!$exists) {
                break;
            }
            sleep(1);
        }
        if ($exists) {
            return new WP_Error('remote_delete_unconfirmed', '邮箱网盘未确认删除该图片，已保留图库记录，请稍后重试。');
        }
        global $wpdb;
        return self::remove_item_from_gallery($item);
    }

    private static function api_request($settings, $token, $method, $path, $body = null) {
        $base = untrailingslashit($settings['api_base']);
        if ($base === '') {
            return new WP_Error('api_base', '请填写游先锋邮箱网页接口地址。');
        }
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
        );
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($base . '/api/v1' . $path, $args);
        if (is_wp_error($response)) {
            return new WP_Error('api_request', '游先锋邮箱接口请求失败：' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return new WP_Error('api_response', '游先锋邮箱接口未返回可用结果（HTTP ' . $code . '）。');
        }
        return $data;
    }

    private static function api_login($settings, $password) {
        $username = $settings['api_username'] ?: $settings['sftp_username'];
        if ($username === '' || $password === '') {
            return new WP_Error('api_credentials', '请填写用于游先锋邮箱网页端的账号和密码。');
        }
        $base = untrailingslashit($settings['api_base']);
        $response = wp_remote_post($base . '/api/v1/auth/authenticate-user', array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => wp_json_encode(array('username' => $username, 'password' => $password, 'clientId' => wp_generate_uuid4())),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('api_login', '无法连接游先锋邮箱网页接口。');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ((int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300 || empty($data['accessToken'])) {
            return new WP_Error('api_login', '游先锋邮箱网页账号验证失败，无法自动生成公开链接。');
        }
        return $data['accessToken'];
    }

    private static function find_file_in_tree($folder, $file_name) {
        if (!is_array($folder)) {
            return null;
        }
        foreach ((array) ($folder['files'] ?? array()) as $file) {
            $name = $file['fileName'] ?? ($file['name'] ?? '');
            if ($name === $file_name && !empty($file['id'])) {
                return $file;
            }
        }
        foreach ((array) ($folder['subFolders'] ?? array()) as $sub_folder) {
            $found = self::find_file_in_tree($sub_folder, $file_name);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    private static function create_public_link($settings, $password, $file_name) {
        $token = self::api_login($settings, $password);
        if (is_wp_error($token)) {
            return $token;
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $folders = self::api_request($settings, $token, 'GET', '/filestorage/folders');
            if (is_wp_error($folders)) {
                return $folders;
            }
            $file = self::find_file_in_tree($folders['folder'] ?? array(), $file_name);
            if ($file) {
                $link = self::api_request($settings, $token, 'GET', '/filestorage/' . rawurlencode($file['id']) . '/getlink/public');
                if (is_wp_error($link) || empty($link['publicLink'])) {
                    return is_wp_error($link) ? $link : new WP_Error('public_link', '游先锋邮箱未返回公开下载链接。');
                }
                $public_url = $link['publicLink'];
                if (strpos($public_url, 'http://') !== 0 && strpos($public_url, 'https://') !== 0) {
                    $public_url = untrailingslashit($settings['api_base']) . '/' . ltrim($public_url, '/');
                }
                return esc_url_raw($public_url);
            }
            sleep(1);
        }
        return new WP_Error('file_not_found', '图片已上传，但游先锋邮箱尚未返回文件记录，因此未生成公开链接。');
    }

    /** 通过邮箱网盘接口查找文件；删除必须使用该接口返回的真实文件 ID。 */
    private static function remote_file_record($settings, $password, $remote_path) {
        $token = self::api_login($settings, $password);
        if (is_wp_error($token)) {
            return $token;
        }
        $folders = self::api_request($settings, $token, 'GET', '/filestorage/folders');
        if (is_wp_error($folders)) {
            return $folders;
        }
        return self::find_file_in_tree($folders['folder'] ?? array(), wp_basename($remote_path));
    }

    /** 删除使用邮箱网盘网页端同一接口，而非不可靠的 FTP DELE 命令。 */
    private static function delete_remote_file_via_api($settings, $password, $remote_path) {
        $file = self::remote_file_record($settings, $password, $remote_path);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!$file || empty($file['id'])) {
            return new WP_Error('remote_file_missing', '邮箱网盘中未找到该图片，已停止删除操作。');
        }
        $token = self::api_login($settings, $password);
        if (is_wp_error($token)) {
            return $token;
        }
        return self::api_request($settings, $token, 'POST', '/filestorage/delete-files', array('fileIDs' => array($file['id'])));
    }

    public static function admin_menu() {
        add_menu_page('游先锋图库', '游先锋图库', self::CAPABILITY, 'yxf-gallery', array(__CLASS__, 'render_gallery_page'), 'dashicons-format-gallery', 58);
        add_submenu_page('yxf-gallery', '图库', '图库', self::CAPABILITY, 'yxf-gallery', array(__CLASS__, 'render_gallery_page'));
        add_submenu_page('yxf-gallery', '上传图片', '上传图片', self::CAPABILITY, 'yxf-gallery-upload', array(__CLASS__, 'render_upload_page'));
        add_submenu_page('yxf-gallery', '登录', '登录', self::CAPABILITY, 'yxf-gallery-login', array(__CLASS__, 'render_login_page'));
        add_submenu_page('yxf-gallery', '图库设置', '设置', 'manage_options', 'yxf-gallery-settings', array(__CLASS__, 'render_settings_page'));
        // 管理图片是管理员专用二级菜单，不出现在作者、编辑的图库菜单中。
        add_submenu_page('yxf-gallery', '管理图片', '管理图片', 'manage_options', 'yxf-gallery-manage', array(__CLASS__, 'render_manage_page'));
    }

    public static function handle_admin_actions() {
        if (!is_admin() || empty($_POST['yxf_gallery_action'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['yxf_gallery_action']));
        if ($action === 'save_settings') {
            if (!self::can_administer()) {
                wp_die('无权修改图库设置。');
            }
            check_admin_referer('yxf_gallery_settings');
            $settings = self::settings_from_request(self::settings());
            $target_parts = wp_parse_url($settings['target_base']);
            if ($settings['source_host'] === '' || empty($target_parts['host'])) {
                self::redirect('yxf-gallery-settings', 'settings_error');
            }
            $github_token = trim((string) wp_unslash($_POST['github_token'] ?? ''));
            if ($github_token !== '') {
                $github_secret = self::encrypt_secret($github_token);
                if (is_wp_error($github_secret)) {
                    set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', $github_secret->get_error_message()), MINUTE_IN_SECONDS);
                    self::redirect('yxf-gallery-settings', 'stored_notice');
                }
                $settings['github_secret'] = $github_secret;
            }
            update_option(self::OPTION, $settings, false);
            self::redirect('yxf-gallery-settings', 'settings_saved');
        }

        if ($action === 'save_login' || $action === 'test_login') {
            if (!self::can_use_gallery()) {
                wp_die('无权测试存储连接。');
            }
            check_admin_referer('yxf_gallery_login');
            $user_id = get_current_user_id();
            $username = trim((string) wp_unslash($_POST['gallery_username'] ?? ''));
            $password = (string) wp_unslash($_POST['gallery_password'] ?? '');
            $current = self::account($user_id);
            if ($username === '') {
                $username = $current['username'];
            }
            if ($password === '') {
                $password = self::decrypt_secret($current['secret']);
            }
            if ($action === 'save_login') {
                if ($username === '' || $password === '') {
                    self::redirect('yxf-gallery-login', 'login_required');
                }
                $secret = self::encrypt_secret($password);
                if (is_wp_error($secret)) {
                    set_transient('yxf_gallery_notice_' . $user_id, array('error', $secret->get_error_message()), MINUTE_IN_SECONDS);
                    self::redirect('yxf-gallery-login', 'stored_notice');
                }
                update_user_meta($user_id, self::USERNAME_META, $username);
                update_user_meta($user_id, self::SECRET_META, $secret);
                self::redirect('yxf-gallery-login', 'login_saved');
            }
            $settings = self::settings();
            $settings['sftp_username'] = $username;
            $settings['api_username'] = $username;
            $result = self::storage_test($settings, $password);
            set_transient(
                'yxf_gallery_notice_' . $user_id,
                is_wp_error($result) ? array('error', $result->get_error_message()) : array('success', strtoupper($settings['storage_protocol']) . ' 连接成功，目标目录可访问。'),
                MINUTE_IN_SECONDS
            );
            self::redirect('yxf-gallery-login', 'stored_notice');
        }

        if ($action === 'upload_image') {
            if (!self::can_use_gallery()) {
                wp_die('无权上传图库图片。');
            }
            check_admin_referer('yxf_gallery_upload');
            $result = self::upload_gallery_file($_FILES['gallery_file'] ?? null);
            if (is_wp_error($result)) {
                set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', $result->get_error_message()), MINUTE_IN_SECONDS);
                self::redirect('yxf-gallery-upload', 'stored_notice');
            }
            self::redirect('yxf-gallery', 'uploaded');
        }

        if ($action === 'remove_item' || $action === 'delete_item') {
            if (!self::can_use_gallery()) {
                wp_die('无权删除图库图片。');
            }
            check_admin_referer('yxf_gallery_delete');
            $item_id = absint($_POST['item_id'] ?? 0);
            if ($item_id) {
                global $wpdb;
                $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $item_id));
                $owner_id = $item ? (int) $item->author_id : 0;
                if (!$owner_id || !self::can_delete_item($owner_id)) {
                    wp_die('无权删除其他用户上传的图片。');
                }
                $result = $action === 'remove_item' ? self::remove_item_from_gallery($item) : self::delete_item_with_remote_file($item);
                if (is_wp_error($result)) {
                    set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', $result->get_error_message()), MINUTE_IN_SECONDS);
                    self::redirect(self::can_administer() ? 'yxf-gallery-manage' : 'yxf-gallery', 'stored_notice');
                }
            }
            self::redirect(self::can_administer() ? 'yxf-gallery-manage' : 'yxf-gallery', $action === 'remove_item' ? 'removed' : 'deleted');
        }

        if ($action === 'delete_items_remote') {
            if (!self::can_administer()) {
                wp_die('无权删除邮箱网盘中的图片。');
            }
            check_admin_referer('yxf_gallery_delete_remote');
            set_transient('yxf_gallery_notice_' . get_current_user_id(), array('warning', '请在管理图片页面使用批量删除按钮；图片会逐张删除以避免后台超时。'), MINUTE_IN_SECONDS);
            self::redirect('yxf-gallery-manage', 'stored_notice');
        }
    }

    /** 上传一张图片并以内容指纹去重，供普通表单和队列接口共用。 */
    private static function upload_gallery_file($file) {
        if (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('upload_error', '请选择有效的图片文件。');
        }

        return self::upload_local_image_file((string) $file['tmp_name'], (string) $file['name'], (int) $file['size']);
    }

    /**
     * 供可信的后台插件上传其生成的本地图片，并复用图库的权限、去重与公开链接流程。
     *
     * @return array{url:string,attachment_id:int,name:string,duplicate:bool,warning:string}|WP_Error
     */
    public static function upload_generated_image(string $tmp_name, string $file_name) {
        if (!self::can_use_gallery()) {
            return new WP_Error('gallery_permission_denied', '当前账号无权上传游先锋图库图片。');
        }
        if ($tmp_name === '' || !is_file($tmp_name) || !is_readable($tmp_name)) {
            return new WP_Error('generated_image_missing', '待上传的图片文件不存在。');
        }

        $result = self::upload_local_image_file($tmp_name, $file_name, (int) filesize($tmp_name));
        if (is_wp_error($result)) {
            return $result;
        }

        $item = $result['item'] ?? null;
        $url  = $item && (string) ($item->status ?? '') === 'ready' ? self::item_public_url($item) : '';
        if ($url === '') {
            return new WP_Error('gallery_public_url_failed', '图片已上传，但未能获得游先锋图库公开链接。');
        }

        return array(
            'url'           => esc_url_raw($url),
            'attachment_id' => (int) ($item->attachment_id ?? 0),
            'name'          => (string) ($item->file_name ?? $file_name),
            'duplicate'     => !empty($result['duplicate']),
            'warning'       => (string) ($result['warning'] ?? ''),
        );
    }

    /**
     * @return array{item:object,duplicate:bool,warning:string}|WP_Error
     */
    private static function upload_local_image_file(string $tmp_name, string $file_name, int $file_size) {
        $file_name = sanitize_file_name($file_name);
        $type = wp_check_filetype_and_ext($tmp_name, $file_name);
        if (empty($type['type']) || strpos((string) $type['type'], 'image/') !== 0) {
            return new WP_Error('not_image', '仅支持上传图片文件。');
        }
        $max_size = (int) apply_filters('yxf_gallery_max_upload_size', 1024 * MB_IN_BYTES);
        if ($file_size > $max_size) {
            return new WP_Error('too_large', '单个图片文件不能超过 1GB。');
        }
        $file_hash = hash_file('sha256', $tmp_name);
        if (!$file_hash) {
            return new WP_Error('hash_failed', '无法识别图片内容，请重新选择图片。');
        }
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE author_id = %d AND file_hash = %s ORDER BY id DESC LIMIT 1',
            get_current_user_id(),
            $file_hash
        ));
        if ($existing) {
            if ((string) $existing->status === 'ready' && self::media_replacement_enabled()) {
                $attachment_id = self::ensure_virtual_attachment($existing);
                if ($attachment_id) {
                    $existing->attachment_id = $attachment_id;
                }
            }
            return array('item' => $existing, 'duplicate' => true);
        }
        $storage_owner_id = self::effective_account_owner_id();
        list($settings, $password) = self::storage_settings_for_user($storage_owner_id);
        if ($password === '') {
            return new WP_Error('login_required', '请先登录游先锋邮箱，或请管理员为你的用户角色配置默认图库账号。');
        }
        $remote_file = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '-' . $file_name;
        $uploaded = self::storage_upload($settings, $password, $tmp_name, $remote_file);
        $upload_warning = '';
        if (is_wp_error($uploaded)) {
            // 上传连接可能在文件已写入后才超时或断开。先到邮箱网盘确认，
            // 已存在则按成功处理，避免队列误报失败和重复上传。
            $original_url = self::create_public_link($settings, $password, $remote_file);
            if (is_wp_error($original_url)) {
                return $uploaded;
            }
            $upload_warning = '上传连接未返回完成状态，但已确认文件已保存到游先锋邮箱。';
        } else {
            $original_url = self::create_public_link($settings, $password, $remote_file);
        }
        $is_ready = !is_wp_error($original_url);
        $wpdb->insert(self::table_name(), array(
            'original_url' => $is_ready ? $original_url : '',
            'output_url'   => $is_ready ? self::rewrite_url($original_url) : '',
            'file_name'    => $file_name,
            'mime_type'    => $type['type'],
            'remote_path'  => rtrim($settings['sftp_remote_path'], '/') . '/' . $remote_file,
            'file_hash'    => $file_hash,
            'file_size'    => max(0, $file_size),
            'status'       => $is_ready ? 'ready' : 'pending',
            'author_id'    => get_current_user_id(),
            'storage_owner_id' => $storage_owner_id,
            'created_at'   => current_time('mysql'),
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'));
        if (!$wpdb->insert_id) {
            return new WP_Error('record_failed', '图片已上传，但图库记录保存失败，请不要重复上传并联系管理员。');
        }
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $wpdb->insert_id));
        if ($is_ready && self::media_replacement_enabled() && $item) {
            $attachment_id = self::ensure_virtual_attachment($item);
            if ($attachment_id) {
                $item->attachment_id = $attachment_id;
            }
        }
        return array(
            'item'      => $item,
            'duplicate' => false,
            'warning'   => $upload_warning ?: ($is_ready ? '' : $original_url->get_error_message()),
        );
    }

    /** 图片上传队列接口：每次仅处理一个队列项，便于准确显示状态和失败原因。 */
    public static function ajax_upload_image() {
        if (!self::can_use_gallery()) {
            wp_send_json_error(array('message' => '无权上传图库图片。'), 403);
        }
        check_ajax_referer('yxf_gallery_upload', 'nonce');
        $result = self::upload_gallery_file($_FILES['gallery_file'] ?? null);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        $item = $result['item'];
        wp_send_json_success(array(
            'id'        => (int) ($item->id ?? 0),
            'attachmentId' => (int) ($item->attachment_id ?? 0),
            'name'      => (string) ($item->file_name ?? ''),
            'url'       => $item && $item->status === 'ready' ? self::item_public_url($item) : '',
            'mime'      => (string) ($item->mime_type ?? ''),
            'kind'      => strtok((string) ($item->mime_type ?? ''), '/'),
            'createdAt' => (string) ($item->created_at ?? current_time('mysql')),
            'duplicate' => !empty($result['duplicate']),
            'warning'   => (string) ($result['warning'] ?? ''),
        ));
    }

    /** 每个请求只删除一张图片，批量操作由浏览器顺序发起，避免超时中断整个后台页面。 */
    public static function ajax_delete_remote_item() {
        if (!self::can_administer()) {
            wp_send_json_error(array('message' => '无权删除邮箱网盘中的图片。'), 403);
        }
        check_ajax_referer('yxf_gallery_delete_remote', 'nonce');
        $item_id = absint($_POST['item_id'] ?? 0);
        if (!$item_id) {
            wp_send_json_error(array('message' => '未找到需要删除的图片。'), 400);
        }
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $item_id));
        if (!$item) {
            wp_send_json_error(array('message' => '该图片已不存在。'), 404);
        }
        $result = self::delete_item_with_remote_file($item);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        wp_send_json_success(array('id' => $item_id, 'name' => (string) ($item->file_name ?: ('图片 #' . $item_id))));
    }

    private static function redirect($page, $notice) {
        if (sanitize_key(wp_unslash($_REQUEST['yxf_gallery_context'] ?? '')) === 'iframe') {
            $tab = $notice === 'uploaded' ? 'library' : 'upload';
            $args = array(
                'type'                   => 'yxf_gallery',
                'yxf_gallery_tab'        => $tab,
                'yxf_gallery_notice'     => $notice,
                'yxf_gallery_context'    => 'iframe',
            );
            $post_id = absint($_REQUEST['post_id'] ?? 0);
            if ($post_id) {
                $args['post_id'] = $post_id;
            }
            $callback = sanitize_key(wp_unslash($_REQUEST['yxf_gallery_callback'] ?? ''));
            if ($callback) {
                $args['yxf_gallery_callback'] = $callback;
            }
            $multiple = absint($_REQUEST['yxf_gallery_multiple'] ?? 1);
            if ($multiple) {
                $args['yxf_gallery_multiple'] = $multiple;
            }
            $raw_media_type = $_REQUEST['yxf_gallery_type'] ?? '';
            $media_type = is_string($raw_media_type) ? sanitize_key(wp_unslash($raw_media_type)) : '';
            if ($media_type) {
                $args['yxf_gallery_type'] = $media_type;
            }
            wp_safe_redirect(add_query_arg($args, admin_url('media-upload.php')));
            exit;
        }
        wp_safe_redirect(add_query_arg(array('page' => $page, 'yxf_gallery_notice' => $notice), admin_url('admin.php')));
        exit;
    }

    /** 仅改写域名部分，文件路径、查询参数与片段保持不变。 */
    public static function rewrite_url($url) {
        $settings = self::settings();
        if (empty($settings['rewrite_enabled'])) {
            return $url;
        }
        $source = strtolower($settings['source_host']);
        $from = wp_parse_url($url);
        $to = wp_parse_url($settings['target_base']);
        if (empty($from['host']) || empty($to['host']) || strtolower($from['host']) !== $source) {
            return $url;
        }
        $target_path = isset($to['path']) ? rtrim($to['path'], '/') : '';
        $file_path = isset($from['path']) ? $from['path'] : '/';
        $result = (isset($to['scheme']) ? $to['scheme'] : (isset($from['scheme']) ? $from['scheme'] : 'https')) . '://' . $to['host'];
        if (!empty($to['port'])) {
            $result .= ':' . absint($to['port']);
        }
        $result .= $target_path . $file_path;
        if (isset($from['query'])) {
            $result .= '?' . $from['query'];
        }
        if (isset($from['fragment'])) {
            $result .= '#' . $from['fragment'];
        }
        return $result;
    }

    /** 预览、复制和编辑器插入始终使用当前域名规则，历史记录无需重新上传。 */
    private static function item_public_url($item) {
        $original_url = is_object($item) ? (string) ($item->original_url ?? '') : (string) ($item['original_url'] ?? '');
        $output_url = is_object($item) ? (string) ($item->output_url ?? '') : (string) ($item['output_url'] ?? '');
        return $original_url !== '' ? self::rewrite_url($original_url) : $output_url;
    }

    /**
     * 为外链媒体建立一个没有本地文件的媒体记录。这样特色图、主题设置等依赖媒体 ID 的原功能
     * 仍可正常选择媒体，而网站 uploads 目录不会保存原文件。
     */
    private static function ensure_virtual_attachment($item) {
        if (!self::media_replacement_enabled() || (string) ($item->status ?? '') !== 'ready') {
            return 0;
        }
        $attachment_id = absint($item->attachment_id ?? 0);
        if ($attachment_id && get_post_type($attachment_id) === 'attachment') {
            return $attachment_id;
        }
        $url = self::item_public_url($item);
        if (!$url) {
            return 0;
        }
        $existing = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_key' => '_yxf_gallery_item_id',
            'meta_value' => (int) $item->id,
        ));
        $attachment_id = !empty($existing) ? (int) $existing[0] : 0;
        if (!$attachment_id) {
            $attachment_id = wp_insert_attachment(array(
                'post_title'     => sanitize_text_field((string) ($item->file_name ?: '游先锋图库图片')),
                'post_mime_type' => sanitize_text_field((string) ($item->mime_type ?: 'application/octet-stream')),
                'post_status'    => 'inherit',
                'post_author'    => (int) $item->author_id,
                'guid'           => $url,
            ));
            if (is_wp_error($attachment_id) || !$attachment_id) {
                return 0;
            }
            add_post_meta($attachment_id, '_yxf_gallery_item_id', (int) $item->id, true);
        }
        update_post_meta($attachment_id, '_yxf_gallery_external_url', $url);
        global $wpdb;
        $wpdb->update(self::table_name(), array('attachment_id' => $attachment_id), array('id' => (int) $item->id), array('%d'), array('%d'));
        return (int) $attachment_id;
    }

    private static function inspect_image_url($url) {
        if ($url === '') {
            return new WP_Error('empty_url', '请填写图片直链。');
        }
        $response = wp_remote_get($url, array(
            'timeout'             => 20,
            'redirection'         => 3,
            'limit_response_size' => 32768,
            'headers'             => array('Range' => 'bytes=0-31'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('unavailable_url', '图片链接无法访问。');
        }
        $headers = wp_remote_retrieve_headers($response);
        $type = isset($headers['content-type']) ? strtolower(trim(strtok($headers['content-type'], ';'))) : '';
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || strpos($type, 'image/') !== 0) {
            return new WP_Error('not_image', '该链接不是可直接访问的图片文件。');
        }
        return array('mime_type' => $type);
    }

    private static function filename_from_url($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $name = $path ? wp_basename($path) : '';
        return sanitize_file_name($name ?: 'external-image');
    }

    private static function notices() {
        $notice = sanitize_key($_GET['yxf_gallery_notice'] ?? '');
        $messages = array(
            'settings_saved' => array('success', '图库设置已保存。新增和重新导入的链接将按当前规则输出。'),
            'settings_error' => array('error', '请填写有效的原始域名和自定义媒体域名。'),
            'uploaded'       => array('success', '媒体已上传到游先锋邮箱，并已生成公开链接。'),
            'upload_error'   => array('error', '请选择一个媒体文件后再上传。'),
            'not_allowed'    => array('error', '该文件类型不被 WordPress 允许上传。'),
            'too_large'      => array('error', '单个媒体文件不能超过 1GB。'),
            'storage_unconfigured' => array('error', '请先在“登录”中保存自己的游先锋邮箱账号。'),
            'login_required' => array('error', '请先在“登录”中保存自己的游先锋邮箱账号和密码。'),
            'login_saved' => array('success', '你的游先锋邮箱登录信息已保存。'),
            'removed'        => array('success', '图片已移出图库，邮箱网盘中的原文件已保留。'),
            'deleted'        => array('success', '图片及邮箱网盘中的原文件已彻底删除。'),
        );
        if (isset($messages[$notice])) {
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
        }
        if ($notice === 'stored_notice') {
            $stored = get_transient('yxf_gallery_notice_' . get_current_user_id());
            delete_transient('yxf_gallery_notice_' . get_current_user_id());
            if (is_array($stored) && isset($stored[0], $stored[1])) {
                printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($stored[0]), esc_html($stored[1]));
            }
        }
    }

    private static function file_size_label($bytes) {
        $bytes = max(0, (int) $bytes);
        return $bytes > 0 ? size_format($bytes, 1) : '—';
    }

    private static function render_copy_script() {
        ?>
        <style>
            .yxf-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;max-width:1080px}.yxf-gallery-card{position:relative;min-width:0;width:auto!important;margin:0!important;padding:10px;overflow:visible}.yxf-gallery-thumb-wrap{position:relative;height:110px;overflow:hidden;background:#f0f0f1}.yxf-gallery-thumb,.yxf-gallery-file{display:block;width:100%;height:110px;box-sizing:border-box;object-fit:cover;background:#f0f0f1}.yxf-gallery-file{display:flex;align-items:center;justify-content:center;color:#2271b1;text-align:center;text-decoration:none}.yxf-gallery-thumb-badges{position:absolute;inset:0;pointer-events:none}.yxf-gallery-thumb-badge{position:absolute;bottom:7px;overflow:hidden;padding:3px 7px;border-radius:999px;background:rgba(0,0,0,.45);color:#fff;font-size:8px;line-height:1.35;white-space:nowrap;text-overflow:ellipsis}.yxf-gallery-thumb-badge:first-child{left:7px}.yxf-gallery-thumb-badge:last-child{right:7px;max-width:calc(100% - 14px)}.yxf-gallery-pending{color:#646970}.yxf-gallery-file-name{margin:10px 0 3px;word-break:break-all}.yxf-gallery-card-meta{margin:0;color:#787c82;font-size:12px;line-height:1.45}.yxf-gallery-card-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}.yxf-gallery-delete-trigger{display:flex;align-items:center;justify-content:center;width:20px;height:20px;padding:0;border:0;background:transparent;cursor:pointer}.yxf-gallery-delete-trigger svg{display:block;width:16px;height:16px;fill:#bfbfbf}.yxf-gallery-delete-trigger:hover svg,.yxf-gallery-delete-trigger:focus svg{fill:#b32d2e}.yxf-gallery-copy-button{position:relative;padding:0;border:0;background:transparent;color:#2271b1;text-decoration:none;cursor:pointer}.yxf-gallery-copy-button:hover,.yxf-gallery-copy-button:focus{color:#135e96;text-decoration:none}.yxf-gallery-copy-button:after{content:attr(data-link);position:absolute;z-index:20;right:0;bottom:calc(100% + 8px);display:none;width:240px;max-width:calc(100vw - 40px);padding:7px 9px;border-radius:3px;background:rgba(0,0,0,.78);color:#fff;font-size:12px;font-weight:400;line-height:1.45;text-align:left;white-space:normal;word-break:break-all;box-shadow:0 2px 8px rgba(0,0,0,.2);pointer-events:none}.yxf-gallery-copy-button:hover:after,.yxf-gallery-copy-button:focus:after{display:block}.yxf-gallery-limit-note{max-width:1080px;margin:18px 0 0;padding:10px 12px;border-left:3px solid #72aee6;background:#f6f7f7;color:#50575e}.yxf-gallery-wrap #yxf-gallery-manage-form .yxf-gallery-limit-note{max-width:1280px}.yxf-gallery-delete-dialog{position:fixed;z-index:99999;inset:0}.yxf-gallery-delete-dialog-mask{position:absolute;inset:0;background:rgba(0,0,0,.32)}.yxf-gallery-delete-dialog-card{position:relative;z-index:1;width:min(360px,calc(100vw - 40px));margin:18vh auto 0;padding:20px;background:#fff;border-radius:4px;box-shadow:0 8px 24px rgba(0,0,0,.22)}.yxf-gallery-delete-dialog-card h2{margin:0 0 10px;font-size:18px}.yxf-gallery-delete-dialog-card p{margin:8px 0}.yxf-gallery-delete-dialog-card form{display:inline-block;margin:8px 8px 0 0}.yxf-gallery-delete-dialog-card .description{line-height:1.6}.yxf-gallery-delete-cancel{display:block;margin-top:12px}@media(max-width:782px){.yxf-gallery-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}}
        </style>
        <script>
        document.addEventListener('click', function(event) {
            var deleteTrigger = event.target.closest('.yxf-gallery-delete-trigger');
            if (deleteTrigger) {
                var dialog = deleteTrigger.closest('.yxf-gallery-card').querySelector('.yxf-gallery-delete-dialog');
                if (dialog) dialog.hidden = false;
                return;
            }
            if (event.target.closest('[data-yxf-close-delete]')) {
                var closeDialog = event.target.closest('.yxf-gallery-delete-dialog');
                if (closeDialog) closeDialog.hidden = true;
                return;
            }
            var button = event.target.closest('.yxf-copy-link');
            if (!button) return;
            var value = button.getAttribute('data-copy-url') || '';
            var done = function() { var original = button.textContent; button.textContent = '已复制'; window.setTimeout(function() { button.textContent = original; }, 1500); };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(value).then(done); return; }
            var input = document.createElement('textarea'); input.value = value; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove(); done();
        });
        document.addEventListener('keydown', function(event) { if (event.key !== 'Escape') return; document.querySelectorAll('.yxf-gallery-delete-dialog').forEach(function(item) { item.hidden = true; }); });
        </script>
        <?php
    }

    public static function render_gallery_page() {
        if (!self::can_use_gallery()) {
            wp_die('无权访问图库。');
        }
        global $wpdb;
        $item_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table_name() . " WHERE author_id = %d", get_current_user_id()));
        $per_page = 100;
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $total_pages = max(1, (int) ceil($item_total / $per_page));
        $current_page = min($current_page, $total_pages);
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE author_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", get_current_user_id(), $per_page, ($current_page - 1) * $per_page));
        $account = self::account();
        $using_shared_account = self::is_using_shared_account();
        ?>
        <div class="wrap yxf-gallery-wrap">
            <h1>我的媒体库</h1>
            <?php self::notices(); ?>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-upload')); ?>">上传图片</a> <?php if (self::user_has_login()) : ?><?php if ($using_shared_account) : ?><span style="color:#00a32a;font-weight:600">正在使用管理员配置的默认图库账号</span><?php else : ?><a class="button-link" style="color:#00a32a;font-weight:600;text-decoration:none" href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-login')); ?>">已登录：<?php echo esc_html($account['username']); ?></a><?php endif; ?><?php else : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-login')); ?>">登录游先锋邮箱</a><?php endif; ?></p>
            <p class="description">这里仅显示你上传的媒体文件；使用默认图库账号时，文件实际保存到管理员指定的共享网盘。</p>
            <?php if (!$items) : ?>
                <div class="notice notice-info inline"><p>图库暂无媒体文件。请先上传一个文件。</p></div>
            <?php else : ?>
                <div class="yxf-gallery-grid">
                    <?php foreach ($items as $item) : ?>
                        <?php $item_url = $item->status === 'ready' ? self::item_public_url($item) : ''; ?>
                        <article class="card yxf-gallery-card">
                            <div class="yxf-gallery-thumb-wrap">
                                <?php if ($item->status === 'ready' && strpos((string) $item->mime_type, 'image/') === 0) : ?><img class="yxf-gallery-thumb" src="<?php echo esc_url($item_url); ?>" alt="" loading="lazy" decoding="async"><?php elseif ($item->status === 'ready') : ?><a class="yxf-gallery-file" href="<?php echo esc_url($item_url); ?>" target="_blank" rel="noopener">媒体文件<br><?php echo esc_html(strtoupper((string) $item->mime_type)); ?></a><?php else : ?><div class="yxf-gallery-file yxf-gallery-pending">等待公开链接</div><?php endif; ?>
                                <div class="yxf-gallery-thumb-badges"><span class="yxf-gallery-thumb-badge"><?php echo esc_html(self::file_size_label($item->file_size ?? 0)); ?></span><span class="yxf-gallery-thumb-badge"><?php echo esc_html($item->mime_type ?: '—'); ?></span></div>
                            </div>
                            <p class="yxf-gallery-file-name"><strong><?php echo esc_html($item->file_name ?: '图片'); ?></strong></p>
                            <p class="yxf-gallery-card-meta"><?php echo esc_html(mysql2date('Y-m-d H:i', (string) $item->created_at)); ?></p>
                            <div class="yxf-gallery-card-actions">
                                <button class="yxf-gallery-delete-trigger" type="button" title="删除文件" aria-label="删除文件"><svg viewBox="0 0 1024 1024" aria-hidden="true"><path d="M256 333.872a28.8 28.8 0 0 1 28.8 28.8V768a56.528 56.528 0 0 0 56.544 56.528h341.328A56.528 56.528 0 0 0 739.2 768V362.672a28.8 28.8 0 0 1 57.6 0V768a114.128 114.128 0 0 1-114.128 114.128H341.328A114.128 114.128 0 0 1 227.2 768V362.672a28.8 28.8 0 0 1 28.8-28.8zM405.344 269.648a28.8 28.8 0 0 0 28.8-28.8 56.528 56.528 0 0 1 56.528-56.544h42.656a56.528 56.528 0 0 1 56.544 56.544 28.8 28.8 0 0 0 57.6 0 114.128 114.128 0 0 0-112.64-114.128h-45.648a114.144 114.144 0 0 0-112.64 114.128 28.8 28.8 0 0 0 28.8 28.8zM163.2 266.672a28.8 28.8 0 0 1 28.8-28.8h640a28.8 28.8 0 0 1 0 57.6H192a28.8 28.8 0 0 1-28.8-28.8z"/><path d="M426.672 371.2a28.8 28.8 0 0 1 28.8 28.8v320a28.8 28.8 0 0 1-57.6 0V400a28.8 28.8 0 0 1 28.8-28.8zM597.344 371.2a28.8 28.8 0 0 1 28.8 28.8v320a28.8 28.8 0 0 1-57.6 0V400a28.8 28.8 0 0 1 28.8-28.8z"/></svg></button>
                                <?php if ($item->status === 'ready') : ?><button class="yxf-gallery-copy-button yxf-copy-link" type="button" data-copy-url="<?php echo esc_attr($item_url); ?>" data-link="<?php echo esc_attr($item_url); ?>">复制链接</button><?php endif; ?>
                            </div>
                            <div class="yxf-gallery-delete-dialog" hidden role="dialog" aria-modal="true" aria-label="删除文件">
                                <div class="yxf-gallery-delete-dialog-mask" data-yxf-close-delete></div>
                                <div class="yxf-gallery-delete-dialog-card">
                                    <h2>删除文件</h2>
                                    <p>请选择删除方式：</p>
                                    <form method="post"><?php wp_nonce_field('yxf_gallery_delete'); ?><input type="hidden" name="yxf_gallery_action" value="remove_item"><input type="hidden" name="item_id" value="<?php echo absint($item->id); ?>"><button class="button" type="submit">仅移出图库列表</button></form>
                                    <form method="post"><?php wp_nonce_field('yxf_gallery_delete'); ?><input type="hidden" name="yxf_gallery_action" value="delete_item"><input type="hidden" name="item_id" value="<?php echo absint($item->id); ?>"><button class="button button-link-delete" type="submit">彻底删除</button></form>
                                    <p class="description">仅移出图库列表会保留邮箱网盘文件；彻底删除会同时删除邮箱网盘中的文件，且无法恢复。</p>
                                    <button class="button-link yxf-gallery-delete-cancel" type="button" data-yxf-close-delete>取消</button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($total_pages > 1) : ?><div class="tablenav yxf-gallery-pagination"><div class="tablenav-pages"><?php echo paginate_links(array('base' => add_query_arg('paged', '%#%', admin_url('admin.php?page=yxf-gallery')), 'format' => '', 'current' => $current_page, 'total' => $total_pages, 'prev_text' => '‹', 'next_text' => '›')); ?></div></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php self::render_copy_script();
    }

    public static function render_upload_page() {
        if (!self::can_use_gallery()) {
            wp_die('无权上传图库图片。');
        }
        ?>
        <div class="wrap">
            <h1>上传图片</h1>
            <?php self::notices(); ?>
            <?php if (!self::user_has_login()) : ?><div class="notice notice-warning inline"><p>请先在“登录”中填写自己的游先锋邮箱账号，或请管理员为你的用户角色配置默认图库账号。<a href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-login')); ?>">去登录</a></p></div><?php endif; ?>
            <div class="card yxf-upload-card" style="max-width:900px;padding:22px;margin-top:18px">
                <p>选择图片后会先进入上传队列。每张相同图片只会上传一次，可继续选择更多图片加入队列。</p>
                <input id="yxf-gallery-files" type="file" accept="image/*" multiple class="screen-reader-text" <?php disabled(!self::user_has_login()); ?>>
                <p class="yxf-upload-actions"><button type="button" class="button" id="yxf-gallery-choose" <?php disabled(!self::user_has_login()); ?>>选择图片</button> <button type="button" class="button button-primary" id="yxf-gallery-start" disabled>开始上传</button></p>
                <ul class="yxf-upload-queue" id="yxf-gallery-queue" aria-live="polite"></ul>
                <div class="yxf-upload-links" id="yxf-gallery-links" aria-live="polite"></div>
            </div>
        </div>
        <style>
            .yxf-upload-actions{display:flex;gap:8px;align-items:center}.yxf-upload-queue{margin:20px 0 0;border-top:1px solid #dcdcde}.yxf-upload-queue:empty{display:none}.yxf-upload-item{display:flex;gap:12px;align-items:center;padding:12px 2px;border-bottom:1px solid #f0f0f1}.yxf-upload-item-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-upload-item-status{font-size:12px;color:#646970}.yxf-upload-item.is-uploading .yxf-upload-item-status{color:#2271b1}.yxf-upload-item.is-success .yxf-upload-item-status{color:#00a32a}.yxf-upload-item.is-error .yxf-upload-item-status{color:#d63638}.yxf-upload-item-remove{color:#b32d2e;border:0;background:none;cursor:pointer}.yxf-upload-links{margin-top:20px}.yxf-upload-links:empty{display:none}.yxf-upload-links-title{margin:0 0 8px;font-weight:600}.yxf-upload-link-row{display:flex;align-items:center;gap:8px;padding:10px 0;border-top:1px solid #f0f0f1}.yxf-upload-link-name{flex:0 0 150px;max-width:28%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-upload-link-url{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#2271b1;text-decoration:none}.yxf-upload-copy{flex:0 0 auto;padding:0;border:0;background:transparent;color:#2271b1;cursor:pointer}.yxf-upload-copy:hover{color:#135e96}.yxf-upload-copy:focus{outline:2px solid #72aee6;outline-offset:1px}@media(max-width:782px){.yxf-upload-link-row{flex-wrap:wrap}.yxf-upload-link-name{flex-basis:100%;max-width:100%}.yxf-upload-link-url{flex-basis:calc(100% - 64px)}}
        </style>
        <script>
        (function(){
            var input=document.getElementById('yxf-gallery-files'), choose=document.getElementById('yxf-gallery-choose'), start=document.getElementById('yxf-gallery-start'), list=document.getElementById('yxf-gallery-queue'), links=document.getElementById('yxf-gallery-links');
            if(!input||!choose||!start||!list||!links){return;}
            var queue=new Map(), uploading=false, ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode(wp_create_nonce('yxf_gallery_upload')); ?>;
            function key(file){return [file.name,file.size,file.lastModified].join(':');}
            function copy(url,button){var done=function(){button.textContent='已复制';window.setTimeout(function(){button.textContent='复制链接';},1500);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){window.prompt('请复制图片链接：',url);});}else{window.prompt('请复制图片链接：',url);}}
            function render(){list.innerHTML='';links.innerHTML='';var completed=[];queue.forEach(function(item,id){var row=document.createElement('li');row.className='yxf-upload-item is-'+item.state;row.innerHTML='<span class="yxf-upload-item-name"></span><span class="yxf-upload-item-status"></span>';row.querySelector('.yxf-upload-item-name').textContent=item.file.name;row.querySelector('.yxf-upload-item-status').textContent=item.message;if(item.state==='waiting'||item.state==='error'){var remove=document.createElement('button');remove.type='button';remove.className='yxf-upload-item-remove';remove.textContent='移除';remove.addEventListener('click',function(){queue.delete(id);render();});row.appendChild(remove);}if(item.state==='success'&&item.url){completed.push(item);}list.appendChild(row);});if(completed.length){var title=document.createElement('p');title.className='yxf-upload-links-title';title.textContent='图片外部链接';links.appendChild(title);completed.forEach(function(item){var row=document.createElement('div');row.className='yxf-upload-link-row';var name=document.createElement('strong');name.className='yxf-upload-link-name';name.title=item.file.name;name.textContent=item.file.name;var url=document.createElement('a');url.className='yxf-upload-link-url';url.href=item.url;url.target='_blank';url.rel='noopener';url.title=item.url;url.textContent=item.url;var copyButton=document.createElement('button');copyButton.type='button';copyButton.className='yxf-upload-copy';copyButton.textContent='复制链接';copyButton.addEventListener('click',function(){copy(item.url,copyButton);});row.append(name,url,copyButton);links.appendChild(row);});}start.disabled=uploading||![...queue.values()].some(function(item){return item.state==='waiting'||item.state==='error';});}
            function add(files){Array.prototype.forEach.call(files,function(file){if(!file.type.match(/^image\//)){return;}var id=key(file);if(!queue.has(id)){queue.set(id,{file:file,state:'waiting',message:'等待上传'});}});input.value='';render();}
            async function send(item){item.state='uploading';item.message='正在上传…';render();var data=new FormData();data.append('action','yxf_gallery_upload_image');data.append('nonce',nonce);data.append('gallery_file',item.file,item.file.name);try{var response=await fetch(ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}),raw=await response.text(),payload;try{payload=JSON.parse(raw);}catch(parseError){throw new Error('服务器未返回有效的上传结果，请重新登录游先锋邮箱后再试。');}if(!payload.success){throw new Error((payload.data&&payload.data.message)||'上传失败，请重试。');}item.url=(payload.data&&payload.data.url)||'';item.state='success';item.message=item.url?(payload.data.duplicate?'已存在，无需重复上传':'上传完成'):(payload.data.warning||'已上传，公开链接正在生成');}catch(error){item.state='error';item.message=error.message||'上传失败，请重试。';}render();}
            async function run(){if(uploading){return;}uploading=true;render();for(const item of queue.values()){if(item.state==='waiting'||item.state==='error'){await send(item);}}uploading=false;render();}
            choose.addEventListener('click',function(){input.click();});input.addEventListener('change',function(){add(input.files);});start.addEventListener('click',run);render();
        }());
        </script>
        <?php
    }

    public static function render_login_page() {
        if (!self::can_use_gallery()) {
            wp_die('无权访问登录。');
        }
        $account = self::account();
        ?>
        <div class="wrap">
            <h1>登录游先锋邮箱</h1>
            <?php self::notices(); ?>
            <div class="notice notice-info inline"><p><?php echo self::is_using_shared_account() ? '你当前可使用管理员配置的默认图库账号上传图片。你也可以填写自己的账号；保存后会优先使用自己的网盘。' : '账号信息只保存到你自己的用户资料中。若管理员为普通用户或贡献者配置了默认图库账号，符合条件的用户可使用该账号上传，但不会看到账号密码。'; ?></p></div>
            <form method="post" class="card" style="max-width:720px;padding:18px 22px;margin-top:18px">
                <?php wp_nonce_field('yxf_gallery_login'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="gallery_username">游先锋邮箱用户名</label></th><td><input id="gallery_username" name="gallery_username" class="regular-text" value="<?php echo esc_attr($account['username']); ?>" required></td></tr>
                    <tr><th scope="row"><label for="gallery_password">游先锋邮箱密码</label></th><td><input id="gallery_password" name="gallery_password" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $account['secret'] ? esc_attr('已保存；留空则不修改') : esc_attr('填写密码'); ?>"></td></tr>
                </table>
                <p class="submit"><button class="button button-primary" name="yxf_gallery_action" value="save_login">保存登录信息</button> <button class="button" name="yxf_gallery_action" value="test_login">测试连接</button></p>
            </form>
        </div>
        <?php
    }

    public static function render_manage_page() {
        if (!self::can_administer()) {
            wp_die('无权管理图片。');
        }
        global $wpdb;
        $filter_month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['yxf_gallery_month'] ?? '')) ? (string) $_GET['yxf_gallery_month'] : '';
        $filter_author = absint($_GET['yxf_gallery_author'] ?? 0);
        $filter_type = sanitize_text_field($_GET['yxf_gallery_type'] ?? '');
        $where = array('1=1');
        $where_args = array();
        if ($filter_month !== '') {
            $where[] = "DATE_FORMAT(i.created_at, '%%Y-%%m') = %s";
            $where_args[] = $filter_month;
        }
        if ($filter_author) {
            $where[] = 'i.author_id = %d';
            $where_args[] = $filter_author;
        }
        if ($filter_type !== '') {
            $where[] = 'i.mime_type = %s';
            $where_args[] = $filter_type;
        }
        $where_sql = implode(' AND ', $where);
        $base_from = " FROM " . self::table_name() . " i LEFT JOIN {$wpdb->users} u ON i.author_id = u.ID WHERE " . $where_sql;
        $count_sql = 'SELECT COUNT(*)' . $base_from;
        $item_total = (int) ($where_args ? $wpdb->get_var($wpdb->prepare($count_sql, $where_args)) : $wpdb->get_var($count_sql));
        $allowed_per_page = array(10, 20, 50, 100);
        $per_page = absint($_GET['yxf_gallery_per_page'] ?? 20);
        if (!in_array($per_page, $allowed_per_page, true)) {
            $per_page = 20;
        }
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $total_pages = max(1, (int) ceil($item_total / $per_page));
        $current_page = min($current_page, $total_pages);
        $items_sql = 'SELECT i.*, u.display_name, u.user_login' . $base_from . ' ORDER BY i.id DESC LIMIT %d OFFSET %d';
        $items = $wpdb->get_results($wpdb->prepare($items_sql, array_merge($where_args, array($per_page, ($current_page - 1) * $per_page))));
        $months = $wpdb->get_col("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') FROM " . self::table_name() . " WHERE created_at IS NOT NULL AND created_at <> '' ORDER BY created_at DESC");
        $authors = $wpdb->get_results("SELECT DISTINCT i.author_id, u.display_name, u.user_login FROM " . self::table_name() . " i LEFT JOIN {$wpdb->users} u ON i.author_id = u.ID ORDER BY u.display_name, u.user_login");
        $types = $wpdb->get_col("SELECT DISTINCT mime_type FROM " . self::table_name() . " WHERE mime_type IS NOT NULL AND mime_type <> '' ORDER BY mime_type");
        $pagination_filters = array_filter(array('yxf_gallery_month' => $filter_month, 'yxf_gallery_author' => $filter_author, 'yxf_gallery_type' => $filter_type, 'yxf_gallery_per_page' => $per_page));
        $render_pagination = static function () use ($total_pages, $current_page, $item_total, $pagination_filters) {
            ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($item_total); ?> 个项目</span>
                <?php echo paginate_links(array('base' => add_query_arg(array_merge(array('page' => 'yxf-gallery-manage', 'paged' => '%#%'), $pagination_filters), admin_url('admin.php')), 'format' => '', 'current' => $current_page, 'total' => $total_pages, 'prev_text' => '‹', 'next_text' => '›')); ?>
            </div>
            <?php
        };
        ?>
        <div class="wrap yxf-gallery-wrap">
            <h1>管理图片</h1>
            <?php self::notices(); ?>
            <p class="description">这里可查看所有用户上传的图片。删除时会同时删除对应用户游先锋邮箱网盘中的原文件和网站图库记录。</p>
            <style>
                .yxf-gallery-manage-table-wrap{width:100%;overflow-x:auto}.yxf-gallery-manage-table{width:100%;min-width:980px}.yxf-gallery-manage-table th,.yxf-gallery-manage-table td{vertical-align:middle}.yxf-gallery-manage-table .check-column{width:2.2em}.yxf-manage-thumb{width:64px}.yxf-manage-thumb img{display:block;width:52px;height:52px;object-fit:cover;background:#f0f0f1}.yxf-manage-image-preview{display:block;padding:0;border:0;background:transparent;cursor:zoom-in}.yxf-manage-name{min-width:180px;word-break:break-word}.yxf-manage-file-size{white-space:nowrap}.yxf-manage-actions{width:130px;white-space:nowrap}.yxf-manage-actions button{margin:0 8px 0 0;padding:0;border:0;background:transparent;cursor:pointer}.yxf-manage-actions .button-link-delete{color:#b32d2e}.yxf-manage-actions .button-link-delete:hover{color:#8a2424}.yxf-manage-actions .button-link{color:#2271b1}.yxf-manage-actions .button-link:hover{color:#135e96}.yxf-gallery-manage-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px}.yxf-gallery-manage-topbar .alignleft{display:flex;align-items:center;gap:6px;min-width:0}.yxf-gallery-manage-filter{display:flex;align-items:center;gap:6px;margin:0 0 0 8px}.yxf-gallery-manage-filter select[name="yxf_gallery_month"]{width:108px}.yxf-gallery-manage-filter select[name="yxf_gallery_author"]{width:132px}.yxf-gallery-manage-filter select[name="yxf_gallery_type"]{width:106px}.yxf-gallery-manage-filter select[name="yxf_gallery_per_page"]{width:86px}.yxf-gallery-manage-topbar .tablenav-pages{margin-left:auto;white-space:nowrap}.yxf-gallery-manage-bottom{margin-top:12px}.yxf-gallery-manage-bottom .alignleft{display:flex;align-items:center;gap:6px}.yxf-gallery-manage-topbar .tablenav-pages,.yxf-gallery-manage-bottom .tablenav-pages{margin-left:auto}.yxf-manage-preview-dialog{position:fixed;z-index:100000;inset:0}.yxf-manage-preview-mask{position:absolute;inset:0;background:rgba(0,0,0,.7)}.yxf-manage-preview-card{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;width:min(960px,calc(100vw - 48px));max-height:calc(100vh - 48px);margin:24px auto;padding:16px;background:#fff;border-radius:4px;box-shadow:0 8px 30px rgba(0,0,0,.35)}.yxf-manage-preview-card img{display:block;max-width:100%;max-height:calc(100vh - 118px);object-fit:contain}.yxf-manage-preview-card p{margin:10px 36px 0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-manage-preview-close{position:absolute;top:7px;right:9px;width:28px;height:28px;padding:0;border:0;background:transparent;color:#50575e;font-size:26px;line-height:28px;cursor:pointer}.yxf-manage-preview-close:hover{color:#1d2327}
            </style>
            <?php if (!$items) : ?><div class="notice notice-info inline"><p>暂无用户上传图片。</p></div><?php else : ?>
                <div class="tablenav top yxf-gallery-manage-topbar">
                    <div class="alignleft actions">
                        <select class="yxf-gallery-bulk-action"><option value="">批量操作</option><option value="delete">彻底删除</option></select><button type="button" class="button action yxf-gallery-delete-selected">应用</button><span class="description yxf-gallery-selected-count">未选择图片</span>
                        <form method="get" class="yxf-gallery-manage-filter"><input type="hidden" name="page" value="yxf-gallery-manage"><select name="yxf_gallery_month"><option value="">全部月份</option><?php foreach ($months as $month) : ?><option value="<?php echo esc_attr($month); ?>" <?php selected($filter_month, $month); ?>><?php echo esc_html($month); ?></option><?php endforeach; ?></select><select name="yxf_gallery_author"><option value="0">全部上传者</option><?php foreach ($authors as $author) : ?><option value="<?php echo absint($author->author_id); ?>" <?php selected($filter_author, (int) $author->author_id); ?>><?php echo esc_html($author->display_name ?: $author->user_login ?: ('用户 #' . $author->author_id)); ?></option><?php endforeach; ?></select><select name="yxf_gallery_type"><option value="">全部类型</option><?php foreach ($types as $type) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($filter_type, $type); ?>><?php echo esc_html($type); ?></option><?php endforeach; ?></select><select name="yxf_gallery_per_page"><option value="10" <?php selected($per_page, 10); ?>>10 条/页</option><option value="20" <?php selected($per_page, 20); ?>>20 条/页</option><option value="50" <?php selected($per_page, 50); ?>>50 条/页</option><option value="100" <?php selected($per_page, 100); ?>>100 条/页</option></select><button class="button" type="submit">筛选</button></form>
                    </div>
                    <?php $render_pagination(); ?>
                </div>
                <form method="post" id="yxf-gallery-manage-form" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('yxf_gallery_delete_remote')); ?>">
                    <?php wp_nonce_field('yxf_gallery_delete_remote'); ?>
                    <input type="hidden" name="yxf_gallery_action" value="delete_items_remote">
                    <div class="yxf-gallery-manage-table-wrap">
                        <table class="widefat fixed striped yxf-gallery-manage-table">
                            <thead><tr><td class="check-column"><input class="yxf-gallery-select-all-table" type="checkbox" aria-label="全选图片"></td><th scope="col">缩略图</th><th scope="col">文件名</th><th scope="col">文件大小</th><th scope="col">文件类型</th><th scope="col">上传用户</th><th scope="col">上传时间</th><th scope="col">操作</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $item) : ?>
                                    <?php $item_url = $item->status === 'ready' ? self::item_public_url($item) : ''; ?>
                                    <tr class="yxf-gallery-manage-item" data-item-id="<?php echo absint($item->id); ?>">
                                        <th scope="row" class="check-column"><input class="yxf-gallery-item-check" type="checkbox" name="item_ids[]" value="<?php echo absint($item->id); ?>" aria-label="选择 <?php echo esc_attr($item->file_name ?: '图片'); ?>"></th>
                                        <td class="yxf-manage-thumb"><?php if ($item->status === 'ready' && strpos((string) $item->mime_type, 'image/') === 0) : ?><button class="yxf-manage-image-preview" type="button" data-image-url="<?php echo esc_attr($item_url); ?>" data-image-name="<?php echo esc_attr($item->file_name ?: '图片'); ?>"><img src="<?php echo esc_url($item_url); ?>" alt="<?php echo esc_attr($item->file_name ?: '图片'); ?>" loading="lazy" decoding="async"></button><?php elseif ($item->status === 'ready') : ?><a href="<?php echo esc_url($item_url); ?>" target="_blank" rel="noopener">文件</a><?php else : ?><span>待生成</span><?php endif; ?></td>
                                        <td class="yxf-manage-name"><strong><?php echo esc_html($item->file_name ?: '图片'); ?></strong></td>
                                        <td class="yxf-manage-file-size"><?php echo esc_html(self::file_size_label($item->file_size ?? 0)); ?></td>
                                        <td><code><?php echo esc_html($item->mime_type ?: '—'); ?></code></td>
                                        <td><?php echo esc_html($item->display_name ?: $item->user_login ?: ('用户 #' . $item->author_id)); ?></td>
                                        <td><?php echo esc_html($item->created_at ?: '—'); ?></td>
                                        <td class="yxf-manage-actions"><button type="button" class="button-link-delete yxf-manage-delete-item">删除</button><?php if ($item->status === 'ready') : ?> <button type="button" class="button-link yxf-copy-link" data-copy-url="<?php echo esc_attr($item_url); ?>">复制链接</button><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr><td class="check-column"><input class="yxf-gallery-select-all-table" type="checkbox" aria-label="全选图片"></td><th scope="col">缩略图</th><th scope="col">文件名</th><th scope="col">文件大小</th><th scope="col">文件类型</th><th scope="col">上传用户</th><th scope="col">上传时间</th><th scope="col">操作</th></tr></tfoot>
                        </table>
                    </div>
                </form>
                <div class="tablenav bottom yxf-gallery-manage-topbar yxf-gallery-manage-bottom">
                    <div class="alignleft actions"><select class="yxf-gallery-bulk-action"><option value="">批量操作</option><option value="delete">彻底删除</option></select><button type="button" class="button action yxf-gallery-delete-selected">应用</button><span class="description yxf-gallery-selected-count">未选择图片</span></div>
                    <?php $render_pagination(); ?>
                </div>
                <div class="yxf-manage-preview-dialog" id="yxf-manage-preview-dialog" hidden role="dialog" aria-modal="true" aria-label="图片预览"><div class="yxf-manage-preview-mask" data-yxf-close-preview></div><div class="yxf-manage-preview-card"><button class="yxf-manage-preview-close" type="button" data-yxf-close-preview aria-label="关闭预览">×</button><img src="" alt="" id="yxf-manage-preview-image"><p id="yxf-manage-preview-name"></p></div></div>
                <script>
                (function(){
                    var form=document.getElementById('yxf-gallery-manage-form'),tableAlls=[].slice.call(document.querySelectorAll('.yxf-gallery-select-all-table')),bulks=[].slice.call(document.querySelectorAll('.yxf-gallery-bulk-action')),removes=[].slice.call(document.querySelectorAll('.yxf-gallery-delete-selected')),counts=[].slice.call(document.querySelectorAll('.yxf-gallery-selected-count'));
                    if(!form||!tableAlls.length||!bulks.length||!removes.length||!counts.length)return;
                    var checks=function(){return [].slice.call(document.querySelectorAll('.yxf-gallery-item-check'));};
                    var refresh=function(){var list=checks(),selected=list.filter(function(check){return check.checked;}).length;counts.forEach(function(count){count.textContent=selected?'已选择 '+selected+' 张图片':'未选择图片';});tableAlls.forEach(function(tableAll){tableAll.checked=!!list.length&&selected===list.length;tableAll.indeterminate=selected>0&&selected<list.length;});};
                    var chooseAll=function(shouldSelect){checks().forEach(function(check){check.checked=shouldSelect;});refresh();};
                    var deleteItems=async function(selected,button){removes.forEach(function(item){item.disabled=true;});tableAlls.forEach(function(item){item.disabled=true;});bulks.forEach(function(item){item.disabled=true;});var failed=[];for(var index=0;index<selected.length;index++){var check=selected[index],row=check.closest('.yxf-gallery-manage-item'),data=new FormData();data.append('action','yxf_gallery_delete_remote_item');data.append('nonce',form.getAttribute('data-nonce'));data.append('item_id',check.value);removes.forEach(function(item){item.textContent='正在删除 '+(index+1)+'/'+selected.length;});row.style.opacity='.55';try{var response=await fetch(form.getAttribute('data-ajax-url'),{method:'POST',body:data,credentials:'same-origin'}),payload=await response.json();if(!payload.success)throw new Error((payload.data&&payload.data.message)||'删除失败');row.remove();}catch(error){row.style.opacity='1';failed.push((row.querySelector('.yxf-manage-name')||{}).textContent||('图片 #'+check.value));}}removes.forEach(function(item){item.textContent='应用';item.disabled=false;});tableAlls.forEach(function(item){item.disabled=false;});bulks.forEach(function(item){item.disabled=false;});refresh();if(failed.length){window.alert('以下图片未删除：'+failed.join('、'));}else{window.alert('已删除 '+selected.length+' 张图片及其邮箱网盘文件。');}};
                    tableAlls.forEach(function(tableAll){tableAll.addEventListener('change',function(){chooseAll(tableAll.checked);});});
                    document.addEventListener('change',function(event){if(event.target.classList.contains('yxf-gallery-item-check'))refresh();});
                    form.addEventListener('submit',function(event){event.preventDefault();});
                    removes.forEach(function(remove,index){remove.addEventListener('click',function(){var selected=checks().filter(function(check){return check.checked;});if(bulks[index].value!=='delete'){window.alert('请先选择批量操作方式。');return;}if(!selected.length){window.alert('请先选择需要操作的图片。');return;}if(window.confirm('将永久删除所选图片的邮箱网盘原文件和网站图库记录，无法恢复。确定继续吗？'))deleteItems(selected,remove);});});
                    form.addEventListener('click',function(event){var preview=event.target.closest('.yxf-manage-image-preview');if(preview){var dialog=document.getElementById('yxf-manage-preview-dialog'),image=document.getElementById('yxf-manage-preview-image'),name=document.getElementById('yxf-manage-preview-name');if(dialog&&image&&name){image.src=preview.getAttribute('data-image-url')||'';image.alt=preview.getAttribute('data-image-name')||'';name.textContent=preview.getAttribute('data-image-name')||'';dialog.hidden=false;}return;}var button=event.target.closest('.yxf-manage-delete-item');if(!button)return;var check=button.closest('.yxf-gallery-manage-item').querySelector('.yxf-gallery-item-check');if(check&&window.confirm('将永久删除此图片的邮箱网盘原文件和网站图库记录，无法恢复。确定继续吗？'))deleteItems([check],button);});
                    document.addEventListener('click',function(event){if(!event.target.closest('[data-yxf-close-preview]'))return;var dialog=document.getElementById('yxf-manage-preview-dialog');if(dialog)dialog.hidden=true;});
                    document.addEventListener('keydown',function(event){if(event.key==='Escape'){var dialog=document.getElementById('yxf-manage-preview-dialog');if(dialog)dialog.hidden=true;}});
                    refresh();
                }());
                </script>
            <?php endif; ?>
        </div>
        <?php self::render_copy_script();
    }

    public static function render_settings_page() {
        if (!self::can_administer()) {
            wp_die('无权访问图库设置。');
        }
        $settings = self::settings();
        $shared_account_users = array();
        foreach (get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $user) {
            if (self::user_has_own_login((int) $user->ID)) {
                $shared_account_users[] = $user;
            }
        }
        ?>
        <div class="wrap">
            <h1>游先锋图库设置</h1>
            <?php self::notices(); ?>
            <form method="post">
                <?php wp_nonce_field('yxf_gallery_settings'); ?>
                <input type="hidden" name="yxf_gallery_action" value="save_settings">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">替换媒体库</th>
                        <td><label><input type="checkbox" name="replace_media_library" value="1" <?php checked(!empty($settings['replace_media_library'])); ?>> 启用游先锋图库替换媒体库</label><p class="description">启用后，WordPress、子比主题和使用标准媒体接口的日主题，都会将图片、视频、音频和普通附件的选择、上传入口改为游先锋图库；原文件保存在用户自己的网盘，或管理员指定的默认图库账号中。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="shared_account_user_id">前台默认图库账号</label></th>
                        <td>
                            <select id="shared_account_user_id" name="shared_account_user_id">
                                <option value="0">不启用（用户需自行登录）</option>
                                <?php foreach ($shared_account_users as $user) : ?><option value="<?php echo absint($user->ID); ?>" <?php selected((int) $settings['shared_account_user_id'], (int) $user->ID); ?>><?php echo esc_html($user->display_name ?: $user->user_login); ?>（站内账号：<?php echo esc_html($user->user_login); ?>）</option><?php endforeach; ?>
                            </select>
                            <?php if (!$shared_account_users) : ?><p class="description" style="color:#b32d2e">暂无可选账号。请先让一个站内用户在“游先锋图库 → 登录”中保存并测试自己的邮箱登录信息。</p><?php endif; ?>
                            <p style="margin:10px 0 4px"><strong>允许使用该账号的用户角色</strong></p>
                            <label style="margin-right:16px"><input type="checkbox" name="shared_account_roles[]" value="subscriber" <?php checked(in_array('subscriber', (array) $settings['shared_account_roles'], true)); ?>> 普通用户</label>
                            <label><input type="checkbox" name="shared_account_roles[]" value="contributor" <?php checked(in_array('contributor', (array) $settings['shared_account_roles'], true)); ?>> 贡献者</label>
                            <p class="description">只有未设置个人邮箱账号、且角色被勾选的用户会使用此账号。密码不会发送到浏览器或显示给用户；图片记录仍归实际上传者所有，用户只能查看和管理自己的图片。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">自定义媒体域名</th>
                        <td><label><input type="checkbox" name="rewrite_enabled" value="1" <?php checked(!empty($settings['rewrite_enabled'])); ?>> 启用链接域名替换</label><p class="description">关闭时，图库始终使用原始链接。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source_host">原始域名</label></th>
                        <td><input id="source_host" name="source_host" class="regular-text" value="<?php echo esc_attr($settings['source_host']); ?>"><p class="description">这里只填写域名，不填写 http:// 或 https://。它只用于匹配，http 与 https 的原始链接都会识别。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="target_base">输出域名</label></th>
                        <td><input id="target_base" name="target_base" type="url" class="regular-text code" value="<?php echo esc_attr($settings['target_base']); ?>"><p class="description">必须填写完整 HTTPS 地址，例如 https://img.youxianfeng.com 或 https://cdn.example.com。开启后，输出链接会统一使用这里的 HTTPS 地址。</p></td>
                    </tr>
                </table>
                <hr>
                <h2>存储连接</h2>
                <p>这里配置全站共用的游先锋邮箱服务器地址和图片目录。每个用户的用户名、密码均在“登录”菜单中单独保存；启用默认图库账号后，已授权的前台用户可使用该账号上传。</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="storage_protocol">协议</label></th>
                        <td><select id="storage_protocol" name="storage_protocol"><option value="ftps" <?php selected($settings['storage_protocol'], 'ftps'); ?>>FTPS（游先锋邮箱当前账号默认）</option><option value="sftp" <?php selected($settings['storage_protocol'], 'sftp'); ?>>SFTP</option></select></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sftp_host">服务器地址</label></th>
                        <td><input id="sftp_host" name="sftp_host" class="regular-text code" value="<?php echo esc_attr($settings['sftp_host']); ?>" placeholder="按游先锋邮箱连接指南填写"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sftp_port">端口</label></th>
                        <td><input id="sftp_port" name="sftp_port" type="number" min="1" max="65535" value="<?php echo esc_attr($settings['sftp_port']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sftp_remote_path">目标目录</label></th>
                        <td><input id="sftp_remote_path" name="sftp_remote_path" class="regular-text code" value="<?php echo esc_attr($settings['sftp_remote_path']); ?>" placeholder="例如 /Files/youxianfeng-gallery"><p class="description">同一路径会分别建立在每位用户自己的网盘中。请先让用户在自己的游先锋邮箱网盘中建好该目录。</p></td>
                    </tr>
                </table>
                <h3>公开链接接口</h3>
                <p>媒体上传后，插件会用用户自己的账号或管理员指定的默认图库账号为文件开启公开访问并取得链接。</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="api_base">网页接口地址</label></th>
                        <td><input id="api_base" name="api_base" type="url" class="regular-text code" value="<?php echo esc_attr($settings['api_base']); ?>"><p class="description">通常是 https://mail.youxianfeng.com。</p></td>
                    </tr>
                </table>
                <h3>GitHub 更新</h3>
                <p>图库插件从私有 GitHub 仓库读取版本并下载升级包。令牌只由管理员保存，用于检查和下载该插件的新版本。</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="github_token">GitHub 更新令牌</label></th>
                        <td><input id="github_token" name="github_token" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($settings['github_secret']) ? esc_attr('已保存；留空则不修改') : esc_attr('粘贴仅有读取权限的令牌'); ?>"><p class="description">仅需授予游先锋图库仓库的 Contents 读取权限。不会显示或写入插件文件。</p></td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary" name="yxf_gallery_action" value="save_settings">保存设置</button></p>
            </form>
            <div class="notice notice-info inline"><p>转换只修改域名头，原始文件路径、查询参数和链接片段完全保留。已加入图库的历史记录不会被批量改写。</p></div>
            <h2>当前接入状态</h2>
            <p>已具备：用户独立登录、上传到个人网盘、自动生成公开链接、可控域名替换、经典编辑器插入媒体<?php echo !empty($settings['replace_media_library']) ? '、替换媒体库已启用' : ''; ?>。</p>
            <p>存储连接由每位用户在“登录”菜单中自行测试。</p>
        </div>
        <?php
    }

    public static function classic_editor_button($editor_id) {
        if (!self::can_use_gallery()) {
            return;
        }
        add_thickbox();
        $url = add_query_arg(array('type' => 'yxf_gallery', 'TB_iframe' => 1, 'width' => 900, 'height' => 620), admin_url('media-upload.php'));
        $replacing = self::media_replacement_enabled();
        printf(' <a id="insert-yxf-gallery-button" href="%1$s" class="button thickbox yxf-gallery-media-button%2$s" title="游先锋图库" style="display:inline-flex;align-items:center;gap:4px;line-height:28px"><span class="dashicons dashicons-format-gallery" aria-hidden="true" style="width:18px;height:18px;font-size:18px;line-height:18px"></span><span>%3$s</span></a>', esc_url($url), $replacing ? ' is-media-replacement' : '', esc_html($replacing ? '添加媒体' : '游先锋图库'));
    }

    public static function media_iframe() {
        wp_iframe(array(__CLASS__, 'render_media_iframe'));
    }

    /** 前台和后台共用的图库窗口；admin-ajax 不要求普通用户进入 wp-admin。 */
    public static function ajax_media_iframe() {
        if (!self::can_use_gallery()) {
            status_header(403);
            wp_die('无权访问图库。');
        }
        nocache_headers();
        wp_iframe(array(__CLASS__, 'render_media_iframe'));
    }

    /** 前台独立窗口：不进入 wp-admin 或 admin-ajax，避免普通用户收到默认“0”响应。 */
    public static function frontend_media_iframe() {
        if (empty($_GET['yxf_gallery_frame'])) {
            return;
        }
        nocache_headers();
        if (!is_user_logged_in()) {
            status_header(403);
            wp_die('请先登录网站后再上传图片。');
        }
        if (!self::can_use_gallery()) {
            status_header(403);
            wp_die('你的账户暂未获得图库上传权限，请联系管理员。');
        }
        // 此页面本身就是 iframe 内容，不能调用仅后台可用的 wp_iframe()。
        // 直接输出插件的独立窗口，普通前台用户也能正常使用。
        status_header(200);
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="' . esc_attr(get_option('blog_charset')) . '"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>';
        self::render_media_iframe();
        echo '</body></html>';
        exit;
    }

    public static function render_media_iframe() {
        if (!self::can_use_gallery()) {
            wp_die('无权访问图库。');
        }
        if (!self::user_has_login()) {
            ?>
            <style>body{margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327}.yxf-login-guide{max-width:520px;margin:75px auto;padding:30px;background:#fff;border:1px solid #dcdcde;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.04)}.yxf-login-guide h2{margin-top:0}.yxf-login-guide p{color:#646970;line-height:1.7}.yxf-login-guide .button{margin:8px 4px}</style>
            <div class="yxf-login-guide"><h2>请先登录游先锋邮箱</h2><p>登录后才能查看自己的图库、上传媒体或插入文章。当前文章不会被保存或修改。</p><p><a class="button button-primary" target="_top" href="<?php echo esc_url(self::login_url()); ?>">前往登录</a><button class="button" type="button" onclick="if(window.parent&&window.parent.YXFGalleryClose){window.parent.YXFGalleryClose();}else if(window.parent&&window.parent.tb_remove){window.parent.tb_remove();}">暂不登录</button></p></div>
            <?php
            return;
        }
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE status = 'ready' AND author_id = %d ORDER BY id DESC LIMIT 500", get_current_user_id()));
        $media_items = array();
        foreach ($items as $item) {
            $item_url = self::item_public_url($item);
            if (!$item_url) {
                continue;
            }
            $attachment_id = self::ensure_virtual_attachment($item);
            $media_items[] = array(
                'id'        => (int) $item->id,
                'attachmentId' => $attachment_id,
                'name'      => (string) ($item->file_name ?: '媒体文件'),
                'url'       => esc_url_raw($item_url),
                'mime'      => (string) $item->mime_type,
                'kind'      => strtok((string) $item->mime_type, '/'),
                'authorId'  => (int) $item->author_id,
                'createdAt' => (string) $item->created_at,
            );
        }
        $post_id = absint($_REQUEST['post_id'] ?? 0);
        $callback = sanitize_key(wp_unslash($_REQUEST['yxf_gallery_callback'] ?? ''));
        $multiple = max(1, absint($_REQUEST['yxf_gallery_multiple'] ?? 1));
        $raw_requested_type = $_REQUEST['yxf_gallery_type'] ?? 'all';
        $requested_type = is_string($raw_requested_type) ? sanitize_key(wp_unslash($raw_requested_type)) : 'all';
        $requested_type = in_array($requested_type, array('image', 'video', 'audio', 'file', 'all'), true) ? $requested_type : 'all';
        // 从任何上传/添加图片入口进入时，都应先显示上传文件页。
        $active_tab = sanitize_key(wp_unslash($_GET['yxf_gallery_tab'] ?? 'upload')) === 'library' ? 'library' : 'upload';
        ?>
        <style>
            html,body{height:100%;min-height:100%;margin:0;background:#f0f0f1;overflow:hidden}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327}.yxf-media-frame{position:fixed;inset:0;display:flex;flex-direction:column;background:#fff}.yxf-media-tabs{height:56px;display:flex;align-items:flex-end;padding:0 24px;border-bottom:1px solid #dcdcde;background:#fff}.yxf-media-tab{height:56px;padding:0 14px;border:0;border-bottom:4px solid transparent;background:transparent;color:#50575e;font-size:14px;cursor:pointer}.yxf-media-tab.is-active{border-bottom-color:#2271b1;color:#1d2327;font-weight:600}.yxf-media-body{position:relative;z-index:1;flex:1;min-height:0}.yxf-media-panel{display:none;height:100%}.yxf-media-panel.is-active{display:block}.yxf-upload-panel{box-sizing:border-box;padding:28px 40px;overflow:auto}.yxf-upload-box{max-width:760px;margin:0 auto;padding:40px 30px;border:2px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;text-align:center}.yxf-upload-box h2{margin:0 0 10px;font-size:20px}.yxf-upload-box p{color:#646970}.yxf-upload-box input[type=file]{display:block;max-width:100%;margin:22px auto}.yxf-upload-actions{display:flex;justify-content:center;gap:8px}.yxf-upload-queue{max-width:760px;margin:22px auto 0;padding:0;list-style:none;border-top:1px solid #dcdcde}.yxf-upload-queue:empty{display:none}.yxf-upload-item{display:flex;gap:12px;align-items:center;padding:12px 2px;border-bottom:1px solid #e5e5e5}.yxf-upload-item-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left}.yxf-upload-item-status{font-size:12px;color:#646970}.yxf-upload-item.is-uploading .yxf-upload-item-status{color:#2271b1}.yxf-upload-item.is-success .yxf-upload-item-status{color:#00a32a}.yxf-upload-item.is-error .yxf-upload-item-status{color:#d63638}.yxf-upload-item-remove{color:#b32d2e;border:0;background:none;cursor:pointer}.yxf-uploaded-wrap{max-width:760px;margin:26px auto 0}.yxf-uploaded-wrap:empty{display:none}.yxf-uploaded-title{margin:0 0 10px;font-weight:600;text-align:left}.yxf-uploaded-thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:10px}.yxf-uploaded-thumb{position:relative;display:block;aspect-ratio:1;padding:0;border:2px solid transparent;background:#f0f0f1;cursor:pointer;overflow:hidden}.yxf-uploaded-thumb:hover,.yxf-uploaded-thumb.is-selected{border-color:#2271b1}.yxf-uploaded-thumb img{display:block;width:100%;height:100%;object-fit:cover}.yxf-uploaded-thumb span{position:absolute;inset:auto 0 0;padding:4px 5px;background:rgba(0,0,0,.62);color:#fff;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-library-panel{display:flex;flex-direction:column}.yxf-library-toolbar{display:flex;align-items:center;gap:10px;min-height:54px;padding:0 18px;border-bottom:1px solid #dcdcde;background:#f6f7f7}.yxf-library-toolbar select{min-width:128px}.yxf-library-main{display:flex;flex:1;min-height:0}.yxf-attachments{flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));align-content:start;gap:16px;margin:0;padding:20px;overflow:auto;list-style:none}.yxf-attachment{position:relative;aspect-ratio:1;border:1px solid #dcdcde;background:#f0f0f1;cursor:pointer;overflow:hidden}.yxf-attachment img{width:100%;height:100%;object-fit:cover;display:block}.yxf-file-icon{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#646970;font-size:12px;padding:12px;text-align:center;word-break:break-all}.yxf-file-icon b{font-size:28px;line-height:1;margin-bottom:10px;color:#2271b1}.yxf-attachment.is-selected{border:3px solid #2271b1}.yxf-attachment.is-selected:after{content:"✓";position:absolute;right:0;top:0;width:24px;height:24px;background:#2271b1;color:#fff;font-weight:700;text-align:center;line-height:24px}.yxf-empty{grid-column:1/-1;padding:70px 20px;text-align:center;color:#646970}.yxf-details{width:300px;box-sizing:border-box;padding:20px;border-left:1px solid #dcdcde;background:#fff;overflow:auto}.yxf-details.is-empty{color:#646970;padding-top:70px;text-align:center}.yxf-details img,.yxf-details video{width:100%;height:190px;object-fit:contain;background:#f0f0f1;margin-bottom:18px}.yxf-detail-title{margin:0 0 14px;font-size:15px;word-break:break-word}.yxf-detail-meta{margin:6px 0;color:#646970;font-size:12px;word-break:break-all}.yxf-detail-url{display:block;max-height:64px;overflow:auto;color:#2271b1;font-size:12px;word-break:break-all}.yxf-detail-actions{display:flex;gap:8px;margin-top:16px}.yxf-media-footer{position:relative;z-index:10;display:flex;align-items:center;flex:0 0 60px;gap:8px;min-height:60px;padding:12px 18px;box-sizing:border-box;border-top:1px solid #dcdcde;background:#fff;box-shadow:0 -2px 8px rgba(0,0,0,.06)}.yxf-media-footer input{flex:1;min-width:0;max-width:420px;height:32px;margin-left:auto;box-sizing:border-box}.yxf-media-footer .button{height:32px;margin:0;white-space:nowrap}.yxf-notice{margin:16px 0}.yxf-hidden{display:none!important}@media(max-width:720px){.yxf-upload-panel{padding:20px}.yxf-details{width:240px}.yxf-media-footer input{max-width:none}.yxf-attachments{grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:10px;padding:12px}}
        </style>
        <div class="yxf-media-frame" id="yxf-media-frame">
            <div class="yxf-media-tabs" role="tablist" aria-label="游先锋图库">
                <button type="button" class="yxf-media-tab <?php echo $active_tab === 'upload' ? 'is-active' : ''; ?>" data-yxf-tab="upload" role="tab" aria-selected="<?php echo $active_tab === 'upload' ? 'true' : 'false'; ?>">上传文件</button>
                <button type="button" class="yxf-media-tab <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" data-yxf-tab="library" role="tab" aria-selected="<?php echo $active_tab === 'library' ? 'true' : 'false'; ?>">全部媒体</button>
            </div>
            <div class="yxf-media-body">
                <section class="yxf-media-panel yxf-upload-panel <?php echo $active_tab === 'upload' ? 'is-active' : ''; ?>" data-yxf-panel="upload">
                    <div class="yxf-upload-box">
                        <h2>上传图片到游先锋图库</h2>
                        <p>可一次选择多张图片。上传完成后，点击下方缩略图选中图片，再点击“插入图片”。</p>
                        <?php self::notices(); ?>
                        <?php if (!self::user_has_login()) : ?><div class="notice notice-warning inline"><p>请先在后台“游先锋图库 → 登录”中填写你自己的游先锋邮箱账号。</p></div><?php endif; ?>
                        <input id="yxf-frame-files" type="file" accept="image/*" multiple class="yxf-hidden" <?php disabled(!self::user_has_login()); ?>>
                        <p class="yxf-upload-actions"><button class="button button-large" type="button" id="yxf-frame-choose" <?php disabled(!self::user_has_login()); ?>>选择图片</button><button class="button button-primary button-large" type="button" id="yxf-frame-start" disabled>开始上传</button></p>
                    </div>
                    <ul class="yxf-upload-queue" id="yxf-frame-queue" aria-live="polite"></ul>
                    <div class="yxf-uploaded-wrap" id="yxf-frame-uploaded" aria-live="polite"></div>
                </section>
                <section class="yxf-media-panel yxf-library-panel <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" data-yxf-panel="library">
                    <?php self::notices(); ?>
                    <div class="yxf-library-toolbar">
                        <select id="yxf-type-filter" aria-label="筛选媒体类型"><option value="all">所有类型</option></select>
                    </div>
                    <div class="yxf-library-main">
                        <ul class="yxf-attachments" id="yxf-attachments" aria-label="媒体列表"></ul>
                        <aside class="yxf-details is-empty" id="yxf-details">请选择一个媒体文件查看详情</aside>
                    </div>
                </section>
            </div>
            <div class="yxf-media-footer"><input id="yxf-search" type="search" placeholder="搜索文件名称或链接" aria-label="搜索媒体或查看所选图片链接"><button type="button" class="button" id="yxf-cancel">取消</button><button type="button" class="button button-primary" id="yxf-insert" disabled>插入图片</button></div>
        </div>
        <script>
        (function(){
            var items = <?php echo wp_json_encode($media_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var callbackKey = <?php echo wp_json_encode($callback); ?>;
            var selectionLimit = <?php echo (int) $multiple; ?>;
            var requestedType = <?php echo wp_json_encode($requested_type); ?>;
            var currentUser = <?php echo (int) get_current_user_id(); ?>;
            var active = null;
            var selectedItems = [];
            var frame = document.getElementById('yxf-media-frame');
            var attachments = document.getElementById('yxf-attachments');
            var details = document.getElementById('yxf-details');
            var insert = document.getElementById('yxf-insert');
            var type = document.getElementById('yxf-type-filter');
            var search = document.getElementById('yxf-search');
            var uploadInput = document.getElementById('yxf-frame-files');
            var uploadChoose = document.getElementById('yxf-frame-choose');
            var uploadStart = document.getElementById('yxf-frame-start');
            var uploadQueue = document.getElementById('yxf-frame-queue');
            var uploadedWrap = document.getElementById('yxf-frame-uploaded');
            var uploadItems = new Map();
            var uploadedItems = [];
            var uploading = false;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var uploadNonce = <?php echo wp_json_encode(wp_create_nonce('yxf_gallery_upload')); ?>;
            var close = function(){ if (window.parent && window.parent.YXFGalleryClose) window.parent.YXFGalleryClose(); else if (window.parent && window.parent.tb_remove) window.parent.tb_remove(); else if (window.tb_remove) window.tb_remove(); };
            var copy = function(value, button){
                var done = function(){ var original = button.textContent; button.textContent = '已复制'; window.setTimeout(function(){ button.textContent = original; }, 1500); };
                if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(value).then(done); return; }
                var textarea = document.createElement('textarea'); textarea.value = value; textarea.style.position = 'fixed'; textarea.style.opacity = '0'; document.body.appendChild(textarea); textarea.select(); document.execCommand('copy'); textarea.remove(); done();
            };
            var switchTab = function(tab){
                frame.querySelectorAll('[data-yxf-tab]').forEach(function(button){ var selected = button.getAttribute('data-yxf-tab') === tab; button.classList.toggle('is-active', selected); button.setAttribute('aria-selected', selected ? 'true' : 'false'); });
                frame.querySelectorAll('[data-yxf-panel]').forEach(function(panel){ panel.classList.toggle('is-active', panel.getAttribute('data-yxf-panel') === tab); });
            };
            var insertItems = function(chosen){
                chosen = chosen || [];
                if (!chosen.length) return;
                if (callbackKey && window.parent && window.parent.YXFGalleryMediaCallbacks && typeof window.parent.YXFGalleryMediaCallbacks[callbackKey] === 'function') {
                    window.parent.YXFGalleryMediaCallbacks[callbackKey](chosen);
                    close();
                    return;
                }
                var imageHtml = chosen.map(function(item){ if (item.kind === 'image') { var image = document.createElement('img'); image.src = item.url; image.alt = ''; return image.outerHTML; } return '<a href="' + item.url.replace(/"/g, '&quot;') + '">' + (item.name || item.url) + '</a>'; }).join('');
                var editorWindow = window.parent && typeof window.parent.send_to_editor === 'function' ? window.parent : window;
                if (typeof editorWindow.send_to_editor !== 'function') { window.alert('未找到文章编辑器，请关闭窗口后重试。'); return; }
                editorWindow.send_to_editor(imageHtml);
                close();
            };
            var uploadKey = function(file){ return [file.name, file.size, file.lastModified].join(':'); };
            var renderUploadQueue = function(){
                if (!uploadQueue || !uploadStart) return;
                uploadQueue.innerHTML = '';
                uploadItems.forEach(function(entry, id){
                    var row = document.createElement('li'); row.className = 'yxf-upload-item is-' + entry.state;
                    row.innerHTML = '<span class="yxf-upload-item-name"></span><span class="yxf-upload-item-status"></span>';
                    row.querySelector('.yxf-upload-item-name').textContent = entry.file.name;
                    row.querySelector('.yxf-upload-item-status').textContent = entry.message;
                    if (entry.state === 'waiting' || entry.state === 'error') { var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'yxf-upload-item-remove'; remove.textContent = '移除'; remove.addEventListener('click', function(){ uploadItems.delete(id); renderUploadQueue(); }); row.appendChild(remove); }
                    uploadQueue.appendChild(row);
                });
                uploadStart.disabled = uploading || !Array.prototype.some.call(Array.from(uploadItems.values()), function(entry){ return entry.state === 'waiting' || entry.state === 'error'; });
            };
            var renderUploadedThumbs = function(){
                if (!uploadedWrap) return;
                uploadedWrap.innerHTML = '';
                if (!uploadedItems.length) return;
                var title = document.createElement('p'); title.className = 'yxf-uploaded-title'; title.textContent = '上传完成（点击下方缩略图选择图片插入）';
                var grid = document.createElement('div'); grid.className = 'yxf-uploaded-thumbs';
                uploadedItems.forEach(function(item){
                    var button = document.createElement('button'); button.type = 'button'; button.className = 'yxf-uploaded-thumb' + (active && active.id === item.id ? ' is-selected' : ''); button.title = '选中：' + item.name;
                    var image = document.createElement('img'); image.src = item.url; image.alt = item.name; image.loading = 'lazy';
                    var label = document.createElement('span'); label.textContent = item.name;
                    button.append(image, label); button.addEventListener('click', function(){ showDetails(item, false); search.value = item.url; renderUploadedThumbs(); }); grid.appendChild(button);
                });
                uploadedWrap.append(title, grid);
            };
            var addUploadFiles = function(files){
                Array.prototype.forEach.call(files || [], function(file){ if (!file.type || !file.type.match(/^image\//)) return; var id = uploadKey(file); if (!uploadItems.has(id)) uploadItems.set(id, {file:file, state:'waiting', message:'等待上传'}); });
                uploadInput.value = ''; renderUploadQueue();
            };
            var addUploadedItem = function(data){
                if (!data || !data.url) return;
                var item = {id:Number(data.id || 0), attachmentId:Number(data.attachmentId || 0), name:data.name || '图片', url:data.url, mime:data.mime || 'image/*', kind:data.kind || 'image', authorId:currentUser, createdAt:data.createdAt || ''};
                if (!items.some(function(existing){ return existing.id === item.id; })) items.unshift(item);
                if (!uploadedItems.some(function(existing){ return existing.id === item.id; })) uploadedItems.unshift(item);
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({type:'yxf_gallery_uploaded', item:item}, window.location.origin);
                }
                render(); renderUploadedThumbs();
            };
            var uploadOne = async function(entry){
                entry.state = 'uploading'; entry.message = '正在上传…'; renderUploadQueue();
                var data = new FormData(); data.append('action', 'yxf_gallery_upload_image'); data.append('nonce', uploadNonce); data.append('gallery_file', entry.file, entry.file.name);
                try { var response = await fetch(ajaxUrl, {method:'POST', body:data, credentials:'same-origin'}), raw = await response.text(), payload; try { payload = JSON.parse(raw); } catch (parseError) { throw new Error('服务器未返回有效的上传结果，请重新登录游先锋邮箱后再试。'); } if (!payload.success) throw new Error((payload.data && payload.data.message) || '上传失败，请重试。'); entry.state = 'success'; entry.message = payload.data.duplicate ? '已存在，无需重复上传' : (payload.data.warning ? '已上传，公开链接正在生成' : '上传完成'); addUploadedItem(payload.data); }
                catch(error) { entry.state = 'error'; entry.message = error.message || '上传失败，请重试。'; }
                renderUploadQueue();
            };
            var runUploadQueue = async function(){
                if (uploading) return;
                uploading = true; renderUploadQueue();
                for (const entry of uploadItems.values()) if (entry.state === 'waiting' || entry.state === 'error') await uploadOne(entry);
                uploading = false; renderUploadQueue();
            };
            var visibleItems = function(){
                var keyword = (search.value || '').trim().toLowerCase();
                return items.filter(function(item){
                    return (requestedType === 'all' || (requestedType === 'file' ? ['image','video','audio'].indexOf(item.kind) < 0 : item.kind === requestedType)) && (type.value === 'all' || item.mime === type.value) && (!keyword || (item.name + ' ' + item.url).toLowerCase().indexOf(keyword) !== -1);
                });
            };
            var showDetails = function(item, toggle){
                if (toggle && selectionLimit > 1) {
                    var existing = selectedItems.findIndex(function(selected){ return selected.id === item.id; });
                    if (existing >= 0) selectedItems.splice(existing, 1); else if (selectedItems.length < selectionLimit) selectedItems.push(item);
                } else {
                    selectedItems = [item];
                }
                active = selectedItems.length ? selectedItems[selectedItems.length - 1] : null;
                insert.disabled = !selectedItems.length;
                details.classList.remove('is-empty');
                details.innerHTML = '';
                var preview;
                if (item.kind === 'image') { preview = document.createElement('img'); preview.src = item.url; preview.alt = ''; }
                else if (item.kind === 'video') { preview = document.createElement('video'); preview.src = item.url; preview.controls = true; }
                else { preview = document.createElement('div'); preview.className = 'yxf-file-icon'; preview.innerHTML = '<b>⌁</b><span>媒体文件</span>'; }
                var title = document.createElement('h2'); title.className = 'yxf-detail-title'; title.textContent = item.name;
                var mime = document.createElement('p'); mime.className = 'yxf-detail-meta'; mime.textContent = '文件类型：' + (item.mime || '图片');
                var date = document.createElement('p'); date.className = 'yxf-detail-meta'; date.textContent = '上传时间：' + (item.createdAt || '');
                var link = document.createElement('a'); link.className = 'yxf-detail-url'; link.href = item.url; link.target = '_blank'; link.rel = 'noopener'; link.textContent = item.url;
                var actions = document.createElement('div'); actions.className = 'yxf-detail-actions';
                var copyButton = document.createElement('button'); copyButton.type = 'button'; copyButton.className = 'button'; copyButton.textContent = '复制链接'; copyButton.addEventListener('click', function(){ copy(item.url, copyButton); });
                actions.appendChild(copyButton); details.append(preview,title,mime,date,link,actions);
                attachments.querySelectorAll('.yxf-attachment').forEach(function(node){ node.classList.toggle('is-selected', selectedItems.some(function(selected){ return selected.id === Number(node.getAttribute('data-id')); })); });
            };
            var render = function(){
                attachments.innerHTML = '';
                var filtered = visibleItems();
                if (!filtered.length) { var empty = document.createElement('li'); empty.className = 'yxf-empty'; empty.textContent = items.length ? '没有符合条件的媒体文件。' : '图库暂无媒体文件，请先上传文件。'; attachments.appendChild(empty); return; }
                filtered.forEach(function(item){
                    var node = document.createElement('li'); node.className = 'yxf-attachment' + (active && active.id === item.id ? ' is-selected' : ''); node.setAttribute('data-id', item.id); node.setAttribute('title', item.name);
                    if (item.kind === 'image') { var image = document.createElement('img'); image.src = item.url; image.alt = item.name; image.loading = 'lazy'; node.appendChild(image); }
                    else { var icon = document.createElement('div'); icon.className = 'yxf-file-icon'; icon.innerHTML = '<b>' + (item.kind === 'video' ? '▶' : item.kind === 'audio' ? '♫' : '⌁') + '</b><span>' + item.name + '</span>'; node.appendChild(icon); }
                    node.addEventListener('click', function(){ showDetails(item, true); search.value = active ? active.url : ''; render(); renderUploadedThumbs(); }); attachments.appendChild(node);
                });
            };
            Array.prototype.forEach.call(document.querySelectorAll('[data-yxf-tab]'), function(button){ button.addEventListener('click', function(){ switchTab(button.getAttribute('data-yxf-tab')); }); });
            items.forEach(function(item){ if (item.mime && !Array.prototype.some.call(type.options, function(option){ return option.value === item.mime; })) { var option = document.createElement('option'); option.value = item.mime; option.textContent = item.mime.replace(/^.*\//, '').toUpperCase(); type.appendChild(option); } });
            type.addEventListener('change', render); search.addEventListener('input', render);
            document.getElementById('yxf-cancel').addEventListener('click', close);
            insert.addEventListener('click', function(){ if (active) insertItems(selectedItems.length ? selectedItems : [active]); });
            if (uploadChoose && uploadInput && uploadStart) { uploadChoose.addEventListener('click', function(){ uploadInput.click(); }); uploadInput.addEventListener('change', function(){ addUploadFiles(uploadInput.files); }); uploadStart.addEventListener('click', runUploadQueue); renderUploadQueue(); }
            render();
        }());
        </script>
        <?php
    }
}

register_activation_hook(__FILE__, array('YouXianFeng_Gallery', 'activate'));
YouXianFeng_Gallery::init();
