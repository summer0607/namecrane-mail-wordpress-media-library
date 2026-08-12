<?php
/**
 * Plugin Name: NameCrane邮箱媒体库
 * Description: 将媒体上传至 NameCrane 邮件存储，自动生成公开链接，并替换 WordPress 与主题的媒体选择入口。
 * Version: 1.0.6
 * Update URI: https://youxianfeng.com/
 * Author: 游先锋
 */

defined('ABSPATH') || exit;

final class YouXianFeng_Gallery {
    const VERSION = '1.0.6';
    const DEFAULT_GALLERY_NAME = 'NameCrane媒体库';
    // 直接使用正式站主域名，避免非 www 域名跳转在部分旧版 cURL 中触发证书用途校验错误。
    const SERVICE_URL = 'https://www.youxianfeng.com/wp-json/namecrane-gallery/v1';
    const UPDATE_CACHE_KEY = 'namecrane_gallery_update_release';
    const LICENSE_CACHE_KEY = 'namecrane_gallery_license_status';
    const INSTALLATION_ID_OPTION = 'namecrane_gallery_installation_id';
    const OPTION = 'yxf_gallery_settings';
    const USERNAME_META = 'yxf_gallery_username';
    const SECRET_META = 'yxf_gallery_secret';
    const CAPABILITY = 'use_yxf_gallery';
    const CAPS_OPTION = 'yxf_gallery_caps_version';
    const TABLE_SUFFIX = 'yxf_gallery_items';
    const DB_OPTION = 'yxf_gallery_db_version';
    const DB_VERSION = '8';
    // 数据表用该保留值标记“管理员配置的默认邮箱账号”，避免与实际上传用户混淆。
    const DEFAULT_MAILBOX_OWNER_ID = 999999999999;
    const DEFAULTS_MIGRATION_OPTION = 'namecrane_gallery_defaults_migration';
    const GALLERY_NAME_MIGRATION_OPTION = 'namecrane_gallery_default_name_migration_v2';
    const MEDIA_REPLACEMENT_MIGRATION_OPTION = 'namecrane_gallery_media_replacement_default_v2';
    const DEFAULT_MAILBOX_MIGRATION_OPTION = 'namecrane_gallery_default_mailbox_migration_v1';
    const INDEPENDENT_LOGIN_DEFAULT_MIGRATION_OPTION = 'namecrane_gallery_independent_login_default_v1';
    const ACTIVATION_REDIRECT_TRANSIENT = 'namecrane_gallery_activation_redirect';

    public static function init() {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_upgrade'));
        add_action('admin_init', array(__CLASS__, 'maybe_redirect_after_activation'), 0);
        add_action('admin_init', array(__CLASS__, 'enforce_login_gate'), 1);
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_menu', array(__CLASS__, 'maybe_hide_wordpress_media_menu'), 999);
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
        add_action('wp_ajax_yxf_gallery_upload_image', array(__CLASS__, 'ajax_upload_image'));
        add_action('wp_ajax_yxf_gallery_start_license_checkout', array(__CLASS__, 'ajax_start_license_checkout'));
        add_action('wp_ajax_yxf_gallery_license_order_status', array(__CLASS__, 'ajax_license_order_status'));
        add_action('wp_ajax_yxf_gallery_resolve_pending_item', array(__CLASS__, 'ajax_resolve_pending_item'));
        add_action('wp_ajax_yxf_gallery_media_items', array(__CLASS__, 'ajax_media_items'));
        add_action('wp_ajax_yxf_gallery_delete_remote_item', array(__CLASS__, 'ajax_delete_remote_item'));
        // 子比前台话题/标签使用原生文件表单，不经过 wp.media。插件选中外链图片后，
        // 在主题处理器之前保存同一表单，避免再次落回网站本地 uploads。
        add_action('wp_ajax_save_forum_topic', array(__CLASS__, 'maybe_save_zibll_forum_term'), 0);
        add_action('wp_ajax_save_forum_tag', array(__CLASS__, 'maybe_save_zibll_forum_term'), 0);
        // 邮箱网盘对刚通过 FTPS/SFTP 写入的文件需要短暂建立索引。
        // 解析公开链接放到异步任务中，不能让浏览器请求一直等待。
        add_action('yxf_gallery_resolve_pending_item', array(__CLASS__, 'resolve_pending_item_event'), 10, 2);
        // 保留后台 AJAX 入口，供后台环境兼容使用。
        add_action('wp_ajax_yxf_gallery_media_frame', array(__CLASS__, 'ajax_media_iframe'));
        // 前台 iframe 不能依赖 admin-ajax，否则普通用户会得到 WordPress 默认的“0”。
        add_action('template_redirect', array(__CLASS__, 'frontend_media_iframe'), 0);
        add_action('admin_post_yxf_gallery_check_update', array(__CLASS__, 'check_update_now'));
        add_action('admin_notices', array(__CLASS__, 'update_check_notice'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_media_replacement'), 999);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_media_replacement'), 999);
        add_action('media_upload_yxf_gallery', array(__CLASS__, 'media_iframe'));
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'gallery_attachment_query'));
        add_action('pre_get_posts', array(__CLASS__, 'gallery_media_list_query'));
        add_filter('wp_get_attachment_url', array(__CLASS__, 'gallery_attachment_url'), 20, 2);
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'prepare_gallery_attachment'), 20, 3);
        add_filter('wp_handle_upload_prefilter', array(__CLASS__, 'prevent_native_image_upload'));
        add_action('admin_init', array(__CLASS__, 'redirect_core_media_pages'), 2);
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 20, 3);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(__CLASS__, 'update_action_link'));
    }

    public static function activate() {
        $is_new_install = get_option(self::DB_OPTION, null) === null && get_option(self::OPTION, null) === null;
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
        if ($is_new_install) {
            set_transient(self::ACTIVATION_REDIRECT_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
        }
    }

    public static function maybe_upgrade() {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::activate();
        }
        if (get_option(self::CAPS_OPTION) !== self::VERSION) {
            self::grant_capabilities();
        }
        self::migrate_legacy_namecrane_defaults();
        self::migrate_default_gallery_name();
        self::migrate_media_replacement_default();
        self::migrate_shared_account_to_default_mailbox();
        self::migrate_independent_login_default();
        self::remove_legacy_global_credentials();
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function defaults() {
        return array(
            'source_host'     => 'eu1.workspace.org',
            'target_base'     => '',
            'storage_protocol'=> 'ftps',
            'sftp_host'       => 'eu1.workspace.org',
            'sftp_port'       => 8221,
            'sftp_remote_path'=> '/',
            'api_base'        => 'https://eu1.workspace.org',
            'replace_media_library' => 1,
            'hide_wordpress_media_menu' => 1,
            'auto_convert_images_to_webp' => 0,
            'default_mailbox_username' => '',
            'default_mailbox_secret' => '',
            'connection_status' => 'not_connected',
            'connection_status_message' => '',
            'connection_checked_at' => '',
            'gallery_name' => self::DEFAULT_GALLERY_NAME,
            'independent_login_roles' => array(),
            'independent_login_user_ids' => array(),
            'upload_default_rule' => self::default_upload_rule(),
            'upload_role_rules' => array(),
            'upload_user_rules' => array(),
        );
    }

    private static function settings() {
        $settings = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        // 服务器地址是唯一的 NameCrane 主机来源，网页接口和文件公开链接不再单独配置。
        $server_host = self::normalize_server_host((string) ($settings['sftp_host'] ?? ''));
        if ($server_host !== '') {
            $settings['sftp_host'] = $server_host;
            $settings['source_host'] = $server_host;
            $settings['api_base'] = 'https://' . $server_host;
        }
        return $settings;
    }

    private static function normalize_server_host($host) {
        $host = trim((string) $host);
        $host = preg_replace('#^https?://#i', '', $host);
        return trim((string) preg_replace('#/.*$#', '', $host), '/');
    }

    /**
     * 旧版会把游先锋邮箱地址保存为站点设置；仅替换这些确定的旧默认值，
     * 不触碰用户自己填写过的自定义域名、端口或目录。
     */
    private static function migrate_legacy_namecrane_defaults() {
        if (get_option(self::DEFAULTS_MIGRATION_OPTION) === self::DB_VERSION) {
            return;
        }
        $settings = get_option(self::OPTION, array());
        if (!is_array($settings)) {
            update_option(self::DEFAULTS_MIGRATION_OPTION, self::DB_VERSION, false);
            return;
        }
        $changed = false;
        $legacy_hosts = array('mail.youxianfeng.com', 'https://mail.youxianfeng.com');
        if (in_array(strtolower(trim((string) ($settings['source_host'] ?? ''))), $legacy_hosts, true)) {
            $settings['source_host'] = 'eu1.workspace.org';
            $changed = true;
        }
        if (in_array(strtolower(trim((string) ($settings['sftp_host'] ?? ''))), $legacy_hosts, true)) {
            $settings['sftp_host'] = 'eu1.workspace.org';
            $changed = true;
        }
        if (in_array(untrailingslashit(strtolower(trim((string) ($settings['api_base'] ?? '')))), array('https://mail.youxianfeng.com', 'http://mail.youxianfeng.com'), true)) {
            $settings['api_base'] = 'https://eu1.workspace.org';
            $changed = true;
        }
        if (absint($settings['sftp_port'] ?? 0) === 21 && (($settings['storage_protocol'] ?? 'ftps') === 'ftps')) {
            $settings['sftp_port'] = 8221;
            $changed = true;
        }
        if (trim((string) ($settings['sftp_remote_path'] ?? '')) === '') {
            $settings['sftp_remote_path'] = '/';
            $changed = true;
        }
        $saved_independent_roles = array_values(array_unique(array_map('sanitize_key', (array) ($settings['independent_login_roles'] ?? array()))));
        sort($saved_independent_roles);
        $legacy_default_roles = array(
            array('administrator', 'author', 'contributor', 'editor', 'subscriber'),
            array('administrator', 'author', 'contributor', 'editor'),
        );
        if (in_array($saved_independent_roles, $legacy_default_roles, true)) {
            $settings['independent_login_roles'] = array();
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $settings, false);
        }
        update_option(self::DEFAULTS_MIGRATION_OPTION, self::DB_VERSION, false);
    }

    /** 将旧版自动填充的默认名称升级为新名称，不动其他自定义名称。 */
    private static function migrate_default_gallery_name() {
        if (get_option(self::GALLERY_NAME_MIGRATION_OPTION)) {
            return;
        }
        $settings = get_option(self::OPTION, array());
        if (is_array($settings) && in_array(($settings['gallery_name'] ?? ''), array('游先锋图库', '游先锋媒体库'), true)) {
            $settings['gallery_name'] = self::DEFAULT_GALLERY_NAME;
            update_option(self::OPTION, $settings, false);
        }
        update_option(self::GALLERY_NAME_MIGRATION_OPTION, 1, false);
    }

    /** 旧版默认关闭媒体库替换；只执行一次，将该旧默认值升级为启用。 */
    private static function migrate_media_replacement_default() {
        if (get_option(self::MEDIA_REPLACEMENT_MIGRATION_OPTION)) {
            return;
        }
        $settings = get_option(self::OPTION, array());
        if (is_array($settings) && empty($settings['replace_media_library'])) {
            $settings['replace_media_library'] = 1;
            update_option(self::OPTION, $settings, false);
        }
        update_option(self::MEDIA_REPLACEMENT_MIGRATION_OPTION, 1, false);
    }

    /** 将旧版“选择站内用户作为默认账号”的资料迁移到管理员配置的默认邮箱账号。 */
    private static function migrate_shared_account_to_default_mailbox() {
        if (get_option(self::DEFAULT_MAILBOX_MIGRATION_OPTION)) {
            return;
        }
        $settings = get_option(self::OPTION, array());
        if (!is_array($settings)) {
            update_option(self::DEFAULT_MAILBOX_MIGRATION_OPTION, 1, false);
            return;
        }
        if (empty($settings['default_mailbox_username'])) {
            $legacy_owner_id = absint($settings['shared_account_user_id'] ?? 0);
            $legacy_account = $legacy_owner_id ? self::account($legacy_owner_id) : array();
            if (!empty($legacy_account['username']) && !empty($legacy_account['secret'])) {
                $settings['default_mailbox_username'] = $legacy_account['username'];
                $settings['default_mailbox_secret'] = $legacy_account['secret'];
            }
        }
        unset($settings['shared_account_user_id'], $settings['shared_account_roles']);
        update_option(self::OPTION, $settings, false);
        update_option(self::DEFAULT_MAILBOX_MIGRATION_OPTION, 1, false);
    }

    /** 旧版默认自动允许管理员独立登录；新逻辑必须由管理员明确授权。 */
    private static function migrate_independent_login_default() {
        if (get_option(self::INDEPENDENT_LOGIN_DEFAULT_MIGRATION_OPTION)) {
            return;
        }
        $settings = get_option(self::OPTION, array());
        if (is_array($settings)) {
            $roles = array_values(array_unique(array_map('sanitize_key', (array) ($settings['independent_login_roles'] ?? array()))));
            sort($roles);
            if ($roles === array('administrator')) {
                $settings['independent_login_roles'] = array();
                update_option(self::OPTION, $settings, false);
            }
        }
        update_option(self::INDEPENDENT_LOGIN_DEFAULT_MIGRATION_OPTION, 1, false);
    }

    private static function remove_legacy_global_credentials() {
        $settings = get_option(self::OPTION, array());
        if (!is_array($settings)) {
            return;
        }
        $legacy_keys = array('sftp_username', 'sftp_secret', 'api_username', 'rewrite_enabled');
        if (!array_intersect($legacy_keys, array_keys($settings))) {
            return;
        }
        unset($settings['sftp_username'], $settings['sftp_secret'], $settings['api_username'], $settings['rewrite_enabled']);
        update_option(self::OPTION, $settings, false);
    }

    private static function settings_from_request($current) {
        $server_host = self::normalize_server_host(wp_unslash($_POST['sftp_host'] ?? ''));
        $target = esc_url_raw(trim((string) wp_unslash($_POST['target_base'] ?? '')));
        $protocol = sanitize_key(wp_unslash($_POST['storage_protocol'] ?? $current['storage_protocol']));
        $protocol = in_array($protocol, array('ftps', 'sftp'), true) ? $protocol : 'ftps';
        $default_port = $protocol === 'sftp' ? 8222 : 8221;
        $settings = array_merge($current, array(
            'replace_media_library' => empty($_POST['replace_media_library']) ? 0 : 1,
            'hide_wordpress_media_menu' => empty($_POST['hide_wordpress_media_menu']) ? 0 : 1,
            'auto_convert_images_to_webp' => empty($_POST['auto_convert_images_to_webp']) ? 0 : 1,
            'source_host'      => $server_host,
            'target_base'      => untrailingslashit($target),
            'storage_protocol' => $protocol,
            'sftp_host'        => $server_host,
            'sftp_port'        => max(1, min(65535, absint($_POST['sftp_port'] ?? $default_port) ?: $default_port)),
            'sftp_remote_path' => '/' . ltrim(trim((string) wp_unslash($_POST['sftp_remote_path'] ?? '')), '/'),
            'api_base'         => $server_host === '' ? '' : 'https://' . $server_host,
        ));
        $default_mailbox_username = trim((string) wp_unslash($_POST['default_mailbox_username'] ?? ''));
        $default_mailbox_password = (string) wp_unslash($_POST['default_mailbox_password'] ?? '');
        $settings['default_mailbox_username'] = $default_mailbox_username;
        if ($default_mailbox_password !== '') {
            $encrypted_password = self::encrypt_secret($default_mailbox_password);
            if (is_wp_error($encrypted_password)) {
                return $encrypted_password;
            }
            $settings['default_mailbox_secret'] = $encrypted_password;
        }
        if ($default_mailbox_username === '') {
            $settings['default_mailbox_secret'] = '';
            return new WP_Error('default_mailbox_required', '请填写默认存储文件邮箱账号。');
        }
        if (self::decrypt_secret((string) ($settings['default_mailbox_secret'] ?? '')) === '') {
            return new WP_Error('default_mailbox_password_required', '请填写默认存储文件邮箱密码。');
        }
        unset($settings['shared_account_user_id'], $settings['shared_account_roles'], $settings['sftp_username'], $settings['sftp_secret'], $settings['api_username'], $settings['rewrite_enabled']);
        return $settings;
    }

    private static function brand_name() {
        $name = trim((string) (self::settings()['gallery_name'] ?? ''));
        return $name !== '' ? $name : self::DEFAULT_GALLERY_NAME;
    }

    /** 设置页和独立邮箱登录页共用的 NameCrane 入口。 */
    private static function render_namecrane_portal_buttons() {
        $settings = self::settings();
        $webmail_url = untrailingslashit((string) ($settings['api_base'] ?? '')) . '/';
        ?>
        <p style="margin:16px 0 12px"><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="https://namecrane.com/clientarea.php">NameCrane Mail控制台</a> <a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($webmail_url); ?>">打开网页版邮箱</a></p>
        <?php
    }

    /** 个性化设置中复用的上传规则编辑器。 */
    private static function render_upload_rule_fields($name, $rule, $id_prefix) {
        $rule = self::normalize_upload_rule($rule);
        $id_prefix = sanitize_html_class($id_prefix);
        ?>
        <div class="yxf-upload-rule-fields">
            <div class="yxf-upload-rule-basics">
                <label><span>单次批量上传数量</span><span class="yxf-upload-rule-input"><input type="number" min="1" max="100" name="<?php echo esc_attr($name); ?>[max_files]" value="<?php echo esc_attr($rule['max_files']); ?>"><em>个</em></span></label>
                <?php foreach (self::supported_upload_formats() as $kind => $group) : $limit = $rule['max_sizes'][$kind]; ?>
                    <label><span><?php echo esc_html($group['label']); ?>单个文件上限</span><span class="yxf-upload-rule-input"><input type="number" min="1" max="1024" step="0.01" name="<?php echo esc_attr($name); ?>[max_sizes][<?php echo esc_attr($kind); ?>][value]" value="<?php echo esc_attr(rtrim(rtrim(number_format((float) $limit['value'], 2, '.', ''), '0'), '.')); ?>">
                    <select name="<?php echo esc_attr($name); ?>[max_sizes][<?php echo esc_attr($kind); ?>][unit]">
                        <?php foreach (array('KB', 'MB', 'GB') as $unit) : ?><option value="<?php echo esc_attr($unit); ?>" <?php selected($limit['unit'], $unit); ?>><?php echo esc_html($unit); ?></option><?php endforeach; ?>
                    </select></span></label>
                <?php endforeach; ?>
            </div>
            <?php $format_summary = implode('、', array_map('strtoupper', $rule['extensions'])); ?>
            <button type="button" class="yxf-upload-rule-formats-open" data-yxf-open-formats onclick="var m=this.parentNode.querySelector('.yxf-upload-rule-format-modal');if(m){m.hidden=false;}"><span>允许上传格式 <b data-yxf-format-summary>已选 <?php echo esc_html(count($rule['extensions'])); ?> 种：<?php echo esc_html($format_summary); ?></b></span><em>选择格式</em></button>
            <div class="yxf-upload-rule-format-modal" hidden role="dialog" aria-modal="true" aria-label="选择允许上传的格式" data-yxf-default-formats="<?php echo esc_attr(wp_json_encode(self::default_upload_rule()['extensions'])); ?>"><div class="yxf-upload-rule-format-mask" data-yxf-close-formats onclick="this.parentNode.hidden=true;"></div><div class="yxf-upload-rule-format-dialog"><button type="button" class="yxf-upload-rule-format-close" data-yxf-close-formats aria-label="关闭格式选择" onclick="this.closest('.yxf-upload-rule-format-modal').hidden=true;">×</button><h3>选择允许上传的格式</h3>
            <div class="yxf-upload-rule-formats-head"><div><p>默认仅开放常用图片；如要上传视频、文档或压缩包，再展开相应类别勾选即可。</p><p class="yxf-upload-rule-format-actions"><button type="button" class="button" data-yxf-format-action="all">全选</button><button type="button" class="button" data-yxf-format-action="none">取消所有</button><button type="button" class="button" data-yxf-format-action="default">恢复默认</button></p></div></div>
            <div class="yxf-upload-rule-format-groups">
            <?php foreach (self::supported_upload_formats() as $group_key => $group) : ?>
                <?php $is_image_group = $group_key === 'image'; ?>
                <<?php echo $is_image_group ? 'div' : 'details'; ?> class="yxf-upload-rule-format-group"<?php echo $is_image_group ? '' : ''; ?>><<?php echo $is_image_group ? 'div' : 'summary'; ?> class="yxf-upload-rule-format-title"><span><?php echo esc_html($group['label']); ?></span><small><?php echo esc_html(implode('、', array_map('strtoupper', array_slice($group['extensions'], 0, 4)))); ?><?php echo count($group['extensions']) > 4 ? '…' : ''; ?></small></<?php echo $is_image_group ? 'div' : 'summary'; ?>><div class="yxf-upload-rule-tags">
                    <?php foreach ($group['extensions'] as $extension) : $id = $id_prefix . '-' . $extension; ?>
                        <input class="yxf-upload-rule-format-input" id="<?php echo esc_attr($id); ?>" type="checkbox" name="<?php echo esc_attr($name); ?>[extensions][]" value="<?php echo esc_attr($extension); ?>" <?php checked(in_array($extension, $rule['extensions'], true)); ?>><label class="yxf-upload-rule-format" for="<?php echo esc_attr($id); ?>"><?php echo esc_html(strtoupper($extension)); ?></label>
                    <?php endforeach; ?>
                </div></<?php echo $is_image_group ? 'div' : 'details'; ?>>
            <?php endforeach; ?>
            </div>
            </div></div>
        </div>
        <?php
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

    /** 独立邮箱账号仅对“个性化设置”中明确授权的角色或用户开放。 */
    private static function can_use_independent_login($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id) {
            return false;
        }
        $settings = self::settings();
        if (in_array($user_id, array_map('absint', (array) ($settings['independent_login_user_ids'] ?? array())), true)) {
            return true;
        }
        $user = get_userdata($user_id);
        $roles = array_intersect(
            array('administrator', 'editor', 'author', 'contributor', 'subscriber'),
            array_map('sanitize_key', (array) ($settings['independent_login_roles'] ?? array()))
        );
        return $user && (bool) array_intersect($roles, (array) $user->roles);
    }

    /** NameCrane 邮箱网盘可保存的常见文件格式；实际限制始终由本插件规则决定。 */
    private static function supported_upload_formats() {
        return array(
            'image' => array('label' => '图片', 'extensions' => array('jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'heic', 'ico', 'svg')),
            'video' => array('label' => '视频', 'extensions' => array('mp4', 'm4v', 'mov', 'webm', 'avi', 'mkv')),
            'audio' => array('label' => '音频', 'extensions' => array('mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac')),
            'document' => array('label' => '文档', 'extensions' => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'md', 'rtf')),
            'archive' => array('label' => '压缩包', 'extensions' => array('zip', 'rar', '7z', 'tar', 'gz')),
        );
    }

    private static function supported_upload_extensions() {
        $extensions = array();
        foreach (self::supported_upload_formats() as $group) {
            foreach ($group['extensions'] as $extension) {
                $extensions[] = $extension;
            }
        }
        return array_values(array_unique($extensions));
    }

    private static function default_upload_rule() {
        return array(
            'max_files' => 5,
            'max_sizes' => array(
                'image'    => array('value' => 1, 'unit' => 'MB'),
                'video'    => array('value' => 10, 'unit' => 'MB'),
                'audio'    => array('value' => 5, 'unit' => 'MB'),
                'document' => array('value' => 500, 'unit' => 'KB'),
                'archive'  => array('value' => 50, 'unit' => 'MB'),
            ),
            // 默认只开放常用图片格式，其他格式由管理员按角色或用户按需开启。
            'extensions' => array('jpg', 'jpeg', 'png', 'webp', 'gif'),
        );
    }

    private static function normalize_upload_rule($rule) {
        $default = self::default_upload_rule();
        $rule = is_array($rule) ? $rule : array();
        $legacy_limit = null;
        $legacy_default_rule = false;
        if (isset($rule['max_size_value']) || isset($rule['max_size_unit'])) {
            $legacy_unit = strtoupper(sanitize_key((string) ($rule['max_size_unit'] ?? 'MB')));
            $legacy_value = (float) ($rule['max_size_value'] ?? 1);
            // 旧版默认值是统一的 1MB；升级后改用新的分类默认值。
            if (!($legacy_value === 1.0 && $legacy_unit === 'MB')) {
                $legacy_limit = array('value' => $legacy_value, 'unit' => in_array($legacy_unit, array('KB', 'MB', 'GB'), true) ? $legacy_unit : 'MB');
            } elseif ((int) ($rule['max_files'] ?? 10) === 10) {
                $legacy_default_rule = true;
            }
        }
        $max_sizes = array();
        foreach ($default['max_sizes'] as $kind => $fallback) {
            $raw_limit = is_array($rule['max_sizes'][$kind] ?? null) ? $rule['max_sizes'][$kind] : ($legacy_limit ?: $fallback);
            $unit = strtoupper(sanitize_key((string) ($raw_limit['unit'] ?? $fallback['unit'])));
            $max_sizes[$kind] = array(
                'value' => max(1, min(1024, (float) ($raw_limit['value'] ?? $fallback['value']))),
                'unit'  => in_array($unit, array('KB', 'MB', 'GB'), true) ? $unit : $fallback['unit'],
            );
        }
        $has_requested_extensions = array_key_exists('extensions', $rule);
        $extensions = array_values(array_intersect(
            self::supported_upload_extensions(),
            array_values(array_unique(array_map('sanitize_key', (array) ($has_requested_extensions ? $rule['extensions'] : $default['extensions']))))
        ));
        // 仅在旧数据没有该字段时回退默认；管理员点击“取消所有”时必须保留空选择。
        if (!$extensions && !$has_requested_extensions) {
            $extensions = $default['extensions'];
        }
        $max_files = $legacy_default_rule ? $default['max_files'] : max(1, min(100, absint($rule['max_files'] ?? $default['max_files'])));
        return array('max_files' => $max_files, 'max_sizes' => $max_sizes, 'extensions' => $extensions);
    }

    private static function upload_rule_size_bytes($rule, $kind = 'image') {
        $rule = self::normalize_upload_rule($rule);
        $limit = $rule['max_sizes'][$kind] ?? $rule['max_sizes']['document'];
        $multiplier = $limit['unit'] === 'GB' ? GB_IN_BYTES : ($limit['unit'] === 'KB' ? KB_IN_BYTES : MB_IN_BYTES);
        return (int) min(PHP_INT_MAX, round($limit['value'] * $multiplier));
    }

    private static function upload_rule_size_label($rule, $kind = 'image') {
        $rule = self::normalize_upload_rule($rule);
        $limit = $rule['max_sizes'][$kind] ?? $rule['max_sizes']['document'];
        $value = rtrim(rtrim(number_format((float) $limit['value'], 2, '.', ''), '0'), '.');
        return $value . $limit['unit'];
    }

    private static function upload_kind_from_extension($extension) {
        foreach (self::supported_upload_formats() as $kind => $group) {
            if (in_array($extension, $group['extensions'], true)) {
                return $kind;
            }
        }
        return 'document';
    }

    private static function upload_rule_limits_by_extension($rule) {
        $rule = self::normalize_upload_rule($rule);
        $limits = array();
        foreach ($rule['extensions'] as $extension) {
            $kind = self::upload_kind_from_extension($extension);
            $limits[$extension] = array('bytes' => self::upload_rule_size_bytes($rule, $kind), 'label' => self::upload_rule_size_label($rule, $kind));
        }
        return $limits;
    }

    private static function upload_rule_limits_summary($rule) {
        $rule = self::normalize_upload_rule($rule);
        $parts = array();
        foreach (self::supported_upload_formats() as $kind => $group) {
            if (array_intersect($group['extensions'], $rule['extensions'])) {
                $parts[] = $group['label'] . '≤' . self::upload_rule_size_label($rule, $kind);
            }
        }
        return implode('，', $parts);
    }

    private static function upload_rule_type_requirements($rule) {
        $rule = self::normalize_upload_rule($rule);
        $parts = array();
        foreach (self::supported_upload_formats() as $kind => $group) {
            $extensions = array_values(array_intersect($group['extensions'], $rule['extensions']));
            if ($extensions) {
                $parts[] = array(
                    'kind'       => $kind,
                    'label'      => $group['label'],
                    'size_label' => self::upload_rule_size_label($rule, $kind),
                    'extensions' => $extensions,
                );
            }
        }
        return $parts;
    }

    /** 指定用户优先；用户拥有多个角色时按管理员、编辑、作者、贡献者、订阅者的顺序生效。 */
    private static function upload_rule_for_user($user_id = 0) {
        $settings = self::settings();
        $default = self::normalize_upload_rule($settings['upload_default_rule'] ?? array());
        // 角色和指定用户的覆盖规则属于授权功能；试用和授权撤销后统一回退到默认规则。
        if (!self::has_paid_authorization()) {
            return $default;
        }
        $user_id = absint($user_id ?: get_current_user_id());
        $user_rules = is_array($settings['upload_user_rules'] ?? null) ? $settings['upload_user_rules'] : array();
        if ($user_id && isset($user_rules[$user_id])) {
            return self::normalize_upload_rule($user_rules[$user_id]);
        }
        $user = $user_id ? get_userdata($user_id) : null;
        $role_rules = is_array($settings['upload_role_rules'] ?? null) ? $settings['upload_role_rules'] : array();
        foreach (array('administrator', 'editor', 'author', 'contributor', 'subscriber') as $role) {
            if ($user && in_array($role, (array) $user->roles, true) && isset($role_rules[$role])) {
                return self::normalize_upload_rule($role_rules[$role]);
            }
        }
        return $default;
    }

    private static function upload_rule_accept_attribute($rule) {
        return implode(',', array_map(static function ($extension) { return '.' . $extension; }, self::normalize_upload_rule($rule)['extensions']));
    }

    private static function media_kind_from_mime($mime) {
        $kind = strtok((string) $mime, '/');
        return in_array($kind, array('image', 'video', 'audio'), true) ? $kind : 'file';
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

    /** 可选隐藏后台左侧的 WordPress 原生“媒体”菜单，直接地址与媒体接口保持不变。 */
    public static function maybe_hide_wordpress_media_menu() {
        $settings = self::settings();
        if (empty($settings['replace_media_library']) || empty($settings['hide_wordpress_media_menu'])) {
            return;
        }
        remove_menu_page('upload.php');
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
        // 前后台都加载 WordPress 标准媒体框，任何主题只要调用 wp.media 都会进入 NameCrane 弹窗。
        wp_enqueue_media();
        $dependencies[] = 'media-views';
        if (is_admin()) {
            $dependencies[] = 'thickbox';
        }
        // 所有主题和后台统一接管 WordPress 标准媒体接口，不再依赖特定主题的上传按钮。
        $asset_file = 'assets/media-replacement.js';
        $asset_path = plugin_dir_path(__FILE__) . $asset_file;
        wp_enqueue_script(
            'yxf-gallery-media-replacement',
            plugin_dir_url(__FILE__) . $asset_file,
            $dependencies,
            is_file($asset_path) ? (string) filemtime($asset_path) : self::VERSION,
            // 先于主题编辑器加载，确保其随后调用的 WordPress 媒体接口已被接管。
            false
        );
        wp_localize_script('yxf-gallery-media-replacement', 'YXFGalleryReplacement', array(
            // 前台统一使用站点自身地址，不触发 wp-admin 的权限和 AJAX 默认响应。
            'iframeUrl' => home_url('/'),
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'loginUrl'  => self::login_url(),
            'hasLogin'  => self::user_has_login(),
            'enabled'   => true,
        ));
    }

    /** 使用 NameCrane 外链保存子比前台话题/标签封面，不复制文件到本地媒体库。 */
    public static function maybe_save_zibll_forum_term() {
        $cover_url = esc_url_raw(wp_unslash($_POST['yxf_gallery_term_cover_url'] ?? ''));
        $attachment_id = absint($_POST['yxf_gallery_term_cover_attachment_id'] ?? 0);
        if ($cover_url === '' || !$attachment_id) {
            return;
        }

        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        $taxonomy = str_replace('save_', '', $action);
        if (!in_array($taxonomy, array('forum_topic', 'forum_tag'), true)) {
            return;
        }

        if (function_exists('zib_ajax_verify_nonce')) {
            zib_ajax_verify_nonce('save_bbs');
        } else {
            check_ajax_referer('save_bbs');
        }

        $term_id = absint($_REQUEST['term_id'] ?? 0);
        $can_save = function_exists('zib_bbs_current_user_can')
            && zib_bbs_current_user_can($taxonomy . ($term_id ? '_edit' : '_add'), $term_id);
        if (!$can_save) {
            self::send_zibll_forum_term_error('您没有保存此内容的权限。');
        }

        $item_id = absint(get_post_meta($attachment_id, '_yxf_gallery_item_id', true));
        global $wpdb;
        $item = $item_id ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE id = %d AND status = %s LIMIT 1',
            $item_id,
            'ready'
        )) : null;
        $item_url = $item ? esc_url_raw(self::item_public_url($item)) : '';
        $can_use_item = $item
            && (int) ($item->attachment_id ?? 0) === $attachment_id
            && strpos((string) ($item->mime_type ?? ''), 'image/') === 0
            && hash_equals($item_url, $cover_url)
            && (self::can_administer() || (int) ($item->author_id ?? 0) === get_current_user_id());
        if (!$can_use_item) {
            self::send_zibll_forum_term_error('所选图片无效，请重新从 NameCrane媒体库选择。');
        }

        $name = function_exists('zib_bbs_get_taxonomy_name') ? zib_bbs_get_taxonomy_name($taxonomy) : ($taxonomy === 'forum_topic' ? '话题' : '标签');
        $title = !empty($_POST['title']) ? strip_tags(trim(wp_unslash($_POST['title']))) : '';
        $content = !empty($_POST['desc']) ? strip_tags(trim(wp_unslash($_POST['desc']))) : '';
        $slug = !empty($_POST['slug']) ? strip_tags(trim(wp_unslash($_POST['slug']))) : '';
        $length = static function ($value) {
            return function_exists('zib_new_strlen') ? zib_new_strlen($value) : mb_strlen($value);
        };

        if ($title === '') self::send_zibll_forum_term_error('请输入' . $name . '标题。', 'warning');
        if ($length($title) > 10) self::send_zibll_forum_term_error('标题太长，不能超过10个字。');
        if ($length($title) <= 1) self::send_zibll_forum_term_error('标题太短！');
        if ($content === '') self::send_zibll_forum_term_error('请输入' . $name . '简介。', 'warning');
        if ($length($content) > 50) self::send_zibll_forum_term_error('简介太长，不能超过50个字。');
        if ($length($content) < 5) self::send_zibll_forum_term_error('简介太短！');

        if (function_exists('_pz') && _pz('audit_bbs_term') && class_exists('ZibAudit')) {
            ZibAudit::ajax_text($title . $content);
        }

        $args = array('name' => $title, 'description' => $content, 'slug' => $slug);
        $result = $term_id ? wp_update_term($term_id, $taxonomy, $args) : wp_insert_term($title, $taxonomy, $args);
        if (is_wp_error($result)) {
            self::send_zibll_forum_term_error($result->get_error_message());
        }

        $saved_term_id = absint($result['term_id'] ?? 0);
        if (function_exists('zib_update_term_meta')) {
            zib_update_term_meta($saved_term_id, 'cover_image', $item_url);
        } else {
            update_term_meta($saved_term_id, 'cover_image', $item_url);
        }
        if (isset($_POST['add_limit'])) {
            update_term_meta($saved_term_id, 'add_limit', sanitize_text_field(wp_unslash($_POST['add_limit'])));
        }
        if (function_exists('zib_flush_rewrite_rules')) {
            zib_flush_rewrite_rules();
        }

        $data = array(
            'image_url'  => $item_url,
            'term_url'   => get_term_link($saved_term_id),
            'msg'        => $name . ($term_id ? '编辑' : '创建') . '成功',
            'term'       => get_term($saved_term_id, $taxonomy),
            'term_id'    => $saved_term_id,
            'taxonomy'   => $taxonomy,
            'type'       => $term_id ? 'update' : 'add',
            'hide_modal' => true,
        );
        if (function_exists('zib_send_json_success')) {
            zib_send_json_success($data);
        }
        wp_send_json_success($data);
    }

    private static function send_zibll_forum_term_error($message, $type = 'danger') {
        if (function_exists('zib_send_json_error')) {
            zib_send_json_error($message, $type);
        }
        wp_send_json_error(array('message' => $message), 400);
    }

    /** 将 WordPress 后台的媒体库列表与“添加媒体”页统一转到 NameCrane 媒体库。 */
    public static function redirect_core_media_pages() {
        if (!self::media_replacement_enabled() || wp_doing_ajax()) {
            return;
        }
        global $pagenow;
        if ($pagenow === 'upload.php') {
            wp_safe_redirect(admin_url('admin.php?page=yxf-gallery'));
            exit;
        }
        if ($pagenow === 'media-new.php') {
            wp_safe_redirect(admin_url('admin.php?page=yxf-gallery-upload'));
            exit;
        }
    }

    /**
     * 前端主题编辑器每次读取“我的图片 / 我的文件”前，确保当前用户已有的图库文件
     * 都有可被主题识别的外链媒体记录。不会写入网站的 uploads 目录。
     */
    public static function prepare_frontend_editor_attachments() {
        if (!self::media_replacement_enabled() || !is_user_logged_in()) {
            return;
        }
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE status = 'ready' AND author_id = %d ORDER BY id DESC LIMIT 500",
            get_current_user_id()
        ));
        foreach ($items as $item) {
            self::ensure_virtual_attachment($item);
        }
    }

    /**
     * 仅收窄子比前端 AJAX 的附件查询，让“我的图片 / 我的文件”只展示图库文件。
     * 后台媒体库、主题设置、分类封面等请求不满足这个条件，保持原样。
     */
    public static function filter_frontend_editor_attachments($query) {
        if (!self::media_replacement_enabled() || !wp_doing_ajax()) {
            return;
        }
        $action = sanitize_key(wp_unslash($_REQUEST['action'] ?? ''));
        if ($action !== 'current_user_attachments' || $query->get('post_type') !== 'attachment') {
            return;
        }
        $meta_query = $query->get('meta_query');
        $meta_query = is_array($meta_query) ? $meta_query : array();
        $meta_query[] = array('key' => '_yxf_gallery_item_id', 'compare' => 'EXISTS');
        $query->set('meta_query', $meta_query);
        if (!self::can_administer()) {
            $query->set('author', get_current_user_id());
        }
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
            ? '媒体上传已由' . self::brand_name() . '接管，请点击“上传到' . self::brand_name() . '”。'
            : '请先登录 NameCrane 邮箱后再上传媒体。';
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

    private static function default_mailbox_account() {
        $settings = self::settings();
        return array(
            'username' => trim((string) ($settings['default_mailbox_username'] ?? '')),
            'secret'   => (string) ($settings['default_mailbox_secret'] ?? ''),
        );
    }

    /** 未使用独立邮箱账号的用户，统一使用管理员设置的默认存储文件邮箱。 */
    private static function shared_account_owner_for_user($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id || (self::can_use_independent_login($user_id) && self::user_has_own_login($user_id))) {
            return 0;
        }
        $default_account = self::default_mailbox_account();
        if ($default_account['username'] === '' || self::decrypt_secret($default_account['secret']) === '') {
            return 0;
        }
        return self::DEFAULT_MAILBOX_OWNER_ID;
    }

    private static function effective_account_owner_id($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        if (!$user_id) {
            return 0;
        }
        return (self::can_use_independent_login($user_id) && self::user_has_own_login($user_id)) ? $user_id : self::shared_account_owner_for_user($user_id);
    }

    private static function is_using_shared_account($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $owner_id = self::effective_account_owner_id($user_id);
        return $owner_id === self::DEFAULT_MAILBOX_OWNER_ID;
    }

    private static function storage_settings_for_user($user_id = 0) {
        // 上传流程和历史文件删除流程都会传入实际的存储归属；默认邮箱账号使用保留标记，不能再当作 WordPress 用户 ID 重新解析。
        $owner_id = (int) $user_id === self::DEFAULT_MAILBOX_OWNER_ID ? self::DEFAULT_MAILBOX_OWNER_ID : self::effective_account_owner_id($user_id);
        $account = $owner_id === self::DEFAULT_MAILBOX_OWNER_ID ? self::default_mailbox_account() : ($owner_id ? self::account($owner_id) : array('username' => '', 'secret' => ''));
        $settings = self::settings();
        $settings['sftp_username'] = $account['username'];
        $settings['api_username'] = $account['username'];
        return array($settings, self::decrypt_secret($account['secret']));
    }

    private static function user_has_login($user_id = 0) {
        return self::effective_account_owner_id($user_id) !== 0;
    }

    private static function login_url() {
        return admin_url('admin.php?page=yxf-gallery-login');
    }

    private static function redirect_to_login() {
        wp_safe_redirect(add_query_arg('yxf_gallery_notice', 'login_required', self::login_url()));
        exit;
    }

    /** 新安装启用后先完成基础配置，避免直接进入空图库页面。 */
    public static function maybe_redirect_after_activation() {
        if (!is_admin() || wp_doing_ajax() || !self::can_administer() || !get_transient(self::ACTIVATION_REDIRECT_TRANSIENT)) {
            return;
        }
        delete_transient(self::ACTIVATION_REDIRECT_TRANSIENT);
        wp_safe_redirect(add_query_arg(array('page' => 'yxf-gallery-settings', 'tab' => 'basic'), admin_url('admin.php')));
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
        if (!in_array($page, array('yxf-gallery', 'yxf-gallery-upload', 'yxf-gallery-manage'), true) || self::user_has_login()) {
            return;
        }
        self::redirect_to_login();
    }

    private static function plugin_file() {
        return plugin_basename(__FILE__);
    }

    private static function service_url() {
        // 授权价格、授权状态和更新信息必须始终以游先锋正式站为准，
        // 不能被本地主题的开发接口覆盖，否则测试站会显示过期价格。
        return untrailingslashit(self::SERVICE_URL);
    }

    private static function installation_id() {
        $id = (string) get_option(self::INSTALLATION_ID_OPTION, '');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            $id = wp_generate_uuid4();
            update_option(self::INSTALLATION_ID_OPTION, $id, false);
        }
        return $id;
    }

    private static function service_payload() {
        return array(
            'installation_id' => self::installation_id(),
            'site_url' => home_url('/'),
            'plugin_version' => self::VERSION,
        );
    }

    private static function service_request($route, $extra = array()) {
        $response = wp_remote_post(self::service_url() . '/' . ltrim($route, '/'), array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'NameCrane-Mail-Gallery/' . self::VERSION),
            'body' => wp_json_encode(array_merge(self::service_payload(), (array) $extra)),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ((int) wp_remote_retrieve_response_code($response) !== 200 || !is_array($data)) {
            return new WP_Error('service_error', is_array($data) && !empty($data['message']) ? (string) $data['message'] : '授权服务暂时不可用。');
        }
        return $data;
    }

    private static function license_status($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::LICENSE_CACHE_KEY);
            if (is_array($cached) && !empty($cached['status'])) {
                return $cached;
            }
        }
        $status = self::service_request('license/verify');
        if (is_wp_error($status)) {
            return array('status' => 'service_unavailable', 'allowed' => false, 'message' => $status->get_error_message());
        }
        set_site_transient(self::LICENSE_CACHE_KEY, $status, HOUR_IN_SECONDS);
        return $status;
    }

    private static function license_allows_storage($force = false) {
        $status = self::license_status($force);
        if (empty($status['allowed'])) {
            return new WP_Error('license_required', (string) ($status['message'] ?? '授权已到期，请在“授权管理”完成支付后再连接邮件服务或上传新图片。'));
        }
        return true;
    }

    /** 个性化设置只对已付费开通的安装开放，免费试用不包含该功能。 */
    private static function has_paid_authorization($force = false) {
        $status = self::license_status($force);
        return !empty($status['allowed']) && sanitize_key((string) ($status['status'] ?? '')) === 'active';
    }

    private static function release_data($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::UPDATE_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $release = self::service_request('update');
        if (is_wp_error($release) || empty($release['version']) || empty($release['package'])) {
            return null;
        }
        set_site_transient(self::UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);
        return $release;
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
        $package = is_array($release) ? esc_url_raw((string) $release['package']) : '';
        $version = is_array($release) ? ltrim((string) $release['version'], 'vV') : '';
        if ($version === '' || $package === '' || !version_compare($version, self::VERSION, '>')) {
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$plugin_file] = (object) array(
                'slug'        => dirname($plugin_file),
                'plugin'      => $plugin_file,
                'new_version' => self::VERSION,
                'url'         => 'https://youxianfeng.com/',
                'package'     => '',
            );
            return $transient;
        }
        unset($transient->no_update[$plugin_file]);
        $transient->response[$plugin_file] = (object) array(
            'slug'        => dirname($plugin_file),
            'plugin'      => $plugin_file,
            'new_version' => $version,
            'url'         => 'https://youxianfeng.com/',
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
        $version = ltrim((string) $release['version'], 'vV');
        $package = esc_url_raw((string) ($release['package'] ?? ''));
        return (object) array(
            'name'          => 'NameCrane邮箱媒体库',
            'slug'          => dirname(self::plugin_file()),
            'version'       => $version ?: self::VERSION,
            'homepage'      => 'https://youxianfeng.com/',
            'download_link' => $package,
            'sections'      => array('description' => 'NameCrane 邮件存储图库与文章图片插入工具。', 'changelog' => wp_kses_post((string) ($release['changelog'] ?? ''))),
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
        echo '<div class="notice notice-success is-dismissible"><p>NameCrane邮箱媒体库已检查新版本。</p></div>';
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

    /** 连接失败只区分服务器信息和邮箱账号密码两类，避免向管理员暴露底层错误。 */
    private static function connection_failure_reason($component, $error) {
        $raw = trim((string) $error);
        $normalized = strtolower($raw);
        if (preg_match('/\b530\b|authentication|login incorrect|access denied|permission denied/', $normalized)) {
            return '邮箱账号密码不正确，请输入正确的邮箱账号和密码。';
        }
        return '服务器连接失败，请检查服务器信息是否正确。';
    }

    private static function storage_test($settings, $password) {
        if (empty($settings['sftp_host']) || empty($settings['sftp_remote_path'])) {
            return new WP_Error('sftp_missing', '服务器连接失败，请检查服务器信息是否正确。');
        }
        if (empty($settings['sftp_username'])) {
            return new WP_Error('sftp_username', '邮箱账号密码不正确，请输入正确的邮箱账号和密码。');
        }
        if ($password === '') {
            return new WP_Error('sftp_password', '邮箱账号密码不正确，请输入正确的邮箱账号和密码。');
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
        return $ok === false ? new WP_Error('storage_connection', self::connection_failure_reason($label, $error)) : true;
    }

    /** 默认存储邮箱必须同时通过文件存储和网页接口，才能正常上传并生成公开链接。 */
    private static function default_storage_connection_test() {
        list($settings, $password) = self::storage_settings_for_user(self::DEFAULT_MAILBOX_OWNER_ID);
        $storage_result = self::storage_test($settings, $password);
        if (is_wp_error($storage_result)) {
            return $storage_result;
        }
        $token = self::api_login($settings, $password);
        if (is_wp_error($token)) {
            return $token;
        }
        return self::api_request($settings, $token, 'GET', '/filestorage/folders');
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
        // 上传请求本身会建立并校验连接。此前每张图片上传前都会额外执行一次
        // storage_test()，小文件的耗时几乎都浪费在这次重复握手上。
        foreach (array('sftp_host' => '存储服务器', 'sftp_username' => '存储用户名', 'sftp_remote_path' => '目标目录') as $key => $label) {
            if (empty($settings[$key])) {
                return new WP_Error('sftp_missing', '请填写' . $label . '。');
            }
        }
        if ($password === '') {
            return new WP_Error('sftp_password', '请先登录游先锋邮箱。');
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
            return new WP_Error('api_base', '服务器连接失败，请检查服务器信息是否正确。');
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
            return new WP_Error('api_request', self::connection_failure_reason('网页接口', $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return new WP_Error('api_response', ($code === 401 || $code === 403) ? '邮箱账号密码不正确，请输入正确的邮箱账号和密码。' : '服务器连接失败，请检查服务器信息是否正确。');
        }
        return $data;
    }

    private static function api_login($settings, $password) {
        $username = $settings['api_username'] ?: $settings['sftp_username'];
        if ($username === '' || $password === '') {
            return new WP_Error('api_credentials', '邮箱账号密码不正确，请输入正确的邮箱账号和密码。');
        }
        $base = untrailingslashit($settings['api_base']);
        $response = wp_remote_post($base . '/api/v1/auth/authenticate-user', array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => wp_json_encode(array('username' => $username, 'password' => $password, 'clientId' => wp_generate_uuid4())),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('api_login', self::connection_failure_reason('网页接口', $response->get_error_message()));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401 || $code === 403) {
            return new WP_Error('api_login', '邮箱账号密码不正确，请输入正确的邮箱账号和密码。');
        }
        if ($code < 200 || $code >= 300 || empty($data['accessToken'])) {
            return new WP_Error('api_login', '服务器连接失败，请检查服务器信息是否正确。');
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

    private static function create_public_link($settings, $password, $file_name, $max_attempts = 10, $wait_microseconds = 1000000) {
        $token = self::api_login($settings, $password);
        if (is_wp_error($token)) {
            return $token;
        }
        $max_attempts = max(1, min(10, absint($max_attempts)));
        $wait_microseconds = max(0, absint($wait_microseconds));
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
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
            if ($attempt + 1 < $max_attempts && $wait_microseconds > 0) {
                usleep($wait_microseconds);
            }
        }
        return new WP_Error('file_not_found', '图片已上传，但游先锋邮箱尚未返回文件记录，因此未生成公开链接。');
    }

    /** 将公开链接解析排队，避免上传接口因邮箱网盘索引而长时间占用浏览器请求。 */
    private static function schedule_pending_link_resolution($item_id, $attempt = 0, $delay = 2) {
        $item_id = absint($item_id);
        $attempt = absint($attempt);
        if (!$item_id || $attempt >= 20) {
            return;
        }
        $args = array($item_id, $attempt);
        if (!wp_next_scheduled('yxf_gallery_resolve_pending_item', $args)) {
            wp_schedule_single_event(time() + max(1, absint($delay)), 'yxf_gallery_resolve_pending_item', $args);
        }
    }

    /**
     * 尝试一次公开链接解析；每次最多查询一次网盘，不在这里等待或轮询。
     *
     * @return object|WP_Error
     */
    private static function resolve_pending_item($item_id) {
        $item_id = absint($item_id);
        if (!$item_id) {
            return new WP_Error('missing_item', '未找到需要处理的图库文件。');
        }
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $item_id));
        if (!$item) {
            return new WP_Error('missing_item', '该图库文件已不存在。');
        }
        if ((string) $item->status === 'ready') {
            return $item;
        }
        $storage_owner_id = absint($item->storage_owner_id ?: $item->author_id);
        list($settings, $password) = self::storage_settings_for_user($storage_owner_id);
        if ($password === '') {
            return new WP_Error('login_required', '用于保存该图片的游先锋邮箱账号已不可用。');
        }
        $remote_file = wp_basename((string) $item->remote_path);
        if ($remote_file === '') {
            return new WP_Error('missing_remote_path', '该图片缺少邮箱网盘文件路径。');
        }
        // 单次查询即可返回，让前端和计划任务决定何时再查，不能在此阻塞十秒以上。
        $original_url = self::create_public_link($settings, $password, $remote_file, 1, 0);
        if (is_wp_error($original_url)) {
            return $original_url;
        }
        $updated = $wpdb->update(self::table_name(), array(
            'original_url' => $original_url,
            'output_url'   => self::rewrite_url($original_url),
            'status'       => 'ready',
        ), array('id' => $item_id), array('%s', '%s', '%s'), array('%d'));
        if ($updated === false) {
            return new WP_Error('record_update_failed', '公开链接已生成，但图库记录更新失败。');
        }
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $item_id));
        if ($item && self::media_replacement_enabled()) {
            $attachment_id = self::ensure_virtual_attachment($item);
            if ($attachment_id) {
                $item->attachment_id = $attachment_id;
            }
        }
        return $item ?: new WP_Error('record_missing_after_update', '公开链接已生成，但未能读取图库记录。');
    }

    /** 计划任务只做一次快速检查；未就绪时最多继续尝试二十次。 */
    public static function resolve_pending_item_event($item_id, $attempt = 0) {
        $result = self::resolve_pending_item($item_id);
        if (is_wp_error($result) || (string) ($result->status ?? '') !== 'ready') {
            self::schedule_pending_link_resolution($item_id, absint($attempt) + 1, 3);
        }
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
        $brand = self::brand_name();
        // WordPress 原生“媒体”位于菜单位置 10；使用位置 9 固定显示在它上方。
        add_menu_page($brand, $brand, self::CAPABILITY, 'yxf-gallery', array(__CLASS__, 'render_gallery_page'), 'dashicons-format-gallery', 9);
        // 主菜单可自定义名称；子菜单首项始终使用清晰的固定名称。
        add_submenu_page('yxf-gallery', '我的文件', '我的文件', self::CAPABILITY, 'yxf-gallery', array(__CLASS__, 'render_gallery_page'));
        add_submenu_page('yxf-gallery', '上传文件', '上传文件', self::CAPABILITY, 'yxf-gallery-upload', array(__CLASS__, 'render_upload_page'));
        if (self::can_use_independent_login()) {
            add_submenu_page('yxf-gallery', '登录邮箱', '登录邮箱', self::CAPABILITY, 'yxf-gallery-login', array(__CLASS__, 'render_login_page'));
        }
        add_submenu_page('yxf-gallery', '图库设置', '设置', 'manage_options', 'yxf-gallery-settings', array(__CLASS__, 'render_settings_page'));
        // 管理文件是管理员专用二级菜单，不出现在作者、编辑的图库菜单中。
        add_submenu_page('yxf-gallery', '管理文件', '管理文件', 'manage_options', 'yxf-gallery-manage', array(__CLASS__, 'render_manage_page'));
    }

    public static function handle_admin_actions() {
        if (!is_admin() || empty($_POST['yxf_gallery_action'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['yxf_gallery_action']));
        if ($action === 'save_settings' || $action === 'save_basic_settings') {
            if (!self::can_administer()) {
                wp_die('无权修改图库设置。');
            }
            check_admin_referer('yxf_gallery_settings');
            $settings = self::settings_from_request(self::settings());
            if (is_wp_error($settings)) {
                set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', $settings->get_error_message()), MINUTE_IN_SECONDS);
                self::redirect('yxf-gallery-settings', 'stored_notice', array('tab' => 'basic'));
            }
            $target_parts = wp_parse_url($settings['target_base']);
            if ($settings['source_host'] === '' || ($settings['target_base'] !== '' && (empty($target_parts['host']) || strtolower((string) ($target_parts['scheme'] ?? '')) !== 'https'))) {
                self::redirect('yxf-gallery-settings', 'settings_error', array('tab' => 'basic'));
            }
            $settings['connection_status'] = 'not_connected';
            $settings['connection_status_message'] = '';
            $settings['connection_checked_at'] = '';
            update_option(self::OPTION, $settings, false);
            // 保存与单独测试都必须检查同一套连接，避免保存了不可用配置却没有提示。
            $connection_result = self::default_storage_connection_test();
            $settings = self::settings();
            $settings['connection_checked_at'] = current_time('mysql');
            if (is_wp_error($connection_result)) {
                $settings['connection_status'] = 'failed';
                $settings['connection_status_message'] = $connection_result->get_error_message();
                update_option(self::OPTION, $settings, false);
                set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', $connection_result->get_error_message()), MINUTE_IN_SECONDS);
                self::redirect('yxf-gallery-settings', 'stored_notice', array('tab' => 'basic'));
            }
            $settings['connection_status'] = 'connected';
            $settings['connection_status_message'] = '';
            update_option(self::OPTION, $settings, false);
            set_transient('yxf_gallery_notice_' . get_current_user_id(), array('success', '设置已保存，文件存储和网页接口均连接成功。'), MINUTE_IN_SECONDS);
            self::redirect('yxf-gallery-settings', 'stored_notice', array('tab' => 'basic'));
        }

        if ($action === 'save_personalization_settings') {
            if (!self::can_administer()) {
                wp_die('无权修改图库设置。');
            }
            check_admin_referer('yxf_gallery_personalization');
            $settings = self::settings();
            $section = sanitize_key(wp_unslash($_POST['personalization_section'] ?? ''));
            if (!in_array($section, array('all', 'gallery_name', 'independent_login', 'upload_rules'), true)) {
                self::redirect('yxf-gallery-settings', 'settings_error', array('tab' => 'personalization'));
            }
            // 每项个性化功能分别判断授权；默认上传规则保持可用，角色和指定用户覆盖规则需要授权。
            $protected_sections = array('gallery_name', 'independent_login');
            $authorized = self::has_paid_authorization(true);
            // 未授权时，同一张表单仍可保存免费的上传限制；被锁定的项不会参与保存。
            $requested_sections = $section === 'all'
                ? array_merge(array('upload_rules'), $authorized ? $protected_sections : array())
                : array($section);
            if (array_intersect($requested_sections, $protected_sections) && !$authorized) {
                set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', '该功能需授权后才可使用。'), MINUTE_IN_SECONDS);
                self::redirect('yxf-gallery-settings', 'stored_notice', array('tab' => 'personalization'));
            }
            if (in_array('gallery_name', $requested_sections, true)) {
                $name = sanitize_text_field(wp_unslash($_POST['gallery_name'] ?? ''));
                $settings['gallery_name'] = $name !== '' ? $name : self::DEFAULT_GALLERY_NAME;
            }
            if (in_array('independent_login', $requested_sections, true)) {
                $allowed_roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
                $requested_roles = isset($_POST['independent_login_roles']) && is_array($_POST['independent_login_roles']) ? array_map('sanitize_key', wp_unslash($_POST['independent_login_roles'])) : array();
                $requested_users = isset($_POST['independent_login_user_ids']) && is_array($_POST['independent_login_user_ids']) ? array_map('absint', wp_unslash($_POST['independent_login_user_ids'])) : array();
                $settings['independent_login_roles'] = array_values(array_intersect($allowed_roles, $requested_roles));
                $settings['independent_login_user_ids'] = array_values(array_filter($requested_users, 'get_userdata'));
            }
            if (in_array('upload_rules', $requested_sections, true)) {
                $settings['upload_default_rule'] = self::normalize_upload_rule(wp_unslash($_POST['upload_default_rule'] ?? array()));
                if ($authorized) {
                    $allowed_roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
                    $raw_role_rules = isset($_POST['upload_role_rules']) && is_array($_POST['upload_role_rules']) ? wp_unslash($_POST['upload_role_rules']) : array();
                    $role_rules = array();
                    foreach ($allowed_roles as $role) {
                        if (!empty($raw_role_rules[$role]['enabled']) && isset($raw_role_rules[$role]) && is_array($raw_role_rules[$role])) {
                            $role_rules[$role] = self::normalize_upload_rule($raw_role_rules[$role]);
                        }
                    }
                    $raw_user_rules = isset($_POST['upload_user_rules']) && is_array($_POST['upload_user_rules']) ? wp_unslash($_POST['upload_user_rules']) : array();
                    $user_rules = array();
                    foreach ($raw_user_rules as $user_id => $rule) {
                        $user_id = absint($user_id);
                        if ($user_id && get_userdata($user_id) && is_array($rule)) {
                            $user_rules[$user_id] = self::normalize_upload_rule($rule);
                        }
                    }
                    $settings['upload_role_rules'] = $role_rules;
                    $settings['upload_user_rules'] = $user_rules;
                }
            }
            update_option(self::OPTION, $settings, false);
            self::redirect('yxf-gallery-settings', 'settings_saved', array('tab' => 'personalization'));
        }

        if ($action === 'start_license_checkout') {
            if (!self::can_administer()) {
                wp_die('无权修改图库授权。');
            }
            check_admin_referer('yxf_gallery_license');
            $plan = sanitize_key(wp_unslash($_POST['license_plan'] ?? ''));
            if ($plan !== 'lifetime') {
                self::redirect('yxf-gallery-settings', 'license_order_error', array('tab' => 'license'));
            }
            $checkout = self::service_request('license/checkout', array('plan' => $plan));
            if (is_wp_error($checkout) || empty($checkout['order_token']) || (empty($checkout['url_qrcode']) && empty($checkout['url']))) {
                $message = is_wp_error($checkout) ? $checkout->get_error_message() : '暂时无法创建支付订单，请稍后重试。';
                set_transient('yxf_gallery_notice_' . get_current_user_id(), array('error', $message), MINUTE_IN_SECONDS);
                self::redirect('yxf-gallery-settings', 'stored_notice', array('tab' => 'license'));
            }
            set_transient('yxf_gallery_license_payment_' . get_current_user_id(), $checkout, 30 * MINUTE_IN_SECONDS);
            self::redirect('yxf-gallery-settings', 'settings_saved', array('tab' => 'license'));
        }

        if ($action === 'save_login' || $action === 'test_login') {
            if (!self::can_use_gallery()) {
                wp_die('无权测试存储连接。');
            }
            check_admin_referer('yxf_gallery_login');
            $user_id = get_current_user_id();
            if (!self::can_use_independent_login($user_id)) {
                self::redirect('yxf-gallery-login', 'independent_login_denied');
            }
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
            $license = self::license_allows_storage(true);
            if (is_wp_error($license)) {
                set_transient('yxf_gallery_notice_' . $user_id, array('error', $license->get_error_message()), MINUTE_IN_SECONDS);
                self::redirect('yxf-gallery-login', 'stored_notice');
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

    /** 无刷新创建付款订单，避免生成付款码时整页跳转闪烁。 */
    public static function ajax_start_license_checkout() {
        if (!self::can_administer()) {
            wp_send_json_error(array('message' => '无权创建授权订单。'), 403);
        }
        check_ajax_referer('yxf_gallery_license', 'nonce');

        $checkout = self::service_request('license/checkout', array('plan' => 'lifetime'));
        if (is_wp_error($checkout) || empty($checkout['order_token']) || (empty($checkout['url_qrcode']) && empty($checkout['url']))) {
            $message = is_wp_error($checkout) ? $checkout->get_error_message() : '暂时无法创建支付订单，请稍后重试。';
            wp_send_json_error(array('message' => $message), 502);
        }

        set_transient('yxf_gallery_license_payment_' . get_current_user_id(), $checkout, 30 * MINUTE_IN_SECONDS);
        wp_send_json_success($checkout);
    }

    /** 授权付款码显示在购买者自己的 WordPress 后台；轮询仅查询当前管理员刚创建的订单。 */
    public static function ajax_license_order_status() {
        if (!self::can_administer()) {
            wp_send_json_error(array('message' => '无权查询授权订单。'), 403);
        }
        check_ajax_referer('yxf_gallery_license_status', 'nonce');
        $payment = get_transient('yxf_gallery_license_payment_' . get_current_user_id());
        $token = is_array($payment) ? sanitize_text_field((string) ($payment['order_token'] ?? '')) : '';
        if ($token === '') {
            wp_send_json_error(array('message' => '支付订单已失效，请重新发起付款。'), 410);
        }
        $status = self::service_request('license/checkout-status', array('order_token' => $token));
        if (is_wp_error($status)) {
            wp_send_json_error(array('message' => $status->get_error_message()), 502);
        }
        if (!empty($status['paid']) && !empty($status['authorized'])) {
            delete_site_transient(self::LICENSE_CACHE_KEY);
            delete_site_transient(self::UPDATE_CACHE_KEY);
            delete_transient('yxf_gallery_license_payment_' . get_current_user_id());
        }
        wp_send_json_success($status);
    }

    private static function upload_mime_for_extension($extension) {
        $fallbacks = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'heic' => 'image/heic', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/m4a', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'flac' => 'audio/flac',
            'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'txt' => 'text/plain', 'csv' => 'text/csv', 'md' => 'text/markdown', 'rtf' => 'application/rtf',
            'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar', 'gz' => 'application/gzip',
        );
        return $fallbacks[$extension] ?? 'application/octet-stream';
    }

    /** 服务器端的格式和大小校验，优先级高于所有主题在浏览器内设置的限制。 */
    private static function validate_gallery_upload_file($tmp_name, $file_name, $file_size, $rule = null) {
        $file_name = sanitize_file_name($file_name);
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::supported_upload_extensions(), true)) {
            return new WP_Error('unsupported_file', '该文件格式不在 NameCrane媒体库允许的格式列表中。');
        }
        if ($rule !== null) {
            $rule = self::normalize_upload_rule($rule);
            if (!in_array($extension, $rule['extensions'], true)) {
                return new WP_Error('file_type_denied', '当前账号不允许上传 .' . $extension . ' 格式的文件。');
            }
            $kind = self::upload_kind_from_extension($extension);
            if ((int) $file_size > self::upload_rule_size_bytes($rule, $kind)) {
                return new WP_Error('too_large', '文件大小超过当前上传权限限制，' . self::supported_upload_formats()[$kind]['label'] . '单个文件最大 ' . self::upload_rule_size_label($rule, $kind) . '。');
            }
        }
        $type = wp_check_filetype_and_ext($tmp_name, $file_name);
        $mime = sanitize_mime_type((string) ($type['type'] ?? ''));
        if ($mime === '') {
            $mime = self::upload_mime_for_extension($extension);
        }
        return array('file_name' => $file_name, 'extension' => $extension, 'mime' => $mime);
    }

    /** 上传文件并以内容指纹去重，供普通表单和队列接口共用。 */
    private static function upload_gallery_file($file) {
        $license = self::license_allows_storage();
        if (is_wp_error($license)) {
            return $license;
        }
        if (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('upload_error', '请选择有效的文件。');
        }

        $rule = self::upload_rule_for_user();
        $validated = self::validate_gallery_upload_file((string) $file['tmp_name'], (string) $file['name'], (int) $file['size'], $rule);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $prepared = self::prepare_image_for_upload((string) $file['tmp_name'], (string) $file['name'], (int) $file['size'], $validated);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        try {
            return self::upload_local_file($prepared['tmp_name'], $prepared['file_name'], $prepared['file_size'], false, $prepared['validated']);
        } finally {
            self::cleanup_prepared_upload($prepared);
        }
    }

    /** 开关启用时，在进入远程上传前将可安全转换的静态位图保存为 WebP。 */
    private static function prepare_image_for_upload(string $tmp_name, string $file_name, int $file_size, array $validated) {
        $prepared = array(
            'tmp_name'  => $tmp_name,
            'file_name' => $validated['file_name'],
            'file_size' => $file_size,
            'validated' => $validated,
            'temporary' => false,
        );
        $settings = self::settings();
        $extension = strtolower((string) ($validated['extension'] ?? ''));
        if (empty($settings['auto_convert_images_to_webp']) || $extension === 'webp' || !self::should_convert_image_to_webp($tmp_name, $extension)) {
            return $prepared;
        }
        if (!function_exists('wp_get_image_editor') || !wp_image_editor_supports(array('mime_type' => 'image/webp'))) {
            return new WP_Error('webp_not_supported', '当前服务器不支持 WebP 转换，请关闭“自动将静态图片转为 WebP”或联系服务器管理员开启 WebP 支持。');
        }

        $editor = wp_get_image_editor($tmp_name);
        if (is_wp_error($editor)) {
            return new WP_Error('webp_source_unreadable', '图片无法转换为 WebP：' . $editor->get_error_message());
        }
        if (method_exists($editor, 'maybe_exif_rotate')) {
            $rotated = $editor->maybe_exif_rotate();
            if (is_wp_error($rotated)) {
                return new WP_Error('webp_rotation_failed', '图片方向校正失败：' . $rotated->get_error_message());
            }
        }
        $quality = max(40, min(95, absint(apply_filters('yxf_gallery_webp_quality', 82, $file_name))));
        $editor->set_quality($quality);
        $seed_path = wp_tempnam('namecrane-webp-' . wp_basename($file_name));
        if (!$seed_path) {
            return new WP_Error('webp_temp_failed', '无法创建 WebP 临时文件，请检查服务器临时目录。');
        }
        $webp_path = $seed_path . '.webp';
        wp_delete_file($seed_path);
        $saved = $editor->save($webp_path, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
            wp_delete_file($webp_path);
            $message = is_wp_error($saved) ? $saved->get_error_message() : '转换结果未生成。';
            return new WP_Error('webp_conversion_failed', '图片无法转换为 WebP：' . $message);
        }

        $base_name = pathinfo($validated['file_name'], PATHINFO_FILENAME);
        $webp_name = sanitize_file_name(($base_name !== '' ? $base_name : 'image') . '.webp');
        $webp_size = (int) filesize($saved['path']);
        $webp_validated = self::validate_gallery_upload_file($saved['path'], $webp_name, $webp_size);
        if (is_wp_error($webp_validated)) {
            wp_delete_file($saved['path']);
            return $webp_validated;
        }
        return array(
            'tmp_name'  => $saved['path'],
            'file_name' => $webp_name,
            'file_size' => $webp_size,
            'validated' => $webp_validated,
            'temporary' => true,
        );
    }

    /** SVG 保持矢量格式；动图 GIF 保持动画，静态 GIF 可正常转为 WebP。 */
    private static function should_convert_image_to_webp(string $tmp_name, string $extension) {
        if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'avif', 'bmp', 'heic', 'ico'), true)) {
            return false;
        }
        return $extension !== 'gif' || !self::is_animated_gif($tmp_name);
    }

    /** 流式检查 GIF 帧，避免为了识别动图将整个文件读入内存。 */
    private static function is_animated_gif(string $file_path) {
        $stream = @fopen($file_path, 'rb');
        if (!$stream) {
            return false;
        }
        $frames = 0;
        $tail = '';
        while (!feof($stream) && $frames < 2) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $data = $tail . $chunk;
            $frames += substr_count($data, "\x21\xF9\x04");
            $tail = substr($data, -2);
        }
        fclose($stream);
        return $frames > 1;
    }

    private static function cleanup_prepared_upload(array $prepared) {
        if (!empty($prepared['temporary']) && !empty($prepared['tmp_name']) && is_file($prepared['tmp_name'])) {
            wp_delete_file($prepared['tmp_name']);
        }
    }

    /**
     * 供可信的后台插件上传其生成的本地图片，并复用图库的权限、去重与公开链接流程。
     *
     * @return array{url:string,attachment_id:int,name:string,duplicate:bool,warning:string}|WP_Error
     */
    public static function upload_generated_image(string $tmp_name, string $file_name) {
        if (!self::can_use_gallery()) {
            return new WP_Error('gallery_permission_denied', '当前账号无权上传 NameCrane媒体库图片。');
        }
        $license = self::license_allows_storage();
        if (is_wp_error($license)) {
            return $license;
        }
        if ($tmp_name === '' || !is_file($tmp_name) || !is_readable($tmp_name)) {
            return new WP_Error('generated_image_missing', '待上传的图片文件不存在。');
        }

        // Steam 导入等后台自动流程需要在当前请求内直接得到链接，保持原有行为。
        $file_size = (int) filesize($tmp_name);
        $validated = self::validate_gallery_upload_file($tmp_name, $file_name, $file_size);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $prepared = self::prepare_image_for_upload($tmp_name, $file_name, $file_size, $validated);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        try {
            $result = self::upload_local_file($prepared['tmp_name'], $prepared['file_name'], $prepared['file_size'], true, $prepared['validated']);
        } finally {
            self::cleanup_prepared_upload($prepared);
        }
        if (is_wp_error($result)) {
            return $result;
        }

        $item = $result['item'] ?? null;
        $url  = $item && (string) ($item->status ?? '') === 'ready' ? self::item_public_url($item) : '';
        if ($url === '') {
            return new WP_Error('gallery_public_url_failed', '图片已上传，但未能获得 NameCrane媒体库公开链接。');
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
    private static function upload_local_file(string $tmp_name, string $file_name, int $file_size, bool $wait_for_public_url = false, $validated = null) {
        $validated = is_array($validated) ? $validated : self::validate_gallery_upload_file($tmp_name, $file_name, $file_size);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $file_name = $validated['file_name'];
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
            if ((string) $existing->status !== 'ready') {
                self::schedule_pending_link_resolution((int) $existing->id);
            }
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
            return new WP_Error('login_required', '请先登录 NameCrane 邮箱，或请管理员为你的用户角色配置默认邮箱账号。');
        }
        $remote_file = gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '-' . $file_name;
        $uploaded = self::storage_upload($settings, $password, $tmp_name, $remote_file);
        $upload_warning = '';
        $original_url = '';
        $is_ready = false;
        if (is_wp_error($uploaded)) {
            // 上传连接可能在文件已写入后才超时或断开。先到邮箱网盘确认，
            // 已存在则按成功处理，避免队列误报失败和重复上传。
            $original_url = self::create_public_link($settings, $password, $remote_file, $wait_for_public_url ? 10 : 1, $wait_for_public_url ? 1000000 : 0);
            if (is_wp_error($original_url)) {
                return $uploaded;
            }
            $upload_warning = '上传连接未返回完成状态，但已确认文件已保存到游先锋邮箱。';
            $is_ready = true;
        } elseif ($wait_for_public_url) {
            // 仅供需要在同一请求内拿到 URL 的后台自动流程使用。
            $original_url = self::create_public_link($settings, $password, $remote_file);
            $is_ready = !is_wp_error($original_url);
        }
        $wpdb->insert(self::table_name(), array(
            'original_url' => $is_ready ? $original_url : '',
            'output_url'   => $is_ready ? self::rewrite_url($original_url) : '',
            'file_name'    => $file_name,
            'mime_type'    => $validated['mime'],
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
        if (!$is_ready && $item) {
            self::schedule_pending_link_resolution((int) $item->id);
        }
        if ($is_ready && self::media_replacement_enabled() && $item) {
            $attachment_id = self::ensure_virtual_attachment($item);
            if ($attachment_id) {
                $item->attachment_id = $attachment_id;
            }
        }
        return array(
            'item'      => $item,
            'duplicate' => false,
            'warning'   => $upload_warning ?: ($is_ready ? '' : '图片已上传，公开链接正在生成。'),
        );
    }

    /** 统一返回给上传窗口的文件信息，避免待解析文件被当作上传失败。 */
    private static function item_ajax_payload($item) {
        $ready = $item && (string) ($item->status ?? '') === 'ready';
        return array(
            'id'        => (int) ($item->id ?? 0),
            'attachmentId' => (int) ($item->attachment_id ?? 0),
            'name'      => (string) ($item->file_name ?? ''),
            'url'       => $ready ? self::item_public_url($item) : '',
            'mime'      => (string) ($item->mime_type ?? ''),
            'kind'      => self::media_kind_from_mime((string) ($item->mime_type ?? '')),
            'fileSize'  => (int) ($item->file_size ?? 0),
            'fileSizeLabel' => self::file_size_label($item->file_size ?? 0),
            'createdAt' => (string) ($item->created_at ?? current_time('mysql')),
            'status'    => $ready ? 'ready' : 'pending',
            'pending'   => !$ready,
        );
    }

    /** 图片上传队列接口：每次仅处理一个队列项，便于准确显示状态和失败原因。 */
    public static function ajax_upload_image() {
        if (!self::can_use_gallery()) {
            wp_send_json_error(array('message' => '无权上传图库文件。'), 403);
        }
        check_ajax_referer('yxf_gallery_upload', 'nonce');
        $result = self::upload_gallery_file($_FILES['gallery_file'] ?? null);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        $item = $result['item'];
        wp_send_json_success(array_merge(self::item_ajax_payload($item), array(
            'duplicate' => !empty($result['duplicate']),
            'warning'   => (string) ($result['warning'] ?? ''),
        )));
    }

    /** 前端每次只请求一次链接状态，避免一个 AJAX 请求长时间阻塞。 */
    public static function ajax_resolve_pending_item() {
        if (!self::can_use_gallery()) {
            wp_send_json_error(array('message' => '无权查看图库文件。'), 403);
        }
        check_ajax_referer('yxf_gallery_upload', 'nonce');
        $item_id = absint($_POST['item_id'] ?? 0);
        if (!$item_id) {
            wp_send_json_error(array('message' => '未找到需要检查的图片。'), 400);
        }
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE id = %d AND author_id = %d',
            $item_id,
            get_current_user_id()
        ));
        if (!$item) {
            wp_send_json_error(array('message' => '该图片不存在或无权访问。'), 404);
        }
        $result = self::resolve_pending_item($item_id);
        if (!is_wp_error($result)) {
            $item = $result;
        }
        $payload = self::item_ajax_payload($item);
        $payload['warning'] = $payload['pending'] ? '公开链接正在生成，请稍候。' : '';
        if (is_wp_error($result) && $result->get_error_code() === 'login_required') {
            $payload['warning'] = $result->get_error_message();
        }
        wp_send_json_success($payload);
    }

    /** 文件列表改为在打开“全部文件”时分页加载，上传窗口无需等待整份媒体列表。 */
    public static function ajax_media_items() {
        if (!self::can_use_gallery()) {
            wp_send_json_error(array('message' => '无权查看图库文件。'), 403);
        }
        check_ajax_referer('yxf_gallery_media_items', 'nonce');
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = min(60, max(12, absint($_POST['per_page'] ?? 30)));
        $filters = array(
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'mime'   => sanitize_text_field(wp_unslash($_POST['mime'] ?? '')),
            'kind'   => sanitize_key(wp_unslash($_POST['kind'] ?? 'all')),
        );
        wp_send_json_success(self::media_frame_items_for_current_user($page, $per_page, $filters));
    }

    /**
     * 仅供“全部文件”按需分页加载；保留虚拟附件兼容性，但不再阻塞上传弹窗首屏。
     * 每次只处理当前页，避免文件较多时一次性创建虚拟附件造成窗口卡顿。
     */
    private static function media_frame_items_for_current_user($page = 1, $per_page = 30, $filters = array()) {
        global $wpdb;
        $page = max(1, (int) $page);
        $per_page = min(60, max(12, (int) $per_page));
        $where = array("status = 'ready'", 'author_id = %d');
        $params = array(get_current_user_id());
        $search = trim((string) ($filters['search'] ?? ''));
        $mime = trim((string) ($filters['mime'] ?? ''));
        $kind = sanitize_key((string) ($filters['kind'] ?? 'all'));

        if ($search !== '') {
            $where[] = 'file_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if ($mime !== '') {
            $where[] = 'mime_type = %s';
            $params[] = $mime;
        }
        if ($kind === 'image' || $kind === 'video' || $kind === 'audio') {
            $where[] = 'mime_type LIKE %s';
            $params[] = $kind . '/%';
        } elseif ($kind === 'file') {
            $where[] = "mime_type NOT LIKE 'image/%' AND mime_type NOT LIKE 'video/%' AND mime_type NOT LIKE 'audio/%'";
        }
        $where_sql = implode(' AND ', $where);
        $table = self::table_name();
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        $total_pages = max(1, (int) ceil($total / $per_page));
        $page = min($page, $total_pages);
        $query_params = array_merge($params, array($per_page, ($page - 1) * $per_page));
        $items_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $items = $wpdb->get_results($wpdb->prepare($items_sql, $query_params));
        $mime_sql = "SELECT DISTINCT mime_type FROM {$table} WHERE status = 'ready' AND author_id = %d AND mime_type <> '' ORDER BY mime_type ASC";
        $mime_types = $wpdb->get_col($wpdb->prepare($mime_sql, get_current_user_id()));
        $media_items = array();
        foreach ($items as $item) {
            $item_url = self::item_public_url($item);
            if (!$item_url) {
                continue;
            }
            $attachment_id = (int) ($item->attachment_id ?? 0);
            if (!$attachment_id && self::media_replacement_enabled()) {
                $attachment_id = self::ensure_virtual_attachment($item);
            }
            $media_items[] = array(
                'id'           => (int) $item->id,
                'attachmentId' => $attachment_id,
                'name'         => (string) ($item->file_name ?: '媒体文件'),
                'url'          => esc_url_raw($item_url),
                'mime'         => (string) $item->mime_type,
                'kind'         => self::media_kind_from_mime((string) $item->mime_type),
                'fileSize'     => (int) ($item->file_size ?? 0),
                'fileSizeLabel'=> self::file_size_label($item->file_size ?? 0),
                'authorId'     => (int) $item->author_id,
                'createdAt'    => (string) $item->created_at,
            );
        }
        return array(
            'items'      => $media_items,
            'page'       => $page,
            'perPage'    => $per_page,
            'total'      => $total,
            'totalPages' => $total_pages,
            'mimeTypes'  => array_values(array_filter(array_map('strval', $mime_types))),
        );
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

    private static function redirect($page, $notice, $extra = array()) {
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
        wp_safe_redirect(add_query_arg(array_merge(array('page' => $page, 'yxf_gallery_notice' => $notice), (array) $extra), admin_url('admin.php')));
        exit;
    }

    /** 仅改写域名部分，文件路径、查询参数与片段保持不变。 */
    public static function rewrite_url($url) {
        $settings = self::settings();
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
                'post_title'     => sanitize_text_field((string) ($item->file_name ?: (self::brand_name() . '图片'))),
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
            'settings_saved' => array('success', '设置已保存。新增和重新导入的链接将按当前规则输出。'),
            'settings_error' => array('error', '请填写有效的 NameCrane 邮箱服务器地址；如填写 CDN 加速域名，必须为完整的 https 地址。'),
            'uploaded'       => array('success', '图片已上传到 NameCrane 邮箱，并已生成公开链接。'),
            'upload_error'   => array('error', '请选择一个媒体文件后再上传。'),
            'not_allowed'    => array('error', '该文件类型不被 WordPress 允许上传。'),
            'too_large'      => array('error', '单个媒体文件不能超过 1GB。'),
            'storage_unconfigured' => array('error', '默认存储文件邮箱尚未配置，请联系管理员。'),
            'login_required' => array('error', '请先在“登录”中保存自己的 NameCrane 邮箱账号和密码。'),
            'login_saved' => array('success', '你的 NameCrane 邮箱登录信息已保存。'),
            'independent_login_denied' => array('error', '管理员尚未授权你使用独立邮箱登录。'),
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
            .yxf-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;max-width:1080px}.yxf-gallery-card{position:relative;min-width:0;width:auto!important;margin:0!important;padding:10px;overflow:visible}.yxf-gallery-thumb-wrap{position:relative;height:110px;overflow:hidden;background:#f0f0f1}.yxf-gallery-thumb,.yxf-gallery-file{display:block;width:100%;height:110px;box-sizing:border-box;object-fit:cover;background:#f0f0f1}.yxf-gallery-file{display:flex;align-items:center;justify-content:center;color:#2271b1;text-align:center;text-decoration:none}.yxf-gallery-thumb-badges{position:absolute;inset:0;pointer-events:none}.yxf-gallery-thumb-badge{position:absolute;bottom:7px;overflow:hidden;padding:3px 7px;border-radius:999px;background:rgba(0,0,0,.45);color:#fff;font-size:8px;line-height:1.35;white-space:nowrap;text-overflow:ellipsis}.yxf-gallery-thumb-badge:first-child{left:7px}.yxf-gallery-thumb-badge:last-child{right:7px;max-width:calc(100% - 14px)}.yxf-gallery-pending{color:#646970}.yxf-gallery-file-name{margin:10px 0 3px;word-break:break-all}.yxf-gallery-card-meta{margin:0;color:#787c82;font-size:12px;line-height:1.45}.yxf-gallery-card-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}.yxf-gallery-delete-trigger{display:flex;align-items:center;justify-content:center;width:20px;height:20px;padding:0;border:0;background:transparent;cursor:pointer}.yxf-gallery-delete-trigger svg{display:block;width:16px;height:16px;fill:#bfbfbf}.yxf-gallery-delete-trigger:hover svg,.yxf-gallery-delete-trigger:focus svg{fill:#b32d2e}.yxf-gallery-copy-button{position:relative;padding:0;border:0;background:transparent;color:#2271b1;text-decoration:none;cursor:pointer}.yxf-gallery-copy-button:hover,.yxf-gallery-copy-button:focus{color:#135e96;text-decoration:none}.yxf-gallery-copy-button:after{content:attr(data-link);position:absolute;z-index:20;right:0;bottom:calc(100% + 8px);display:none;width:240px;max-width:calc(100vw - 40px);padding:7px 9px;border-radius:3px;background:rgba(0,0,0,.78);color:#fff;font-size:12px;font-weight:400;line-height:1.45;text-align:left;white-space:normal;word-break:break-all;box-shadow:0 2px 8px rgba(0,0,0,.2);pointer-events:none}.yxf-gallery-copy-button:hover:after,.yxf-gallery-copy-button:focus{color:#135e96;text-decoration:none}.yxf-gallery-limit-note{max-width:1080px;margin:18px 0 0;padding:10px 12px;border-left:3px solid #72aee6;background:#f6f7f7;color:#50575e}.yxf-gallery-wrap #yxf-gallery-manage-form .yxf-gallery-limit-note{max-width:1280px}.yxf-gallery-delete-dialog{position:fixed;z-index:99999;inset:0}.yxf-gallery-delete-dialog-mask{position:absolute;inset:0;background:rgba(0,0,0,.32)}.yxf-gallery-delete-dialog-card{position:relative;z-index:1;width:min(360px,calc(100vw - 40px));margin:18vh auto 0;padding:20px;background:#fff;border-radius:4px;box-shadow:0 8px 24px rgba(0,0,0,.22)}.yxf-gallery-delete-dialog-card h2{margin:0 0 10px;font-size:18px}.yxf-gallery-delete-dialog-card p{margin:8px 0}.yxf-gallery-delete-dialog-card form{display:inline-block;margin:8px 8px 0 0}.yxf-gallery-delete-dialog-card .description{line-height:1.6}.yxf-gallery-delete-cancel{display:block;margin-top:12px}.yxf-gallery-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;max-width:1080px;margin:24px 0 12px;color:#7b8495}.yxf-gallery-pagination-info{margin-right:auto;font-size:12px}.yxf-gallery-pagination .tablenav-pages{margin:0}.yxf-gallery-pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;margin:0 2px;padding:0 7px;border:0;border-radius:8px;background:#f2f5fa;color:#667085;text-decoration:none;font-weight:600}.yxf-gallery-pagination .page-numbers:hover{background:#e8efff;color:#4e6ef2}.yxf-gallery-pagination .page-numbers.current{background:linear-gradient(135deg,#4e6ef2,#65a5ff);color:#fff;box-shadow:0 4px 10px rgba(78,110,242,.25)}@media(max-width:782px){.yxf-gallery-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}.yxf-gallery-pagination{flex-wrap:wrap;justify-content:center}.yxf-gallery-pagination-info{width:100%;margin:0;text-align:center}}
        </style>
        <style>.yxf-gallery-copy-button:hover:after,.yxf-gallery-copy-button:focus:after{display:block}</style>
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
        // “我的文件”按较小页数展示，避免文件数量增长后单页过长且始终看不到分页器。
        $per_page = 24;
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
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-upload')); ?>">上传文件</a> <?php if (self::user_has_login()) : ?><?php if ($using_shared_account) : ?><span style="color:#00a32a;font-weight:600">正在使用管理员配置的默认邮箱账号</span><?php else : ?><a class="button-link" style="color:#00a32a;font-weight:600;text-decoration:none" href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-login')); ?>">已登录：<?php echo esc_html($account['username']); ?></a><?php endif; ?><?php else : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-login')); ?>">登录 NameCrane 邮箱</a><?php endif; ?></p>
            <p class="description">这里仅显示你上传的媒体文件；使用默认邮箱账号时，文件实际保存到管理员指定的邮箱空间。</p>
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
                <?php if ($total_pages > 1) : ?><nav class="yxf-gallery-pagination" aria-label="我的文件分页"><span class="yxf-gallery-pagination-info">共 <?php echo esc_html($item_total); ?> 个文件，第 <?php echo esc_html($current_page); ?>/<?php echo esc_html($total_pages); ?> 页</span><div class="tablenav-pages"><?php echo paginate_links(array('base' => add_query_arg('paged', '%#%', admin_url('admin.php?page=yxf-gallery')), 'format' => '', 'current' => $current_page, 'total' => $total_pages, 'end_size' => 1, 'mid_size' => 1, 'prev_text' => '‹', 'next_text' => '›')); ?></div></nav><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php self::render_copy_script();
    }

    public static function render_upload_page() {
        if (!self::can_use_gallery()) {
            wp_die('无权上传图库图片。');
        }
        $upload_rule = self::upload_rule_for_user();
        $max_upload_files = (int) $upload_rule['max_files'];
        $upload_accept = self::upload_rule_accept_attribute($upload_rule);
        $upload_limits_by_extension = self::upload_rule_limits_by_extension($upload_rule);
        $upload_limits_summary = self::upload_rule_limits_summary($upload_rule);
        ?>
        <div class="wrap">
            <h1>上传文件</h1>
            <?php self::notices(); ?>
            <?php if (!self::user_has_login()) : ?><div class="notice notice-warning inline"><p>请先在“独立邮箱帐户登录”中填写自己的 NameCrane 邮箱账号，或请管理员为你的用户角色配置默认邮箱账号。<a href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-login')); ?>">去登录</a></p></div><?php endif; ?>
            <div class="card yxf-upload-card" style="max-width:900px;padding:22px;margin-top:18px">
                <p>单次最多上传 <?php echo esc_html($max_upload_files); ?> 个文件。文件上限：<?php echo esc_html($upload_limits_summary); ?>。允许格式：<?php echo esc_html(implode('、', array_map('strtoupper', $upload_rule['extensions']))); ?>。<a class="yxf-format-guide" href="https://tinypng.com/" target="_blank" rel="noopener">免费压缩/转换 <span aria-hidden="true">↗</span></a></p>
                <input id="yxf-gallery-files" type="file" accept="<?php echo esc_attr($upload_accept); ?>" multiple class="screen-reader-text" <?php disabled(!self::user_has_login()); ?>>
                <p class="yxf-upload-actions"><button type="button" class="button" id="yxf-gallery-choose" <?php disabled(!self::user_has_login()); ?>>选择文件</button> <button type="button" class="button button-primary" id="yxf-gallery-start" disabled>开始上传</button></p>
                <ul class="yxf-upload-queue" id="yxf-gallery-queue" aria-live="polite"></ul>
                <div class="yxf-upload-links" id="yxf-gallery-links" aria-live="polite"></div>
            </div>
        </div>
        <style>
            .yxf-upload-actions{display:flex;gap:8px;align-items:center}.yxf-format-guide{display:inline-flex;align-items:center;gap:4px;margin-left:7px;padding:1px 7px;border-radius:999px;background:#eaf3ff;color:#2271b1;font-size:12px;font-weight:600;line-height:1.55;text-decoration:none;vertical-align:1px}.yxf-format-guide:hover{color:#135e96;text-decoration:none}.yxf-upload-queue{margin:20px 0 0;border-top:1px solid #dcdcde}.yxf-upload-queue:empty{display:none}.yxf-upload-item{display:flex;gap:12px;align-items:center;padding:12px 2px;border-bottom:1px solid #f0f0f1}.yxf-upload-item-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-upload-item-status{font-size:12px;color:#646970}.yxf-upload-item.is-uploading .yxf-upload-item-status,.yxf-upload-item.is-pending .yxf-upload-item-status{color:#2271b1}.yxf-upload-item.is-success .yxf-upload-item-status{color:#00a32a}.yxf-upload-item.is-error .yxf-upload-item-status{color:#d63638}.yxf-upload-item-remove{color:#b32d2e;border:0;background:none;cursor:pointer}.yxf-upload-links{margin-top:20px}.yxf-upload-links:empty{display:none}.yxf-upload-links-title{margin:0 0 8px;font-weight:600}.yxf-upload-link-row{display:flex;align-items:center;gap:8px;padding:10px 0;border-top:1px solid #f0f0f1}.yxf-upload-link-name{flex:0 0 150px;max-width:28%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-upload-link-url{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#2271b1;text-decoration:none}.yxf-upload-copy{flex:0 0 auto;padding:0;border:0;background:transparent;color:#2271b1;cursor:pointer}.yxf-upload-copy:hover{color:#135e96}.yxf-upload-copy:focus{outline:2px solid #72aee6;outline-offset:1px}@media(max-width:782px){.yxf-upload-link-row{flex-wrap:wrap}.yxf-upload-link-name{flex-basis:100%;max-width:100%}.yxf-upload-link-url{flex-basis:calc(100% - 64px)}}
        </style>
        <script>
        (function(){
            var input=document.getElementById('yxf-gallery-files'), choose=document.getElementById('yxf-gallery-choose'), start=document.getElementById('yxf-gallery-start'), list=document.getElementById('yxf-gallery-queue'), links=document.getElementById('yxf-gallery-links');
            if(!input||!choose||!start||!list||!links){return;}
            var queue=new Map(), uploading=false, maxUploadFiles=<?php echo (int) $max_upload_files; ?>, allowedExtensions=<?php echo wp_json_encode(array_values($upload_rule['extensions'])); ?>, uploadLimits=<?php echo wp_json_encode($upload_limits_by_extension); ?>, ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce=<?php echo wp_json_encode(wp_create_nonce('yxf_gallery_upload')); ?>;
            function key(file){return [file.name,file.size,file.lastModified].join(':');}
            function copy(url,button){var done=function(){button.textContent='已复制';window.setTimeout(function(){button.textContent='复制链接';},1500);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){window.prompt('请复制文件链接：',url);});}else{window.prompt('请复制文件链接：',url);}}
            function render(){list.innerHTML='';links.innerHTML='';var completed=[];queue.forEach(function(item,id){var row=document.createElement('li');row.className='yxf-upload-item is-'+item.state;row.innerHTML='<span class="yxf-upload-item-name"></span><span class="yxf-upload-item-status"></span>';row.querySelector('.yxf-upload-item-name').textContent=item.file.name;row.querySelector('.yxf-upload-item-status').textContent=item.message;if(item.state==='waiting'||item.state==='error'){var remove=document.createElement('button');remove.type='button';remove.className='yxf-upload-item-remove';remove.textContent='移除';remove.addEventListener('click',function(){queue.delete(id);render();});row.appendChild(remove);}if(item.state==='success'&&item.url){completed.push(item);}list.appendChild(row);});if(completed.length){var title=document.createElement('p');title.className='yxf-upload-links-title';title.textContent='文件外部链接';links.appendChild(title);completed.forEach(function(item){var row=document.createElement('div');row.className='yxf-upload-link-row';var name=document.createElement('strong');name.className='yxf-upload-link-name';name.title=item.file.name;name.textContent=item.file.name;var url=document.createElement('a');url.className='yxf-upload-link-url';url.href=item.url;url.target='_blank';url.rel='noopener';url.title=item.url;url.textContent=item.url;var copyButton=document.createElement('button');copyButton.type='button';copyButton.className='yxf-upload-copy';copyButton.textContent='复制链接';copyButton.addEventListener('click',function(){copy(item.url,copyButton);});row.append(name,url,copyButton);links.appendChild(row);});}start.disabled=uploading||![...queue.values()].some(function(item){return item.state==='waiting'||item.state==='error';});}
            function add(files){var rejected=[],remaining=Math.max(0,maxUploadFiles-queue.size);Array.prototype.forEach.call(files,function(file){var extension=(file.name.split('.').pop()||'').toLowerCase(),id=key(file),limit=uploadLimits[extension];if(queue.has(id))return;if(!allowedExtensions.includes(extension)||!limit){rejected.push(file.name+'（格式不允许）');return;}if(file.size>limit.bytes){rejected.push(file.name+'（超过 '+limit.label+'）');return;}if(!remaining){rejected.push(file.name+'（单次最多 '+maxUploadFiles+' 个）');return;}queue.set(id,{file:file,state:'waiting',message:'等待上传'});remaining--;});if(rejected.length){window.alert('以下文件未加入上传队列：'+rejected.join('、'));}input.value='';render();}
            function waitForPublicLink(item,itemId){var attempt=0;function check(){attempt++;var data=new FormData();data.append('action','yxf_gallery_resolve_pending_item');data.append('nonce',nonce);data.append('item_id',itemId);fetch(ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}).then(function(response){return response.text();}).then(function(raw){var payload;try{payload=JSON.parse(raw);}catch(error){throw new Error('服务器未返回有效的链接状态。');}if(!payload.success){throw new Error((payload.data&&payload.data.message)||'公开链接检查失败。');}var result=payload.data||{};if(result.url){item.url=result.url;item.state='success';item.message='上传完成';render();return;}item.message=result.warning||'已上传，公开链接正在生成';if(attempt<30){window.setTimeout(check,1500);}else{item.state='pending';item.message='已上传，公开链接仍在生成，可稍后在我的媒体库查看';}render();}).catch(function(){if(attempt<30){window.setTimeout(check,1500);}else{item.state='pending';item.message='已上传，公开链接仍在生成，可稍后在我的媒体库查看';render();}});}window.setTimeout(check,1200);}
            async function send(item){item.state='uploading';item.message='正在上传…';render();var data=new FormData();data.append('action','yxf_gallery_upload_image');data.append('nonce',nonce);data.append('gallery_file',item.file,item.file.name);try{var response=await fetch(ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}),raw=await response.text(),payload;try{payload=JSON.parse(raw);}catch(parseError){throw new Error('服务器未返回有效的上传结果，请重新登录游先锋邮箱后再试。');}if(!payload.success){throw new Error((payload.data&&payload.data.message)||'上传失败，请重试。');}var result=payload.data||{};item.url=result.url||'';if(item.url){item.state='success';item.message=result.duplicate?'已存在，无需重复上传':'上传完成';}else{item.state='pending';item.message=result.warning||'已上传，公开链接正在生成';waitForPublicLink(item,result.id);} }catch(error){item.state='error';item.message=error.message||'上传失败，请重试。';}render();}
            async function run(){if(uploading){return;}uploading=true;render();for(const item of queue.values()){if(item.state==='waiting'||item.state==='error'){await send(item);}}uploading=false;render();}
            choose.addEventListener('click',function(){input.click();});input.addEventListener('change',function(){add(input.files);});start.addEventListener('click',run);render();
        }());
        </script>
        <?php
    }

    public static function render_login_page() {
        if (!self::can_use_gallery() || !self::can_use_independent_login()) {
            wp_die('无权访问登录。');
        }
        $account = self::account();
        ?>
        <div class="wrap">
            <h1>独立邮箱帐户登录</h1>
            <?php self::render_namecrane_portal_buttons(); ?>
            <?php self::notices(); ?>
            <div class="notice notice-info inline"><p>登录成功后，上传媒体会保存到当前登录的邮箱帐户空间内。如需其他身份或用户也可登录使用自己的邮箱，请在“个性化设置”中勾选权限；请注意，该用户还需要拥有访问 WordPress 后台的权限。</p></div>
            <form method="post" class="card" style="max-width:720px;padding:18px 22px;margin-top:18px">
                <?php wp_nonce_field('yxf_gallery_login'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="gallery_username">NameCrane 邮箱用户名</label></th><td><input id="gallery_username" name="gallery_username" class="regular-text" value="<?php echo esc_attr($account['username']); ?>" required></td></tr>
                    <tr><th scope="row"><label for="gallery_password">NameCrane 邮箱密码</label></th><td><input id="gallery_password" name="gallery_password" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $account['secret'] ? esc_attr('已保存；留空则不修改') : esc_attr('填写密码'); ?>"></td></tr>
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
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'basic'));
        $tab = in_array($tab, array('basic', 'personalization', 'license'), true) ? $tab : 'basic';
        $all_users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
        ?>
        <div class="wrap">
            <h1>NameCrane邮箱媒体库设置</h1>
            <?php self::notices(); ?>
            <?php self::render_namecrane_portal_buttons(); ?>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $tab === 'basic' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'yxf-gallery-settings', 'tab' => 'basic'), admin_url('admin.php'))); ?>">基础配置</a>
                <a class="nav-tab <?php echo $tab === 'personalization' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'yxf-gallery-settings', 'tab' => 'personalization'), admin_url('admin.php'))); ?>">个性化设置</a>
                <a class="nav-tab <?php echo $tab === 'license' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'yxf-gallery-settings', 'tab' => 'license'), admin_url('admin.php'))); ?>">授权管理</a>
            </h2>
            <?php if ($tab === 'basic') : ?>
            <form method="post">
                <?php wp_nonce_field('yxf_gallery_settings'); ?>
                <input type="hidden" name="yxf_gallery_action" value="save_basic_settings">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">替换媒体库</th>
                        <td><label><input type="checkbox" name="replace_media_library" value="1" <?php checked(!empty($settings['replace_media_library'])); ?>> 启用 <?php echo esc_html(self::brand_name()); ?> 替换媒体库</label><p class="description">建议勾选，启用后，WordPress的媒体会将图片、视频、音频和普通附件的选择和上传入口改为当前NameCrane邮箱媒体库；该操作不会破坏原媒体库，关闭该项原媒体库恢复正常使用。上传文件默认保存在管理员设置的默认存储文件邮箱中；已独立登录的用户会保存到自己的邮箱空间。</p></td>
                    </tr>
                    <tr>
                        <th scope="row">隐藏 WordPress 媒体库菜单</th>
                        <td><label><input type="checkbox" name="hide_wordpress_media_menu" value="1" <?php checked(!empty($settings['hide_wordpress_media_menu'])); ?>> 隐藏后台左侧的 WordPress 原生“媒体”菜单</label><p class="description">默认勾选。仅隐藏菜单入口，不删除原媒体文件，也不影响主题或插件调用媒体接口。</p></td>
                    </tr>
                    <tr>
                        <th scope="row">自动将图片格式转为 WebP</th>
                        <td><label><input type="checkbox" name="auto_convert_images_to_webp" value="1" <?php checked(!empty($settings['auto_convert_images_to_webp'])); ?>> 上传时自动将静态图片保存为 WebP 格式</label><p class="description">勾选后，通过 <?php echo esc_html(self::brand_name()); ?> 上传的 JPG、PNG 等静态位图会先在服务器本地快速转换，再上传较小的 WebP 文件并生成链接。已是 WebP、SVG 矢量图和动态 GIF 保持原格式。</p></td>
                    </tr>
                </table>
                <hr>
                <?php
                $connection_status = sanitize_key((string) ($settings['connection_status'] ?? 'not_connected'));
                $connection_connected = $connection_status === 'connected';
                $connection_label = $connection_connected ? '已连接' : ($connection_status === 'failed' ? '连接失败' : '未连接');
                $connection_reason = $connection_status === 'failed' ? trim((string) ($settings['connection_status_message'] ?? '')) : '';
                ?>
                <h2>存储连接 <span style="margin-left:8px;font-size:14px;font-weight:500;color:<?php echo $connection_connected ? '#00a32a' : '#b32d2e'; ?>">● <?php echo esc_html($connection_label); ?><?php echo $connection_reason !== '' ? '：' . esc_html($connection_reason) : ''; ?></span></h2>
                <p>这里配置全站共用的 NameCrane 邮箱服务器地址和媒体目录。默认存储文件邮箱会使用此连接。</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="storage_protocol">协议</label></th>
                        <td><select id="storage_protocol" name="storage_protocol"><option value="ftps" <?php selected($settings['storage_protocol'], 'ftps'); ?>>FTPS（建议使用该协议）</option><option value="sftp" <?php selected($settings['storage_protocol'], 'sftp'); ?>>SFTP</option></select></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sftp_host">服务器地址</label></th>
                        <td><input id="sftp_host" name="sftp_host" class="regular-text code" value="<?php echo esc_attr($settings['sftp_host']); ?>" placeholder="eu1.workspace.org"><p class="description">此处为NameCrane邮箱地址，如在邮箱设置中自定义了SSL主机名，请填入自定义SSL地址。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="target_base">CDN加速域名（选填）</label></th>
                        <td><input id="target_base" name="target_base" type="url" class="regular-text code" value="<?php echo esc_attr($settings['target_base']); ?>"><p class="description">必须填写完整https地址，例如https://cdn.example.com。开启后，文件公开链接会统一使用CDN加速域名。如不填写则直接使用服务器地址域名输出。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sftp_port">端口</label></th>
                        <td><input id="sftp_port" name="sftp_port" type="number" min="1" max="65535" value="<?php echo esc_attr($settings['sftp_port']); ?>"><p class="description">NameCrane默认端口，一般情况无需修改</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sftp_remote_path">目标目录</label></th>
                        <td><input id="sftp_remote_path" name="sftp_remote_path" class="regular-text code" value="<?php echo esc_attr($settings['sftp_remote_path']); ?>" placeholder="/"><p class="description">建议默认即可，同一路径会分别建立在每位用户自己的网盘中，如需使用请先在邮箱网盘中建好该目录。</p></td>
                    </tr>
                </table>
                <table class="form-table" role="presentation">
                    <tr id="yxf-default-mailbox-settings">
                        <th scope="row">默认存储文件邮箱</th>
                        <td>
                            <p><label for="default_mailbox_username">邮箱账号</label><br><input id="default_mailbox_username" name="default_mailbox_username" type="email" class="regular-text" value="<?php echo esc_attr((string) ($settings['default_mailbox_username'] ?? '')); ?>" placeholder="name@example.com" required></p>
                            <p><label for="default_mailbox_password">邮箱密码</label><br><input id="default_mailbox_password" name="default_mailbox_password" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($settings['default_mailbox_secret']) ? esc_attr('已保存；留空则不修改') : esc_attr('填写邮箱密码'); ?>" <?php echo empty($settings['default_mailbox_secret']) ? 'required' : ''; ?>></p>
                            <p class="description">所有用户上传的文件会默认保存到这个邮箱帐户存储空间。<br>文件记录仍归实际上传者所有，每个用户只能查看和管理自己的文件。<br>如需单独给角色或指定账号使用自己的邮箱存储空间，请在个性化中设置。</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary" type="submit">保存设置</button> <button class="button" type="submit" name="yxf_gallery_test_connection" value="1">测试连接性</button></p>
            </form>
            <div class="notice notice-info inline"><p>转换只修改域名头，原始文件路径、查询参数和链接片段完全保留。已加入图库的历史记录不会被批量改写。</p></div>
            <h2>当前接入状态</h2>
            <p>已具备：用户独立登录、上传到个人网盘、自动生成公开链接、可选 CDN 加速域名、经典编辑器插入媒体<?php echo !empty($settings['replace_media_library']) ? '、替换媒体库已启用' : ''; ?>。</p>
            <p>获独立登录权限的用户可在“登录”菜单中测试自己的邮箱连接。</p>
            <script>(function(){var protocol=document.getElementById('storage_protocol'),port=document.getElementById('sftp_port');if(protocol&&port){protocol.addEventListener('change',function(){port.value=protocol.value==='sftp'?'8222':'8221';});}if(window.location.hash==='#yxf-default-mailbox-settings'){var mailbox=document.getElementById('default_mailbox_username');if(mailbox){window.setTimeout(function(){mailbox.scrollIntoView({block:'center'});mailbox.focus();},0);}}}());</script>
            <?php elseif ($tab === 'personalization') : ?>
            <?php $gallery_name_authorized = self::has_paid_authorization(); $independent_login_authorized = $gallery_name_authorized; $upload_rule_overrides_authorized = $gallery_name_authorized; ?>
            <form method="post">
                <?php wp_nonce_field('yxf_gallery_personalization'); ?>
                <input type="hidden" name="yxf_gallery_action" value="save_personalization_settings">
                <input type="hidden" name="personalization_section" value="all">
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="gallery_name">自定义媒体库名称</label></th><td><input id="gallery_name" name="gallery_name" class="regular-text" value="<?php echo esc_attr(self::brand_name()); ?>" <?php disabled(!$gallery_name_authorized); ?>><p class="description">默认“NameCrane媒体库”。保存后，菜单和媒体库界面会使用这个名称。</p><?php if (!$gallery_name_authorized) : ?><p class="description" style="color:#b32d2e">🔒 该功能需授权后才可使用。</p><?php endif; ?></td></tr>
                </table>
                <?php
                $role_labels = array('subscriber' => '订阅者', 'contributor' => '贡献者', 'author' => '作者', 'editor' => '编辑', 'administrator' => '管理员');
                $upload_default_rule = self::normalize_upload_rule($settings['upload_default_rule'] ?? array());
                $upload_role_rules = is_array($settings['upload_role_rules'] ?? null) ? $settings['upload_role_rules'] : array();
                $upload_user_rules = is_array($settings['upload_user_rules'] ?? null) ? $settings['upload_user_rules'] : array();
                $upload_rule_users = array();
                foreach ($all_users as $user) {
                    $upload_rule_users[] = array('id' => absint($user->ID), 'label' => (string) ($user->display_name ?: $user->user_login), 'login' => (string) $user->user_login);
                }
                ob_start();
                self::render_upload_rule_fields('upload_user_rules[__USER_ID__]', $upload_default_rule, 'yxf-upload-user-__USER_ID__');
                $upload_user_rule_template = ob_get_clean();
                $php_upload_limit_bytes = wp_max_upload_size();
                $php_upload_limit_label = $php_upload_limit_bytes ? size_format($php_upload_limit_bytes, 2) : trim((string) ini_get('upload_max_filesize'));
                ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">上传限制</th><td>
                <div class="yxf-upload-rule-intro"><strong>设置顺序很简单：</strong>先设定所有人的默认规则；只有确实需要区别对待时，再打开某个角色或指定用户的“单独设置”。指定用户优先于角色规则；用户有多个角色时，按管理员、编辑、作者、贡献者、订阅者的顺序生效。</div>
                <?php if ($php_upload_limit_label !== '') : ?><p class="yxf-upload-rule-php-limit">当前PHP环境配置限制的最大上传大小为：<?php echo esc_html($php_upload_limit_label); ?>，请自行通过 <a href="https://namecrane.com/clientarea.php" target="_blank" rel="noopener noreferrer"><strong>NameCrane Mail控制台</strong></a> 确认默认存储文件邮箱容量。</p><?php endif; ?>
                <style>
                    .yxf-upload-rule-intro{max-width:850px;margin:10px 0 12px;padding:10px 12px;border-left:3px solid #2271b1;border-radius:0 6px 6px 0;background:#f0f6fc;color:#3c434a;font-size:12px;line-height:1.65}.yxf-upload-rule-php-limit{max-width:850px;margin:-2px 0 12px;color:#d63638;font-size:12px;font-weight:600}.yxf-upload-rule-card{max-width:850px;margin:0 0 10px;overflow:hidden;border:1px solid #dcdcde;border-radius:8px;background:#fff}.yxf-upload-rule-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;background:#f8fafc}.yxf-upload-rule-card-head h3{margin:0;font-size:13px}.yxf-upload-rule-card-head p{margin:2px 0 0;color:#787c82;font-size:11px}.yxf-upload-rule-card-body{padding:0 12px 12px}.yxf-upload-rule-card.is-disabled .yxf-upload-rule-card-body{display:none}.yxf-upload-rule-switch{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;font-size:12px;font-weight:600;color:#50575e}.yxf-upload-rule-switch input{position:absolute;opacity:0}.yxf-upload-rule-switch i{position:relative;display:block;width:32px;height:17px;border-radius:999px;background:#b8bcc2;transition:.18s}.yxf-upload-rule-switch i:after{content:'';position:absolute;top:3px;left:3px;width:11px;height:11px;border-radius:50%;background:#fff;transition:.18s}.yxf-upload-rule-switch input:checked+i{background:#2271b1}.yxf-upload-rule-switch input:checked+i:after{transform:translateX(15px)}.yxf-upload-rule-fields{padding-top:10px}.yxf-upload-rule-basics{display:flex;flex-wrap:wrap;gap:8px}.yxf-upload-rule-basics>label{display:flex;flex:1 1 205px;align-items:center;justify-content:space-between;gap:8px;min-height:36px;padding:0 9px;border:1px solid #dcdcde;border-radius:6px;background:#fff;color:#50575e;font-size:12px;font-weight:600}.yxf-upload-rule-input{display:inline-flex;align-items:center;gap:0}.yxf-upload-rule-input input{width:54px;height:28px;margin:0;border-color:#c3c4c7;border-radius:4px 0 0 4px;text-align:center}.yxf-upload-rule-input em{display:inline-flex;align-items:center;height:28px;padding:0 7px;border:1px solid #c3c4c7;border-left:0;border-radius:0 4px 4px 0;background:#f0f0f1;font-style:normal;font-weight:500}.yxf-upload-rule-input select{height:28px;margin-left:5px;border-color:#c3c4c7;border-radius:4px;background:#fff}.yxf-upload-rule-formats-open{display:flex;align-items:center;justify-content:space-between;width:100%;margin-top:9px;padding:8px 9px;border:1px dashed #b7c7de;border-radius:6px;background:#f8fbff;color:#2271b1;font-size:12px;font-weight:600;cursor:pointer}.yxf-upload-rule-formats-open b{margin-left:7px;color:#787c82;font-size:11px;font-weight:400}.yxf-upload-rule-formats-open em{padding:3px 7px;border-radius:4px;background:#eaf3ff;font-style:normal;font-size:11px}.yxf-upload-rule-format-modal{position:fixed;z-index:100001;inset:0;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box}.yxf-upload-rule-format-modal[hidden]{display:none!important}.yxf-upload-rule-format-mask{position:absolute;inset:0;background:rgba(0,0,0,.45)}.yxf-upload-rule-format-dialog{position:relative;z-index:1;width:min(580px,100%);max-height:min(680px,calc(100vh - 40px));overflow:auto;padding:20px;border-radius:10px;background:#fff;box-shadow:0 16px 48px rgba(0,0,0,.25)}.yxf-upload-rule-format-dialog h3{margin:0 32px 12px 0;font-size:16px}.yxf-upload-rule-format-close{position:absolute;top:10px;right:10px;display:grid;place-items:center;width:28px;height:28px;padding:0;border:0;border-radius:50%;background:#f0f0f1;color:#50575e;font-size:21px;line-height:1;cursor:pointer}.yxf-upload-rule-formats-head p{margin:0 0 7px;color:#787c82;font-size:11px;line-height:1.5}.yxf-upload-rule-format-groups{border-top:1px solid #f0f0f1}.yxf-upload-rule-format-group{margin:0;border-bottom:1px solid #f0f0f1}.yxf-upload-rule-format-title{display:flex;align-items:center;justify-content:space-between;min-height:34px;color:#1d2327;font-size:12px;font-weight:600;cursor:pointer}.yxf-upload-rule-format-title small{color:#8c8f94;font-size:10px;font-weight:500}.yxf-upload-rule-format-group summary{list-style:none}.yxf-upload-rule-format-group summary::-webkit-details-marker{display:none}.yxf-upload-rule-format-group summary:after{content:'⌄';margin-left:8px;color:#8c8f94;font-size:13px}.yxf-upload-rule-format-group[open] summary:after{transform:rotate(180deg)}.yxf-upload-rule-tags{display:flex;flex-wrap:wrap;gap:6px;padding:0 0 9px}.yxf-upload-rule-format-input{position:absolute;opacity:0;pointer-events:none}.yxf-upload-rule-format{display:inline-flex;align-items:center;min-height:25px;padding:0 8px;border:1px solid #d0d3d7;border-radius:999px;background:#fff;color:#646970;font-size:11px;font-weight:600;cursor:pointer;transition:.16s}.yxf-upload-rule-format:hover{border-color:#72aee6;color:#135e96}.yxf-upload-rule-format-input:checked+.yxf-upload-rule-format{border-color:#2271b1;background:#eaf3ff;color:#135e96;box-shadow:inset 0 0 0 1px #2271b1}.yxf-upload-rule-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.yxf-upload-rule-options{max-width:850px;margin:0 0 12px}.yxf-upload-rule-options>summary{padding:9px 12px;border:1px solid #dcdcde;border-radius:7px;background:#fff;color:#2271b1;font-size:12px;font-weight:600;cursor:pointer;list-style:none}.yxf-upload-rule-options>summary::-webkit-details-marker{display:none}.yxf-upload-rule-options[open]>summary{margin-bottom:8px;border-color:#2271b1}.yxf-upload-rule-user-card{position:relative;max-width:850px;margin:8px 0;padding:0;border:1px solid #dcdcde;border-radius:8px;background:#fff;overflow:hidden}.yxf-upload-rule-user-card h4{margin:0;padding:10px 12px;background:#f8fafc;font-size:13px}.yxf-upload-rule-user-card .yxf-upload-rule-fields{padding:10px 12px 12px}.yxf-upload-rule-remove-user{position:absolute;top:9px;right:12px}.yxf-upload-rule-user-results{position:absolute;z-index:3;top:100%;left:0;right:0;max-height:220px;overflow:auto;background:#fff;border:1px solid #ccd0d4;border-top:0;border-radius:0 0 8px 8px;box-shadow:0 8px 18px rgba(0,0,0,.12)}@media(max-width:782px){.yxf-upload-rule-grid{grid-template-columns:1fr}.yxf-upload-rule-card-head{align-items:flex-start;flex-direction:column;gap:7px}.yxf-upload-rule-switch{align-self:flex-end}.yxf-upload-rule-basics{display:block}.yxf-upload-rule-basics>label{margin-bottom:7px}.yxf-upload-rule-user-card{border-radius:7px}.yxf-upload-rule-format-dialog{padding:16px}.yxf-upload-rule-formats-open{align-items:flex-start;text-align:left}}
                    /* 上传限制沿用 WordPress 设置页的朴素表单观感，避免与其他设置项割裂。 */
                    .yxf-upload-rule-intro{margin:8px 0;padding:0;border:0;border-radius:0;background:transparent;color:#50575e}.yxf-upload-rule-php-limit{margin:6px 0 12px}.yxf-upload-rule-card{max-width:850px;margin:0 0 12px;overflow:visible;border:0;border-radius:0;background:transparent}.yxf-upload-rule-card-head{padding:0 0 7px;border-bottom:1px solid #dcdcde;background:transparent}.yxf-upload-rule-card-head h3{font-size:14px}.yxf-upload-rule-card-head p{font-size:12px}.yxf-upload-rule-card-body{padding:8px 0 0}.yxf-upload-rule-basics{gap:14px}.yxf-upload-rule-basics>label{flex:0 1 auto;min-height:40px;padding:0;border:0;border-radius:0;background:transparent;gap:9px}.yxf-upload-rule-input input{width:76px;height:40px;box-sizing:border-box}.yxf-upload-rule-input em{display:inline-flex;width:60px;height:40px;justify-content:center;box-sizing:border-box;background:#fff}.yxf-upload-rule-input select{width:60px;height:40px!important;min-height:40px!important;margin-left:0;border-left:0;border-radius:0 4px 4px 0;box-sizing:border-box}.yxf-upload-rule-formats-open{display:inline-flex;width:auto;min-height:30px;margin-top:10px;padding:0;border:0;border-radius:0;background:transparent;text-align:left}.yxf-upload-rule-formats-open b{font-size:12px}.yxf-upload-rule-formats-open em{display:inline-flex;align-items:center;min-height:30px;margin-left:8px;padding:0 11px;border-radius:4px;background:#2271b1;color:#fff;font-size:12px;font-style:normal;font-weight:600;text-decoration:none;box-shadow:0 1px 2px rgba(0,0,0,.12);transition:background .16s ease}.yxf-upload-rule-formats-open:hover em{background:#135e96}.yxf-upload-rule-options{margin:0 0 14px;padding-top:9px;border-top:1px solid #dcdcde}.yxf-upload-rule-options>summary{padding:0;border:0;border-radius:0;background:transparent;color:#2271b1;font-size:13px}.yxf-upload-rule-options[open]>summary{margin-bottom:10px;border:0}.yxf-upload-rule-grid{gap:0;border-top:1px solid #f0f0f1}.yxf-upload-rule-grid .yxf-upload-rule-card{margin:0;padding:10px 12px;border-bottom:1px solid #f0f0f1}.yxf-upload-rule-grid .yxf-upload-rule-card-head{padding:0;border:0}.yxf-upload-rule-grid .yxf-upload-rule-card-body{padding-top:7px}.yxf-upload-rule-user-card{max-width:850px;margin:8px 0;padding:10px 0 0;border:0;border-top:1px solid #f0f0f1;border-radius:0;background:transparent}.yxf-upload-rule-user-card h4{padding:0;background:transparent}.yxf-upload-rule-user-card .yxf-upload-rule-fields{padding:8px 0 0}.yxf-upload-rule-remove-user{top:8px;right:0}.yxf-upload-rule-user-results{border-radius:0;box-shadow:0 4px 10px rgba(0,0,0,.08)}@media(max-width:782px){.yxf-upload-rule-basics{display:block}.yxf-upload-rule-basics>label{display:flex;margin:0 0 8px}.yxf-upload-rule-formats-open{display:flex;width:100%;justify-content:space-between;align-items:center}}
                </style>
                <style>
                    .yxf-upload-rule-options>summary,.yxf-upload-rule-locked-title{display:flex;align-items:center;justify-content:space-between;gap:8px}
                    .yxf-upload-rule-options.is-locked{padding-bottom:10px;cursor:not-allowed}
                    .yxf-upload-rule-locked-title{color:#8c8f94;font-size:13px;font-weight:600}
                    .yxf-upload-rule-locked-title strong{color:#b32d2e;font-size:12px;font-weight:500}
                    .yxf-upload-rule-formats-open{display:block;width:100%;min-height:40px;padding:4px 0;text-align:left;white-space:normal}
                    .yxf-upload-rule-formats-open>span{display:inline}
                    .yxf-upload-rule-formats-open em{margin-left:8px;vertical-align:middle}
                    .yxf-upload-rule-format-actions{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 12px!important}.yxf-upload-rule-format-actions .button{min-height:30px;padding:0 10px;font-size:12px}
                    .yxf-upload-rule-expand-icon{font-style:normal;font-size:16px;line-height:1;color:#787c82;transition:transform .18s ease}
                    .yxf-upload-rule-options[open] .yxf-upload-rule-expand-icon{transform:rotate(180deg)}
                </style>
                <section class="yxf-upload-rule-card">
                    <div class="yxf-upload-rule-card-head"><div><h3>默认规则</h3><p>所有没有单独规则的用户都会使用这里的限制。</p></div></div>
                    <div class="yxf-upload-rule-card-body"><?php self::render_upload_rule_fields('upload_default_rule', $upload_default_rule, 'yxf-upload-default'); ?></div>
                </section>
                <?php if ($upload_rule_overrides_authorized) : ?>
                <details class="yxf-upload-rule-options">
                    <summary><span>设置各角色组专属限制</span><i class="yxf-upload-rule-expand-icon" aria-hidden="true">⌄</i></summary>
                    <div class="yxf-upload-rule-grid">
                    <?php foreach ($role_labels as $role => $label) : $role_enabled = isset($upload_role_rules[$role]); ?>
                        <section class="yxf-upload-rule-card <?php echo $role_enabled ? '' : 'is-disabled'; ?>" data-yxf-role-card>
                            <div class="yxf-upload-rule-card-head"><div><h3><?php echo esc_html($label); ?></h3><p data-yxf-role-status><?php echo $role_enabled ? '正在使用单独规则' : '沿用默认规则'; ?></p></div><label class="yxf-upload-rule-switch"><span>单独设置</span><input class="yxf-upload-rule-enable" type="checkbox" name="upload_role_rules[<?php echo esc_attr($role); ?>][enabled]" value="1" <?php checked($role_enabled); ?>><i aria-hidden="true"></i></label></div>
                            <div class="yxf-upload-rule-card-body"><?php self::render_upload_rule_fields('upload_role_rules[' . $role . ']', self::normalize_upload_rule($upload_role_rules[$role] ?? $upload_default_rule), 'yxf-upload-role-' . $role); ?></div>
                        </section>
                    <?php endforeach; ?>
                    </div>
                </details>
                <?php else : ?>
                <div class="yxf-upload-rule-options is-locked"><div class="yxf-upload-rule-locked-title"><span>设置各角色组专属限制</span><strong>🔒 该功能需授权后才可使用</strong></div></div>
                <?php endif; ?>
                <?php if ($upload_rule_overrides_authorized) : ?>
                <details class="yxf-upload-rule-options">
                    <summary><span>指定用户专属限制</span><i class="yxf-upload-rule-expand-icon" aria-hidden="true">⌄</i></summary>
                    <div id="yxf-upload-rule-users" data-users="<?php echo esc_attr(wp_json_encode($upload_rule_users)); ?>" data-template="<?php echo esc_attr($upload_user_rule_template); ?>" style="position:relative;max-width:850px;margin-top:8px">
                    <input type="search" class="regular-text" autocomplete="off" placeholder="搜索用户名或昵称，为该用户添加单独规则" aria-label="搜索并添加指定用户上传规则">
                    <div class="yxf-upload-rule-user-results" hidden></div>
                    <div class="yxf-upload-rule-user-cards">
                        <?php foreach ($upload_user_rules as $configured_user_id => $configured_rule) : if (!$configured_user = get_userdata(absint($configured_user_id))) { continue; } ?>
                            <article class="yxf-upload-rule-user-card" data-user-id="<?php echo absint($configured_user_id); ?>"><button class="button-link-delete yxf-upload-rule-remove-user" type="button">移除</button><h4><?php echo esc_html($configured_user->display_name ?: $configured_user->user_login); ?>（<?php echo esc_html($configured_user->user_login); ?>）</h4><?php self::render_upload_rule_fields('upload_user_rules[' . absint($configured_user_id) . ']', self::normalize_upload_rule($configured_rule), 'yxf-upload-user-' . absint($configured_user_id)); ?></article>
                        <?php endforeach; ?>
                    </div>
                    </div>
                    <p class="description">添加后可为该用户设置独立规则；移除后会恢复使用角色规则或默认规则。</p>
                </details>
                <?php else : ?>
                <div class="yxf-upload-rule-options is-locked"><div class="yxf-upload-rule-locked-title"><span>指定用户专属限制</span><strong>🔒 该功能需授权后才可使用</strong></div></div>
                <?php endif; ?>
                    </td></tr>
                </table>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">独立邮箱登录设置</th><td>
                        <p><strong>允许独立登录的用户角色</strong></p>
                        <?php foreach (array('subscriber' => '订阅者', 'contributor' => '贡献者', 'author' => '作者', 'editor' => '编辑', 'administrator' => '管理员') as $role => $label) : ?>
                            <?php $role_locked = !$independent_login_authorized; ?>
                            <label style="display:inline-block;margin:0 16px 8px 0;<?php echo $role_locked ? 'color:#8c8f94' : ''; ?>"><input type="checkbox" name="independent_login_roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, (array) $settings['independent_login_roles'], true)); ?> <?php disabled($role_locked); ?>> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                        <p style="margin:14px 0 6px"><strong>指定具体用户允许独立登录</strong></p>
                        <?php
                        $selected_user_ids = array_values(array_filter(array_map('absint', (array) $settings['independent_login_user_ids'])));
                        $searchable_users = array();
                        foreach ($all_users as $user) {
                            $searchable_users[] = array(
                                'id' => absint($user->ID),
                                'label' => (string) ($user->display_name ?: $user->user_login),
                                'login' => (string) $user->user_login,
                            );
                        }
                        ?>
                        <div id="yxf-gallery-user-search" data-users="<?php echo esc_attr(wp_json_encode($searchable_users)); ?>" data-selected="<?php echo esc_attr(wp_json_encode($selected_user_ids)); ?>" data-disabled="<?php echo $independent_login_authorized ? '0' : '1'; ?>" style="position:relative;max-width:520px">
                            <input type="search" class="regular-text" autocomplete="off" placeholder="输入用户名或昵称搜索并选择用户" <?php disabled(!$independent_login_authorized); ?> aria-label="搜索并选择具体用户">
                            <div class="yxf-gallery-user-results" hidden style="position:absolute;z-index:2;top:100%;left:0;right:0;max-height:220px;overflow:auto;background:#fff;border:1px solid #ccd0d4;border-top:0;box-shadow:0 4px 10px rgba(0,0,0,.08)"></div>
                            <div class="yxf-gallery-user-selected" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px"></div>
                            <div class="yxf-gallery-user-inputs">
                                <?php foreach ($selected_user_ids as $selected_user_id) : ?><input type="hidden" name="independent_login_user_ids[]" value="<?php echo absint($selected_user_id); ?>"><?php endforeach; ?>
                            </div>
                        </div>
                        <p class="description">勾选角色或选择账号后，该角色或用户可通过 WordPress 后台登录自己的 NameCrane 邮箱账号，独立使用和管理自己的邮箱文件空间。</p>
                        <p class="description"><strong>【⚠️注意】</strong></p>
                        <ol class="description" style="margin:0 0 0 20px;list-style:decimal">
                            <li>未被授权使用独立邮箱登录的用户只能使用基础配置中的<a href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-settings&tab=basic#yxf-default-mailbox-settings')); ?>">默认邮箱账号</a>存储空间，文件记录仍会归实际上传者所有，用户只能查看和管理自己的图片。</li>
                            <li><strong>独立登录页面仅允许独立登录的用户角色或账号可以在媒体库菜单查看并登录，请确保其拥有访问 WordPress 后台的权限。</strong></li>
                            <li>独立登录用户的邮箱账号需要你自行在 <a href="https://namecrane.com/clientarea.php" target="_blank" rel="noopener noreferrer">NameCrane 邮箱控制台</a>中添加，并分配存储空间使用量。</li>
                            <li>如独立登录用户未在后台登录自己的邮箱账号，仍会使用<a href="<?php echo esc_url(admin_url('admin.php?page=yxf-gallery-settings&tab=basic#yxf-default-mailbox-settings')); ?>">默认邮箱账号</a>存储空间。</li>
                        </ol><?php if (!$independent_login_authorized) : ?><p class="description" style="color:#b32d2e">🔒 该功能需授权后才可使用。</p><?php endif; ?>
                    </td></tr>
                </table>
                <p class="submit"><button class="button button-primary" type="submit">保存设置</button></p>
            </form>
            <script>
            (function(){
                var root=document.getElementById('yxf-gallery-user-search');
                if(!root)return;
                var input=root.querySelector('input'),results=root.querySelector('.yxf-gallery-user-results'),selectedBox=root.querySelector('.yxf-gallery-user-selected'),inputs=root.querySelector('.yxf-gallery-user-inputs'),disabled=root.getAttribute('data-disabled')==='1',users=[],selected=[];
                try{users=JSON.parse(root.getAttribute('data-users')||'[]');selected=JSON.parse(root.getAttribute('data-selected')||'[]').map(Number);}catch(error){return;}
                var byId=function(id){return users.find(function(user){return Number(user.id)===Number(id);});};
                var sync=function(){inputs.innerHTML='';selectedBox.innerHTML='';selected.forEach(function(id){var user=byId(id);if(!user)return;var hidden=document.createElement('input');hidden.type='hidden';hidden.name='independent_login_user_ids[]';hidden.value=user.id;inputs.appendChild(hidden);var tag=document.createElement('span');tag.style.cssText='display:inline-flex;align-items:center;gap:5px;padding:3px 7px;background:#f0f6fc;border-radius:3px';tag.appendChild(document.createTextNode(user.label+'（'+user.login+'）'));var remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label','移除 '+user.label);remove.style.cssText='padding:0;border:0;background:transparent;cursor:pointer;font-size:16px;line-height:16px';remove.disabled=disabled;remove.addEventListener('click',function(){selected=selected.filter(function(item){return Number(item)!==Number(user.id);});sync();});tag.appendChild(remove);selectedBox.appendChild(tag);});};
                var hide=function(){results.hidden=true;results.innerHTML='';};
                var show=function(keyword){var term=keyword.trim().toLowerCase();if(!term||disabled){hide();return;}var matches=users.filter(function(user){return selected.indexOf(Number(user.id))===-1&&(user.label+' '+user.login).toLowerCase().indexOf(term)!==-1;}).slice(0,20);results.innerHTML='';matches.forEach(function(user){var button=document.createElement('button');button.type='button';button.textContent=user.label+'（'+user.login+'）';button.style.cssText='display:block;width:100%;padding:8px 10px;border:0;background:#fff;text-align:left;cursor:pointer';button.addEventListener('click',function(){selected.push(Number(user.id));input.value='';hide();sync();input.focus();});results.appendChild(button);});if(!matches.length){var empty=document.createElement('div');empty.textContent='未找到匹配用户';empty.style.cssText='padding:8px 10px;color:#646970';results.appendChild(empty);}results.hidden=false;};
                input.addEventListener('input',function(){show(input.value);});input.addEventListener('keydown',function(event){if(event.key==='Escape')hide();});document.addEventListener('click',function(event){if(!root.contains(event.target))hide();});sync();
            }());
            </script>
            <script>
            (function(){
                var updateFormatSummary=function(fields){if(!fields)return;var selected=Array.prototype.slice.call(fields.querySelectorAll('.yxf-upload-rule-format-input:checked')).map(function(input){return input.value.toUpperCase();}),summary=fields.querySelector('[data-yxf-format-summary]');if(summary)summary.textContent='已选 '+selected.length+' 种'+(selected.length?'：'+selected.join('、'):'');};
                document.addEventListener('click',function(event){var open=event.target.closest('[data-yxf-open-formats]');if(open){var fields=open.closest('.yxf-upload-rule-fields'),modal=fields&&fields.querySelector('.yxf-upload-rule-format-modal');if(modal){modal.hidden=false;var close=modal.querySelector('[data-yxf-close-formats]');if(close)close.focus();}return;}var close=event.target.closest('[data-yxf-close-formats]');if(close){var modal=close.closest('.yxf-upload-rule-format-modal');if(modal)modal.hidden=true;}});
                document.addEventListener('click',function(event){var control=event.target.closest('[data-yxf-format-action]');if(!control)return;var modal=control.closest('.yxf-upload-rule-format-modal'),fields=modal&&modal.closest('.yxf-upload-rule-fields');if(!modal||!fields)return;var defaults=[];try{defaults=JSON.parse(modal.getAttribute('data-yxf-default-formats')||'[]');}catch(error){}Array.prototype.forEach.call(fields.querySelectorAll('.yxf-upload-rule-format-input'),function(input){if(control.getAttribute('data-yxf-format-action')==='all'){input.checked=true;}else if(control.getAttribute('data-yxf-format-action')==='none'){input.checked=false;}else{input.checked=defaults.indexOf(input.value)!==-1;}});updateFormatSummary(fields);});
                document.addEventListener('change',function(event){if(event.target.matches('.yxf-upload-rule-format-input'))updateFormatSummary(event.target.closest('.yxf-upload-rule-fields'));});
                document.addEventListener('keydown',function(event){if(event.key!=='Escape')return;var modal=document.querySelector('.yxf-upload-rule-format-modal:not([hidden])');if(modal)modal.hidden=true;});
                document.addEventListener('change',function(event){var toggle=event.target.closest('.yxf-upload-rule-enable');if(!toggle)return;var card=toggle.closest('[data-yxf-role-card]'),status=card&&card.querySelector('[data-yxf-role-status]');if(!card)return;card.classList.toggle('is-disabled',!toggle.checked);if(status)status.textContent=toggle.checked?'正在使用单独规则':'沿用默认规则';});
                var root=document.getElementById('yxf-upload-rule-users');
                if(!root)return;
                var input=root.querySelector('input[type=search]'),results=root.querySelector('.yxf-upload-rule-user-results'),cards=root.querySelector('.yxf-upload-rule-user-cards'),users=[],template=root.getAttribute('data-template')||'';
                try{users=JSON.parse(root.getAttribute('data-users')||'[]');}catch(error){return;}
                var hasCard=function(id){return !!cards.querySelector('[data-user-id="'+String(id).replace(/"/g,'')+'"]');};
                var hide=function(){results.hidden=true;results.innerHTML='';};
                var add=function(user){if(!user||hasCard(user.id))return;var card=document.createElement('article');card.className='yxf-upload-rule-user-card';card.setAttribute('data-user-id',user.id);card.innerHTML='<button class="button-link-delete yxf-upload-rule-remove-user" type="button">移除</button><h4></h4>'+template.split('__USER_ID__').join(String(user.id));card.querySelector('h4').textContent=user.label+'（'+user.login+'）';cards.appendChild(card);};
                var show=function(keyword){var term=String(keyword||'').trim().toLowerCase();if(!term){hide();return;}var matches=users.filter(function(user){return !hasCard(user.id)&&(user.label+' '+user.login).toLowerCase().indexOf(term)!==-1;}).slice(0,20);results.innerHTML='';matches.forEach(function(user){var button=document.createElement('button');button.type='button';button.textContent=user.label+'（'+user.login+'）';button.style.cssText='display:block;width:100%;padding:8px 10px;border:0;background:#fff;text-align:left;cursor:pointer';button.addEventListener('click',function(){add(user);input.value='';hide();input.focus();});results.appendChild(button);});if(!matches.length){var empty=document.createElement('div');empty.textContent='未找到匹配用户';empty.style.cssText='padding:8px 10px;color:#646970';results.appendChild(empty);}results.hidden=false;};
                input.addEventListener('input',function(){show(input.value);});input.addEventListener('keydown',function(event){if(event.key==='Escape')hide();});cards.addEventListener('click',function(event){var button=event.target.closest('.yxf-upload-rule-remove-user');if(button){var card=button.closest('.yxf-upload-rule-user-card');if(card)card.remove();}});document.addEventListener('click',function(event){if(!root.contains(event.target))hide();});
            }());
            </script>
            <?php else : ?>
            <?php $license = self::license_status(true); $license_status = sanitize_key((string) ($license['status'] ?? 'service_unavailable')); $license_allowed = !empty($license['allowed']); $license_active = $license_status === 'active'; $license_price = (float) ($license['lifetime_price'] ?? 19.90); $license_price = ($license_price >= 0.01 && $license_price <= 99999) ? $license_price : 19.90; $license_price_label = rtrim(rtrim(number_format($license_price, 2, '.', ''), '0'), '.'); $payment = get_transient('yxf_gallery_license_payment_' . get_current_user_id()); ?>
            <form id="yxf-gallery-license-form" method="post">
                <?php wp_nonce_field('yxf_gallery_license'); ?>
                <input type="hidden" name="yxf_gallery_action" value="start_license_checkout">
                <table class="form-table" role="presentation">
                    <tr><th scope="row">授权状态</th><td><strong style="color:<?php echo $license_allowed ? '#00a32a' : '#b32d2e'; ?>"><?php echo esc_html($license_active ? '已获得授权' : ($license_status === 'trial' ? '免费试用中' : '需要授权')); ?></strong><p class="description"><?php echo esc_html((string) ($license['message'] ?? '正在连接授权服务。')); ?></p><?php if (!$license_active) : ?><p style="margin:10px 0 0"><button id="yxf-gallery-start-checkout" class="button button-primary" type="button">获取永久授权 ￥<?php echo esc_html($license_price_label); ?></button></p><?php endif; ?></td></tr>
                    <tr><th scope="row">授权说明</th><td><?php if ($license_active) : ?><p>您可以使用插件的完整功能，如有问题请与我们联系：admin@youxianfeng.com</p><?php else : ?><p>试用期可有限体验该插件的核心功能，试用产生的数据和图片链接不会丢失。如您觉得插件好用，请支持我们，一经授权永久使用。</p><?php endif; ?></td></tr>
                </table>
            </form>
            <?php if (!$license_active) : ?>
                <div id="yxf-gallery-payment-modal" role="dialog" aria-modal="true" aria-labelledby="yxf-gallery-payment-title" style="position:fixed;z-index:100001;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.45)">
                    <div id="yxf-gallery-payment-card" class="card" style="position:relative;width:min(100%,460px);padding:28px;text-align:center;box-sizing:border-box">
                        <button type="button" id="yxf-gallery-payment-close" aria-label="关闭付款窗口" style="position:absolute;top:10px;right:10px;width:32px;height:32px;padding:0;border:0;border-radius:50%;background:#f0f0f1;color:#50575e;font-size:24px;line-height:32px;text-align:center;cursor:pointer">×</button>
                        <h2 id="yxf-gallery-payment-title" style="margin-top:0">正在获取支付宝二维码</h2>
                        <div id="yxf-gallery-payment-qr" style="min-height:220px;display:flex;align-items:center;justify-content:center;margin:16px auto"></div>
                        <p class="description" id="yxf-gallery-payment-status" style="color:#00a32a;font-weight:600">正在获取支付宝二维码，请稍候。</p>
                        <p class="description" id="yxf-gallery-payment-order"></p>
                    </div>
                </div>
                <script>
                (function(){
                    var modal=document.getElementById('yxf-gallery-payment-modal'),box=document.getElementById('yxf-gallery-payment-card'),title=document.getElementById('yxf-gallery-payment-title'),qr=document.getElementById('yxf-gallery-payment-qr'),status=document.getElementById('yxf-gallery-payment-status'),order=document.getElementById('yxf-gallery-payment-order'),close=document.getElementById('yxf-gallery-payment-close'),start=document.getElementById('yxf-gallery-start-checkout'),timer=null,stopped=true;
                    if(!modal||!box||!qr||!status||!window.fetch)return;
                    var clearTimer=function(){if(timer){window.clearTimeout(timer);timer=null;}};
                    var dismiss=function(){stopped=true;clearTimer();modal.style.display='none';if(start)start.disabled=false;};
                    var open=function(){stopped=false;modal.style.display='flex';};
                    var waitingMessage=function(message){return (message||'等待支付宝付款。')+' 支付完成后请等待支付成功结果再关闭支付窗口。';};
                    var setLoading=function(){title.textContent='正在获取支付宝二维码';qr.innerHTML='<span class="spinner is-active" style="float:none;margin:0"></span>';status.textContent='正在获取支付宝二维码，请稍候。';order.textContent='';};
                    var showQr=function(payment){qr.innerHTML='';if(payment.url_qrcode){var wrap=document.createElement('div');wrap.style.cssText='position:relative;width:220px;height:220px';var image=document.createElement('img');image.src=payment.url_qrcode;image.alt='支付宝付款码';image.style.cssText='display:block;width:220px;height:220px';var logo=document.createElement('img');logo.src=<?php echo wp_json_encode(plugin_dir_url(__FILE__) . 'assets/alipay-logo.svg'); ?>;logo.alt='支付宝';logo.title='支付宝';logo.style.cssText='position:absolute;left:50%;top:50%;width:42px;height:42px;transform:translate(-50%,-50%);padding:6px;box-sizing:border-box;border:4px solid #fff;border-radius:50%;background:#fff;box-shadow:0 1px 5px rgba(0,0,0,.22)';wrap.appendChild(image);wrap.appendChild(logo);qr.appendChild(wrap);return;}if(payment.url){var link=document.createElement('a');link.className='button button-primary';link.target='_blank';link.rel='noopener';link.href=payment.url;link.textContent='打开支付宝付款';qr.appendChild(link);}};
                    var poll=function(){if(stopped)return;var body=new URLSearchParams({action:'yxf_gallery_license_order_status',nonce:<?php echo wp_json_encode(wp_create_nonce('yxf_gallery_license_status')); ?>});fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),credentials:'same-origin'}).then(function(response){return response.json();}).then(function(result){if(stopped)return;if(!result.success){status.textContent=(result.data&&result.data.message)||'订单状态查询失败，请稍后重试。';timer=window.setTimeout(poll,5000);return;}var data=result.data||{};if(data.paid&&data.authorized){title.textContent='授权已开通';status.textContent=data.message||'付款成功，当前网站域名已开通授权。';qr.innerHTML='';order.textContent='';stopped=true;window.setTimeout(function(){window.location.reload();},1500);return;}if(data.closed){status.textContent='订单已关闭，请重新发起付款。';return;}status.textContent=waitingMessage(data.message);timer=window.setTimeout(poll,3000);}).catch(function(){if(!stopped)timer=window.setTimeout(poll,5000);});};
                    var startCheckout=function(){if(!start||start.disabled)return;var reusable=existing&&existing.order_token&&Math.abs(Number(existing.price)-currentPrice)<0.001;if(reusable){open();title.textContent='请使用支付宝扫码付款';showQr(existing);status.textContent=waitingMessage(existing.message||'请使用支付宝扫码付款。');order.textContent=existing.order_num?'订单号：'+existing.order_num:'';poll();return;}open();setLoading();start.disabled=true;var body=new URLSearchParams({action:'yxf_gallery_start_license_checkout',nonce:<?php echo wp_json_encode(wp_create_nonce('yxf_gallery_license')); ?>});fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),credentials:'same-origin'}).then(function(response){return response.json();}).then(function(result){if(!result.success)throw new Error((result.data&&result.data.message)||'暂时无法创建支付订单，请稍后重试。');var payment=result.data||{};existing=payment;title.textContent='请使用支付宝扫码付款';showQr(payment);status.textContent=waitingMessage(payment.message||'请使用支付宝扫码付款。');order.textContent=payment.order_num?'订单号：'+payment.order_num:'';poll();}).catch(function(error){title.textContent='付款码生成失败';qr.innerHTML='';status.textContent=error.message||'暂时无法创建支付订单，请稍后重试。';start.disabled=false;});};
                    if(close)close.addEventListener('click',dismiss);modal.addEventListener('click',function(event){if(event.target===modal)dismiss();});if(start)start.addEventListener('click',startCheckout);
                    var currentPrice=<?php echo wp_json_encode((float) $license_price); ?>,existing=<?php echo wp_json_encode(is_array($payment) ? $payment : null); ?>;
                }());
                </script>
            <?php endif; ?>
            <?php endif; ?>
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
        $brand = self::brand_name();
        printf(' <a id="insert-yxf-gallery-button" href="%1$s" class="button thickbox yxf-gallery-media-button%2$s" title="%3$s" style="display:inline-flex;align-items:center;gap:4px;line-height:28px"><span class="dashicons dashicons-format-gallery" aria-hidden="true" style="width:18px;height:18px;font-size:18px;line-height:18px"></span><span>%4$s</span></a>', esc_url($url), $replacing ? ' is-media-replacement' : '', esc_attr($brand), esc_html($replacing ? '添加媒体' : $brand));
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
        $theme = sanitize_key(wp_unslash($_GET['yxf_gallery_theme'] ?? 'light')) === 'dark' ? 'dark' : 'light';
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="' . esc_attr(get_option('blog_charset')) . '"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="yxf-gallery-theme-' . esc_attr($theme) . '">';
        self::render_media_iframe();
        echo '</body></html>';
        exit;
    }

    public static function render_media_iframe() {
        if (!self::can_use_gallery()) {
            wp_die('无权访问图库。');
        }
        $brand = self::brand_name();
        if (!self::user_has_login()) {
            ?>
            <style>body{margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327}.yxf-login-guide{max-width:520px;margin:75px auto;padding:30px;background:#fff;border:1px solid #dcdcde;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.04)}.yxf-login-guide h2{margin-top:0}.yxf-login-guide p{color:#646970;line-height:1.7}.yxf-login-guide .button{margin:8px 4px}</style>
            <div class="yxf-login-guide"><h2>请先登录 NameCrane 邮箱</h2><p>登录后才能查看自己的 <?php echo esc_html($brand); ?>、上传媒体或插入文章。当前文章不会被保存或修改。</p><p><a class="button button-primary" target="_top" href="<?php echo esc_url(self::login_url()); ?>">前往登录</a><button class="button" type="button" onclick="if(window.parent&&window.parent.YXFGalleryClose){window.parent.YXFGalleryClose();}else if(window.parent&&window.parent.tb_remove){window.parent.tb_remove();}">暂不登录</button></p></div>
            <?php
            return;
        }
        // 上传页首屏不读取媒体列表；切换到“全部文件”后由 AJAX 按需加载。
        $media_items = array();
        $post_id = absint($_REQUEST['post_id'] ?? 0);
        $callback = sanitize_key(wp_unslash($_REQUEST['yxf_gallery_callback'] ?? ''));
        $multiple = max(1, absint($_REQUEST['yxf_gallery_multiple'] ?? 1));
        $upload_rule = self::upload_rule_for_user();
        $max_upload_files = (int) $upload_rule['max_files'];
        $upload_accept = self::upload_rule_accept_attribute($upload_rule);
        $upload_limits_by_extension = self::upload_rule_limits_by_extension($upload_rule);
        $upload_type_requirements = self::upload_rule_type_requirements($upload_rule);
        $raw_requested_type = $_REQUEST['yxf_gallery_type'] ?? 'all';
        $requested_type = is_string($raw_requested_type) ? sanitize_key(wp_unslash($raw_requested_type)) : 'all';
        $requested_type = in_array($requested_type, array('image', 'video', 'audio', 'file', 'all'), true) ? $requested_type : 'all';
        // 从任何上传/添加图片入口进入时，都应先显示上传文件页。
        $active_tab = sanitize_key(wp_unslash($_GET['yxf_gallery_tab'] ?? 'upload')) === 'library' ? 'library' : 'upload';
        ?>
        <style>
            html,body{height:100%;min-height:100%;margin:0;background:#f0f0f1;overflow:hidden}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327}.yxf-media-frame{position:fixed;inset:0;display:flex;flex-direction:column;background:#fff}.yxf-media-tabs{height:56px;display:flex;align-items:flex-end;padding:0 24px;border-bottom:1px solid #dcdcde;background:#fff}.yxf-media-tab{height:56px;padding:0 14px;border:0;border-bottom:4px solid transparent;background:transparent;color:#50575e;font-size:14px;cursor:pointer}.yxf-media-tab.is-active{border-bottom-color:#2271b1;color:#1d2327;font-weight:600}.yxf-media-body{position:relative;z-index:1;flex:1;min-height:0}.yxf-media-panel{display:none;height:100%}.yxf-media-panel.is-active{display:block}.yxf-upload-panel{box-sizing:border-box;padding:28px 40px;overflow:auto}.yxf-upload-box{max-width:760px;margin:0 auto;padding:40px 30px;border:2px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;text-align:center}.yxf-upload-box h2{margin:0 0 10px;font-size:20px}.yxf-upload-box p{color:#646970}.yxf-upload-box input[type=file]{display:block;max-width:100%;margin:22px auto}.yxf-upload-actions{display:flex;justify-content:center;gap:8px}.yxf-upload-queue{max-width:760px;margin:22px auto 0;padding:0;list-style:none;border-top:1px solid #dcdcde}.yxf-upload-queue:empty{display:none}.yxf-upload-item{display:flex;gap:12px;align-items:center;padding:12px 2px;border-bottom:1px solid #e5e5e5}.yxf-upload-item-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left}.yxf-upload-item-status{font-size:12px;color:#646970}.yxf-upload-item.is-uploading .yxf-upload-item-status,.yxf-upload-item.is-pending .yxf-upload-item-status{color:#2271b1}.yxf-upload-item.is-success .yxf-upload-item-status{color:#00a32a}.yxf-upload-item.is-error .yxf-upload-item-status{color:#d63638}.yxf-upload-item-remove{color:#b32d2e;border:0;background:none;cursor:pointer}.yxf-uploaded-wrap{max-width:760px;margin:26px auto 0}.yxf-uploaded-wrap:empty{display:none}.yxf-uploaded-title{margin:0 0 10px;font-weight:600;text-align:left}.yxf-uploaded-thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:10px}.yxf-uploaded-thumb{position:relative;display:block;aspect-ratio:1;padding:0;border:2px solid transparent;background:#f0f0f1;cursor:pointer;overflow:hidden}.yxf-uploaded-thumb:hover,.yxf-uploaded-thumb.is-selected{border-color:#2271b1}.yxf-uploaded-thumb img{display:block;width:100%;height:100%;object-fit:cover}.yxf-uploaded-thumb span{position:absolute;inset:auto 0 0;padding:4px 5px;background:rgba(0,0,0,.62);color:#fff;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.yxf-library-panel{display:flex;flex-direction:column}.yxf-library-toolbar{display:flex;align-items:center;gap:10px;min-height:54px;padding:0 18px;border-bottom:1px solid #dcdcde;background:#f6f7f7}.yxf-library-toolbar select{min-width:128px}.yxf-library-toolbar input{flex:1;min-width:0;max-width:280px;height:32px;box-sizing:border-box}.yxf-library-main{display:flex;flex:1;min-height:0}.yxf-attachments{flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));align-content:start;gap:16px;margin:0;padding:20px;overflow:auto;list-style:none}.yxf-attachment{position:relative;aspect-ratio:1;border:1px solid #dcdcde;background:#f0f0f1;cursor:pointer;overflow:hidden}.yxf-attachment img{width:100%;height:100%;object-fit:cover;display:block}.yxf-file-icon{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#646970;font-size:12px;padding:12px;text-align:center;word-break:break-all}.yxf-file-icon b{font-size:28px;line-height:1;margin-bottom:10px;color:#2271b1}.yxf-attachment.is-selected{border:3px solid #2271b1}.yxf-attachment.is-selected:after{content:"✓";position:absolute;right:0;top:0;width:24px;height:24px;background:#2271b1;color:#fff;font-weight:700;text-align:center;line-height:24px}.yxf-empty{grid-column:1/-1;padding:70px 20px;text-align:center;color:#646970}.yxf-details{width:300px;box-sizing:border-box;padding:20px;border-left:1px solid #dcdcde;background:#fff;overflow:auto}.yxf-details.is-empty{color:#646970;padding-top:70px;text-align:center}.yxf-details img,.yxf-details video{width:100%;height:190px;object-fit:contain;background:#f0f0f1;margin-bottom:18px}.yxf-detail-title{margin:0 0 14px;font-size:15px;word-break:break-word}.yxf-detail-meta{margin:6px 0;color:#646970;font-size:12px;word-break:break-all}.yxf-detail-url{display:block;max-height:64px;overflow:auto;color:#2271b1;font-size:12px;word-break:break-all}.yxf-detail-actions{display:flex;gap:8px;margin-top:16px}.yxf-media-footer{position:relative;z-index:10;display:flex;align-items:center;flex:0 0 60px;gap:8px;min-height:60px;padding:12px 18px;box-sizing:border-box;border-top:1px solid #dcdcde;background:#fff;box-shadow:0 -2px 8px rgba(0,0,0,.06)}.yxf-media-footer input{flex:1;min-width:0;max-width:420px;height:32px;margin-left:auto;box-sizing:border-box}.yxf-media-footer .button{height:32px;margin:0;white-space:nowrap}.yxf-notice{margin:16px 0}.yxf-hidden{display:none!important}@media(max-width:720px){.yxf-upload-panel{padding:20px}.yxf-details{width:240px}.yxf-media-footer input{max-width:none}.yxf-attachments{grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:10px;padding:12px}}
        </style>
        <style>
            /* 与子比主题卡片、按钮和夜间模式保持一致的前台图库窗口。 */
            :root{--yxf-bg:#f7f8fb;--yxf-surface:#fff;--yxf-surface-soft:#f4f6fa;--yxf-text:#303133;--yxf-muted:#8b93a3;--yxf-line:rgba(43,61,93,.1);--yxf-primary:#4e6ef2;--yxf-primary-end:#65a5ff;--yxf-shadow:0 14px 36px rgba(38,55,94,.14)}
            body.yxf-gallery-theme-dark{--yxf-bg:#292a2d;--yxf-surface:#34363a;--yxf-surface-soft:#2e3034;--yxf-text:#e7eaf0;--yxf-muted:#9299a6;--yxf-line:rgba(255,255,255,.09);--yxf-primary:#5c7cff;--yxf-primary-end:#38a1ff;--yxf-shadow:0 16px 38px rgba(0,0,0,.33)}
            body.yxf-gallery-theme-light,body.yxf-gallery-theme-dark{background:var(--yxf-bg);color:var(--yxf-text)}
            .yxf-media-frame{background:var(--yxf-bg);color:var(--yxf-text)}
            .yxf-media-tabs{height:64px;align-items:center;padding:0 22px;border-color:var(--yxf-line);background:var(--yxf-surface);box-shadow:0 2px 12px rgba(0,0,0,.025)}
            .yxf-media-tab{height:40px;margin-right:8px;padding:0 16px;border:0;border-radius:9px;color:var(--yxf-muted);font-weight:600;transition:.2s ease}
            .yxf-media-tab:hover{background:var(--yxf-surface-soft);color:var(--yxf-text)}
            .yxf-media-tab.is-active{border:0;background:linear-gradient(135deg,var(--yxf-primary),var(--yxf-primary-end));color:#fff;box-shadow:0 5px 14px color-mix(in srgb,var(--yxf-primary) 28%,transparent)}
            .yxf-upload-panel{padding:28px; background:var(--yxf-bg)}
            .yxf-upload-box{max-width:720px;padding:42px 32px;border:1.5px dashed color-mix(in srgb,var(--yxf-primary) 46%,var(--yxf-line));border-radius:16px;background:var(--yxf-surface);box-shadow:var(--yxf-shadow);transition:border-color .18s ease,background .18s ease,box-shadow .18s ease}.yxf-upload-box.is-dragover{border-color:var(--yxf-primary);background:color-mix(in srgb,var(--yxf-primary) 8%,var(--yxf-surface));box-shadow:0 0 0 4px color-mix(in srgb,var(--yxf-primary) 15%,transparent),var(--yxf-shadow)}
            .yxf-upload-box h2{font-size:21px;color:var(--yxf-text)}.yxf-upload-box p{color:var(--yxf-muted);line-height:1.8}.yxf-upload-box .yxf-upload-hint{margin:2px 0 18px;font-size:12px;line-height:1.65}.yxf-upload-hint-line,.yxf-upload-limit-lines,.yxf-upload-limit-lines span{display:block}.yxf-upload-limit-lines{margin:2px 0}.yxf-upload-hint-line:last-of-type{margin-top:2px}
            .yxf-format-guide{display:inline-flex;align-items:center;gap:4px;margin-left:7px;padding:1px 7px;border-radius:999px;background:color-mix(in srgb,var(--yxf-primary) 14%,transparent);color:var(--yxf-primary);font-size:12px;font-weight:600;line-height:1.55;text-decoration:none;vertical-align:1px}.yxf-format-guide:hover{filter:brightness(1.14);text-decoration:none}.yxf-upload-drop-tip{margin:13px 0 0!important;color:var(--yxf-primary)!important;font-size:12px!important;font-weight:600}.yxf-upload-drop-tip b{display:inline-block;margin-right:5px;font-size:15px;line-height:1;vertical-align:-1px}
            .yxf-upload-actions .button,.yxf-media-footer .button{border:0!important;border-radius:8px!important;box-shadow:none!important}.yxf-upload-actions .button-primary,.yxf-media-footer .button-primary{background:linear-gradient(135deg,var(--yxf-primary),var(--yxf-primary-end))!important;color:#fff!important}
            #yxf-frame-choose,#yxf-frame-start{min-height:40px;padding:0 16px!important;border:0!important;border-radius:8px!important;font-size:14px!important;font-weight:600!important;line-height:40px!important;box-shadow:none!important}#yxf-frame-choose{background:var(--yxf-surface-soft)!important;color:var(--yxf-text)!important;border:1px solid var(--yxf-line)!important}#yxf-frame-start:not(:disabled){background:linear-gradient(135deg,var(--yxf-primary),var(--yxf-primary-end))!important;color:#fff!important}#yxf-frame-start:disabled{opacity:.5;cursor:not-allowed}
            .yxf-upload-queue,.yxf-uploaded-wrap{max-width:720px}.yxf-upload-item{border-color:var(--yxf-line);color:var(--yxf-text)}.yxf-upload-item-status,.yxf-uploaded-title{color:var(--yxf-muted)}
            .yxf-uploaded-thumb{border-radius:10px;background:var(--yxf-surface-soft);border-color:transparent}.yxf-uploaded-thumb:hover,.yxf-uploaded-thumb.is-selected{border-color:var(--yxf-primary);box-shadow:0 0 0 2px color-mix(in srgb,var(--yxf-primary) 15%,transparent)}
            .yxf-library-toolbar{min-height:64px;padding:0 22px;border-color:var(--yxf-line);background:var(--yxf-surface)}.yxf-library-toolbar select,.yxf-library-toolbar input,.yxf-media-footer input{border:1px solid var(--yxf-line);border-radius:8px;background:var(--yxf-surface-soft);color:var(--yxf-text);outline:0}.yxf-library-toolbar select:focus,.yxf-library-toolbar input:focus,.yxf-media-footer input:focus{border-color:var(--yxf-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--yxf-primary) 14%,transparent)}
            .yxf-attachments{gap:14px;padding:20px;background:var(--yxf-bg)}.yxf-attachment{border:0;border-radius:12px;background:var(--yxf-surface);box-shadow:0 4px 14px rgba(0,0,0,.06);transition:transform .18s ease,box-shadow .18s ease}.yxf-attachment:hover{transform:translateY(-2px);box-shadow:var(--yxf-shadow)}.yxf-attachment.is-selected{border:3px solid var(--yxf-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--yxf-primary) 14%,transparent)}.yxf-attachment.is-selected:after{background:linear-gradient(135deg,var(--yxf-primary),var(--yxf-primary-end))}
            .yxf-library-list{display:flex;flex:1;min-width:0;flex-direction:column}.yxf-file-icon{color:var(--yxf-muted)}.yxf-file-icon b{color:var(--yxf-primary)}.yxf-details{border-color:var(--yxf-line);background:var(--yxf-surface);color:var(--yxf-text)}.yxf-details.is-empty,.yxf-detail-meta{color:var(--yxf-muted)}.yxf-details img,.yxf-details video{border-radius:10px;background:var(--yxf-surface-soft)}.yxf-detail-url{color:var(--yxf-primary)}
            .yxf-media-footer{min-height:76px;flex-basis:76px;padding:18px 22px;border-color:var(--yxf-line);background:var(--yxf-surface);box-shadow:0 -3px 16px rgba(0,0,0,.04)}.yxf-media-footer input{height:42px;max-width:520px;padding:0 13px;font-size:14px}.yxf-media-footer .button{height:42px;min-width:72px;padding:0 18px!important;font-size:14px;font-weight:600;line-height:42px}.yxf-media-footer #yxf-insert{min-width:88px;padding:0 26px!important}.yxf-empty{color:var(--yxf-muted)}.yxf-media-pagination{display:flex;align-items:center;justify-content:flex-end;gap:7px;min-height:54px;padding:9px 20px;box-sizing:border-box;border-top:1px solid var(--yxf-line);background:var(--yxf-surface);color:var(--yxf-muted);font-size:12px}.yxf-media-pagination-info{margin-right:auto}.yxf-media-page{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 7px;border:0;border-radius:8px;background:transparent;color:var(--yxf-muted);font:inherit;font-weight:600;cursor:pointer}.yxf-media-page:hover:not(:disabled){background:var(--yxf-surface-soft);color:var(--yxf-primary)}.yxf-media-page.is-current{background:linear-gradient(135deg,var(--yxf-primary),var(--yxf-primary-end));color:#fff;box-shadow:0 4px 10px color-mix(in srgb,var(--yxf-primary) 25%,transparent)}.yxf-media-page:disabled{opacity:.36;cursor:not-allowed}.yxf-media-page.is-ellipsis{min-width:16px;padding:0;cursor:default}.yxf-media-page.is-ellipsis:hover{background:transparent;color:var(--yxf-muted)}
            @media(max-width:720px){.yxf-media-tabs{height:56px;padding:0 10px}.yxf-media-tab{padding:0 12px}.yxf-upload-panel{padding:16px}.yxf-upload-box{padding:30px 18px;border-radius:12px}.yxf-details{width:220px}.yxf-attachments{gap:10px;padding:12px}}
        </style>
        <div class="yxf-media-frame" id="yxf-media-frame">
            <div class="yxf-media-tabs" role="tablist" aria-label="<?php echo esc_attr($brand); ?>">
                <button type="button" class="yxf-media-tab <?php echo $active_tab === 'upload' ? 'is-active' : ''; ?>" data-yxf-tab="upload" role="tab" aria-selected="<?php echo $active_tab === 'upload' ? 'true' : 'false'; ?>">上传文件</button>
                <button type="button" class="yxf-media-tab <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" data-yxf-tab="library" role="tab" aria-selected="<?php echo $active_tab === 'library' ? 'true' : 'false'; ?>">全部文件</button>
            </div>
            <div class="yxf-media-body">
                <section class="yxf-media-panel yxf-upload-panel <?php echo $active_tab === 'upload' ? 'is-active' : ''; ?>" data-yxf-panel="upload">
                    <div class="yxf-upload-box" id="yxf-upload-dropzone">
                        <h2>上传文件到<?php echo esc_html($brand); ?></h2>
                        <p class="yxf-upload-hint"><span class="yxf-upload-hint-line">单次最多上传 <?php echo esc_html($max_upload_files); ?> 个文件；文件大小与格式要求：</span><span class="yxf-upload-limit-lines"><?php foreach ($upload_type_requirements as $requirement) : ?><span><?php echo esc_html($requirement['label']); ?>：大小≤<?php echo esc_html($requirement['size_label']); ?>，格式：<?php echo esc_html(implode('、', array_map('strtoupper', $requirement['extensions']))); ?><?php if ($requirement['kind'] === 'image') : ?> <a class="yxf-format-guide" href="https://tinypng.com/" target="_blank" rel="noopener">免费压缩/转换 <span aria-hidden="true">↗</span></a><?php endif; ?></span><?php endforeach; ?></span></p>
                        <?php self::notices(); ?>
                        <?php if (!self::user_has_login()) : ?><div class="notice notice-warning inline"><p>请先在后台“<?php echo esc_html($brand); ?> → 登录”中填写你自己的 NameCrane 邮箱账号。</p></div><?php endif; ?>
                        <input id="yxf-frame-files" type="file" accept="<?php echo esc_attr($upload_accept); ?>" multiple class="yxf-hidden" <?php disabled(!self::user_has_login()); ?>>
                        <p class="yxf-upload-actions"><button class="button button-large" type="button" id="yxf-frame-choose" <?php disabled(!self::user_has_login()); ?>>选择文件</button><button class="button button-primary button-large" type="button" id="yxf-frame-start" disabled>开始上传</button></p>
                        <p class="yxf-upload-drop-tip"><b aria-hidden="true">⇩</b>也可以将文件拖到此处上传</p>
                    </div>
                    <ul class="yxf-upload-queue" id="yxf-frame-queue" aria-live="polite"></ul>
                    <div class="yxf-uploaded-wrap" id="yxf-frame-uploaded" aria-live="polite"></div>
                </section>
                <section class="yxf-media-panel yxf-library-panel <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" data-yxf-panel="library">
                    <?php self::notices(); ?>
                    <div class="yxf-library-toolbar">
                        <select id="yxf-type-filter" aria-label="筛选媒体类型"><option value="all">所有类型</option></select>
                        <input id="yxf-search" type="search" placeholder="搜索文件名称" aria-label="搜索文件名称">
                    </div>
                    <div class="yxf-library-main">
                        <div class="yxf-library-list"><ul class="yxf-attachments" id="yxf-attachments" aria-label="全部文件列表"></ul><nav class="yxf-media-pagination" id="yxf-media-pagination" aria-label="文件分页"></nav></div>
                        <aside class="yxf-details is-empty" id="yxf-details">请选择一个文件查看详情</aside>
                    </div>
                </section>
            </div>
            <div class="yxf-media-footer"><input id="yxf-selected-url" type="url" placeholder="选择文件或填写外部链接在此处" aria-label="文件外链地址"><button type="button" class="button" id="yxf-cancel" hidden>取消</button><button type="button" class="button button-primary" id="yxf-insert" disabled>插入</button></div>
        </div>
        <script>
        (function(){
            var items = <?php echo wp_json_encode($media_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var callbackKey = <?php echo wp_json_encode($callback); ?>;
            var selectionLimit = <?php echo (int) $multiple; ?>;
            var requestedType = <?php echo wp_json_encode($requested_type); ?>;
            var currentUser = <?php echo (int) get_current_user_id(); ?>;
            var maxUploadFiles = <?php echo (int) $max_upload_files; ?>;
            var allowedExtensions = <?php echo wp_json_encode(array_values($upload_rule['extensions'])); ?>;
            var uploadLimits = <?php echo wp_json_encode($upload_limits_by_extension); ?>;
            var active = null;
            var selectedItems = [];
            var frame = document.getElementById('yxf-media-frame');
            var attachments = document.getElementById('yxf-attachments');
            var pagination = document.getElementById('yxf-media-pagination');
            var details = document.getElementById('yxf-details');
            var insert = document.getElementById('yxf-insert');
            var cancel = document.getElementById('yxf-cancel');
            var type = document.getElementById('yxf-type-filter');
            var search = document.getElementById('yxf-search');
            var selectedUrl = document.getElementById('yxf-selected-url');
            var uploadInput = document.getElementById('yxf-frame-files');
            var uploadChoose = document.getElementById('yxf-frame-choose');
            var uploadStart = document.getElementById('yxf-frame-start');
            var uploadDropzone = document.getElementById('yxf-upload-dropzone');
            var uploadQueue = document.getElementById('yxf-frame-queue');
            var uploadedWrap = document.getElementById('yxf-frame-uploaded');
            var uploadItems = new Map();
            var uploadedItems = [];
            var uploading = false;
            var libraryLoaded = false;
            var libraryLoading = false;
            var libraryError = '';
            var libraryPage = 1;
            var libraryPerPage = 30;
            var libraryTotal = 0;
            var libraryTotalPages = 1;
            var librarySearchTimer = 0;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var uploadNonce = <?php echo wp_json_encode(wp_create_nonce('yxf_gallery_upload')); ?>;
            var libraryNonce = <?php echo wp_json_encode(wp_create_nonce('yxf_gallery_media_items')); ?>;
            var initialTab = <?php echo wp_json_encode($active_tab); ?>;
            var close = function(){ if (window.parent && window.parent.YXFGalleryClose) window.parent.YXFGalleryClose(); else if (window.parent && window.parent.tb_remove) window.parent.tb_remove(); else if (window.tb_remove) window.tb_remove(); };
            var updateFooter = function(){
                var hasValue = !!(selectedUrl && selectedUrl.value.trim());
                if (cancel) cancel.hidden = !hasValue;
                insert.disabled = !hasValue;
            };
            var clearSelection = function(){
                active = null;
                selectedItems = [];
                if (selectedUrl) selectedUrl.value = '';
                details.className = 'yxf-details is-empty';
                details.textContent = '请选择一个文件查看详情';
                render();
                renderUploadedThumbs();
                updateFooter();
            };
            var externalItem = function(url){
                var cleanUrl = String(url || '').trim();
                var path = cleanUrl.split(/[?#]/)[0];
                var fileName = decodeURIComponent((path.split('/').pop() || '外部文件')).replace(/\+/g, ' ');
                var extension = (fileName.split('.').pop() || '').toLowerCase();
                var kind = /^(avif|bmp|gif|heic|ico|jpe?g|png|svg|webp)$/.test(extension) ? 'image' : (/^(mp4|m4v|mov|ogv|webm)$/.test(extension) ? 'video' : (/^(mp3|m4a|ogg|opus|wav)$/.test(extension) ? 'audio' : 'file'));
                return {id:0, attachmentId:0, name:fileName, url:cleanUrl, mime:kind === 'image' ? 'image/*' : (kind === 'video' ? 'video/*' : (kind === 'audio' ? 'audio/*' : 'application/octet-stream')), kind:kind, fileSize:0, fileSizeLabel:'—', createdAt:''};
            };
            var copy = function(value, button){
                var done = function(){ var original = button.textContent; button.textContent = '已复制'; window.setTimeout(function(){ button.textContent = original; }, 1500); };
                if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(value).then(done); return; }
                var textarea = document.createElement('textarea'); textarea.value = value; textarea.style.position = 'fixed'; textarea.style.opacity = '0'; document.body.appendChild(textarea); textarea.select(); document.execCommand('copy'); textarea.remove(); done();
            };
            var refreshTypeOptions = function(mimeTypes){
                var selected = type.value || 'all';
                while (type.options.length > 1) type.remove(1);
                (mimeTypes || items.map(function(item){ return item.mime; })).forEach(function(itemMime){ if (itemMime && !Array.prototype.some.call(type.options, function(option){ return option.value === itemMime; })) { var option = document.createElement('option'); option.value = itemMime; option.textContent = itemMime.replace(/^.*\//, '').toUpperCase(); type.appendChild(option); } });
                type.value = Array.prototype.some.call(type.options, function(option){ return option.value === selected; }) ? selected : 'all';
            };
            var loadLibrary = function(page){
                page = Math.max(1, Number(page || 1));
                if (libraryLoading) return;
                libraryLoading = true;
                libraryError = '';
                render();
                var selectedMime = type.value === 'all' ? '' : (type.value || '');
                var data = new FormData(); data.append('action', 'yxf_gallery_media_items'); data.append('nonce', libraryNonce); data.append('page', page); data.append('per_page', libraryPerPage); data.append('search', search.value || ''); data.append('mime', selectedMime); data.append('kind', requestedType || 'all');
                fetch(ajaxUrl, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(response){ return response.json(); })
                    .then(function(payload){
                        if (!payload.success) throw new Error((payload.data && payload.data.message) || '文件列表加载失败。');
                        var result = payload.data || {};
                        items = result.items || [];
                        libraryPage = Number(result.page || page);
                        libraryPerPage = Number(result.perPage || libraryPerPage);
                        libraryTotal = Number(result.total || 0);
                        libraryTotalPages = Math.max(1, Number(result.totalPages || 1));
                        libraryLoaded = true;
                        refreshTypeOptions(result.mimeTypes || []);
                    })
                    .catch(function(error){ libraryError = (error && error.message) || '文件列表加载失败，请重试。'; libraryLoaded = true; })
                    .then(function(){ libraryLoading = false; render(); });
            };
            var switchTab = function(tab){
                frame.querySelectorAll('[data-yxf-tab]').forEach(function(button){ var selected = button.getAttribute('data-yxf-tab') === tab; button.classList.toggle('is-active', selected); button.setAttribute('aria-selected', selected ? 'true' : 'false'); });
                frame.querySelectorAll('[data-yxf-panel]').forEach(function(panel){ panel.classList.toggle('is-active', panel.getAttribute('data-yxf-panel') === tab); });
                if (tab === 'library' && !libraryLoaded) loadLibrary(1);
            };
            var insertItems = function(chosen){
                chosen = chosen || [];
                if (!chosen.length) return;
                if (callbackKey && window.parent) {
                    // 所有入口均通过回调键交回父页。后台的“地址输入框 + 上传”控件与
                    // 前台编辑器会各自按触发来源处理，不能在这里直接假定为正文编辑器。
                    window.parent.postMessage({type:'yxf_gallery_insert', callbackKey:callbackKey, items:chosen}, window.location.origin);
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
                var title = document.createElement('p'); title.className = 'yxf-uploaded-title'; title.textContent = '上传完成（点击下方文件选择插入）';
                var grid = document.createElement('div'); grid.className = 'yxf-uploaded-thumbs';
                uploadedItems.forEach(function(item){
                    var button = document.createElement('button'); button.type = 'button'; button.className = 'yxf-uploaded-thumb' + (active && active.id === item.id ? ' is-selected' : ''); button.title = '选中：' + item.name;
                    var image;
                    if (item.kind === 'image') { image = document.createElement('img'); image.src = item.url; image.alt = item.name; image.loading = 'lazy'; }
                    else { image = document.createElement('div'); image.className = 'yxf-file-icon'; image.innerHTML = '<b>' + (item.kind === 'video' ? '▶' : item.kind === 'audio' ? '♫' : '⌁') + '</b><span>文件</span>'; }
                    var label = document.createElement('span'); label.textContent = item.name;
                    button.append(image, label); button.addEventListener('click', function(){ showDetails(item, false); renderUploadedThumbs(); }); grid.appendChild(button);
                });
                uploadedWrap.append(title, grid);
            };
            var addUploadFiles = function(files){
                var rejected = [], remaining = Math.max(0, maxUploadFiles - uploadItems.size);
                Array.prototype.forEach.call(files || [], function(file){ var extension = (file.name.split('.').pop() || '').toLowerCase(), id = uploadKey(file), limit = uploadLimits[extension]; if (uploadItems.has(id)) return; if (allowedExtensions.indexOf(extension) === -1 || !limit) { rejected.push(file.name + '（格式不允许）'); return; } if (file.size > limit.bytes) { rejected.push(file.name + '（超过 ' + limit.label + '）'); return; } if (!remaining) { rejected.push(file.name + '（单次最多 ' + maxUploadFiles + ' 个）'); return; } uploadItems.set(id, {file:file, state:'waiting', message:'等待上传'}); remaining--; });
                if (rejected.length) window.alert('以下文件未加入上传队列：' + rejected.join('、'));
                uploadInput.value = ''; renderUploadQueue();
            };
            var addUploadedItem = function(data){
                if (!data || !data.url) return;
                var item = {id:Number(data.id || 0), attachmentId:Number(data.attachmentId || 0), name:data.name || '文件', url:data.url, mime:data.mime || 'image/*', kind:data.kind || 'image', fileSize:Number(data.fileSize || 0), fileSizeLabel:data.fileSizeLabel || '—', authorId:currentUser, createdAt:data.createdAt || ''};
                if (!items.some(function(existing){ return existing.id === item.id; })) items.unshift(item);
                if (!uploadedItems.some(function(existing){ return existing.id === item.id; })) uploadedItems.unshift(item);
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({type:'yxf_gallery_uploaded', item:item}, window.location.origin);
                }
                render(); renderUploadedThumbs();
            };
            var waitForPublicLink = function(entry, itemId){
                var attempt = 0;
                var check = function(){
                    attempt++;
                    var data = new FormData(); data.append('action', 'yxf_gallery_resolve_pending_item'); data.append('nonce', uploadNonce); data.append('item_id', itemId);
                    fetch(ajaxUrl, {method:'POST', body:data, credentials:'same-origin'})
                        .then(function(response){ return response.text(); })
                        .then(function(raw){
                            var payload;
                            try { payload = JSON.parse(raw); } catch (parseError) { throw new Error('服务器未返回有效的链接状态。'); }
                            if (!payload.success) throw new Error((payload.data && payload.data.message) || '公开链接检查失败。');
                            var result = payload.data || {};
                            if (result.url) {
                                entry.state = 'success'; entry.message = '上传完成'; addUploadedItem(result); renderUploadQueue(); return;
                            }
                            entry.message = result.warning || '已上传，公开链接正在生成';
                            if (attempt < 30) { window.setTimeout(check, 1500); }
                            else { entry.state = 'pending'; entry.message = '已上传，公开链接仍在生成，可稍后在全部文件查看'; }
                            renderUploadQueue();
                        })
                        .catch(function(){
                            if (attempt < 30) { window.setTimeout(check, 1500); }
                            else { entry.state = 'pending'; entry.message = '已上传，公开链接仍在生成，可稍后在全部文件查看'; renderUploadQueue(); }
                        });
                };
                window.setTimeout(check, 1200);
            };
            var uploadOne = async function(entry){
                entry.state = 'uploading'; entry.message = '正在上传…'; renderUploadQueue();
                var data = new FormData(); data.append('action', 'yxf_gallery_upload_image'); data.append('nonce', uploadNonce); data.append('gallery_file', entry.file, entry.file.name);
                try {
                    var response = await fetch(ajaxUrl, {method:'POST', body:data, credentials:'same-origin'}), raw = await response.text(), payload;
                    try { payload = JSON.parse(raw); } catch (parseError) { throw new Error('服务器未返回有效的上传结果，请重新登录游先锋邮箱后再试。'); }
                    if (!payload.success) throw new Error((payload.data && payload.data.message) || '上传失败，请重试。');
                    var result = payload.data || {};
                    if (result.url) { entry.state = 'success'; entry.message = result.duplicate ? '已存在，无需重复上传' : '上传完成'; addUploadedItem(result); }
                    else { entry.state = 'pending'; entry.message = result.warning || '已上传，公开链接正在生成'; waitForPublicLink(entry, result.id); }
                }
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
                if (selectedUrl) selectedUrl.value = active && active.url ? active.url : '';
                updateFooter();
                details.classList.remove('is-empty');
                details.innerHTML = '';
                var preview;
                if (item.kind === 'image') { preview = document.createElement('img'); preview.src = item.url; preview.alt = ''; }
                else if (item.kind === 'video') { preview = document.createElement('video'); preview.src = item.url; preview.controls = true; }
                else { preview = document.createElement('div'); preview.className = 'yxf-file-icon'; preview.innerHTML = '<b>⌁</b><span>媒体文件</span>'; }
                var title = document.createElement('h2'); title.className = 'yxf-detail-title'; title.textContent = item.name;
                var mime = document.createElement('p'); mime.className = 'yxf-detail-meta'; mime.textContent = '文件类型：' + (item.mime || '文件');
                var size = document.createElement('p'); size.className = 'yxf-detail-meta'; size.textContent = '文件大小：' + (item.fileSizeLabel || '—');
                var date = document.createElement('p'); date.className = 'yxf-detail-meta'; date.textContent = '上传时间：' + (item.createdAt || '');
                var link = document.createElement('a'); link.className = 'yxf-detail-url'; link.href = item.url; link.target = '_blank'; link.rel = 'noopener'; link.textContent = item.url;
                var actions = document.createElement('div'); actions.className = 'yxf-detail-actions';
                var copyButton = document.createElement('button'); copyButton.type = 'button'; copyButton.className = 'button'; copyButton.textContent = '复制链接'; copyButton.addEventListener('click', function(){ copy(item.url, copyButton); });
                actions.appendChild(copyButton); details.append(preview,title,mime,size,date,link,actions);
                attachments.querySelectorAll('.yxf-attachment').forEach(function(node){ node.classList.toggle('is-selected', selectedItems.some(function(selected){ return selected.id === Number(node.getAttribute('data-id')); })); });
            };
            var renderPagination = function(){
                if (!pagination) return;
                pagination.innerHTML = '';
                if (!libraryLoaded || libraryError) return;
                var info = document.createElement('span'); info.className = 'yxf-media-pagination-info'; info.textContent = libraryTotal ? ('共 ' + libraryTotal + ' 个文件，第 ' + libraryPage + '/' + libraryTotalPages + ' 页') : '暂无文件'; pagination.appendChild(info);
                if (libraryTotalPages <= 1) return;
                var addButton = function(label, page, className, disabled){ var button = document.createElement('button'); button.type = 'button'; button.className = 'yxf-media-page' + (className ? ' ' + className : ''); button.textContent = label; button.disabled = !!disabled; if (!disabled && page) button.addEventListener('click', function(){ if (page === libraryPage) return; clearSelection(); loadLibrary(page); }); pagination.appendChild(button); };
                var addEllipsis = function(){ addButton('…', 0, 'is-ellipsis', true); };
                addButton('‹', libraryPage - 1, '', libraryPage <= 1);
                var pages = [1];
                for (var candidate = Math.max(2, libraryPage - 1); candidate <= Math.min(libraryTotalPages - 1, libraryPage + 1); candidate++) pages.push(candidate);
                if (libraryTotalPages > 1) pages.push(libraryTotalPages);
                pages = pages.filter(function(value, index, list){ return list.indexOf(value) === index; }).sort(function(a,b){ return a - b; });
                var previous = 0;
                pages.forEach(function(page){ if (previous && page - previous > 1) addEllipsis(); addButton(String(page), page, page === libraryPage ? 'is-current' : '', page === libraryPage); previous = page; });
                addButton('›', libraryPage + 1, '', libraryPage >= libraryTotalPages);
            };
            var render = function(){
                attachments.innerHTML = '';
                if (!libraryLoaded) { var loading = document.createElement('li'); loading.className = 'yxf-empty'; loading.textContent = libraryLoading ? '正在加载文件…' : '文件列表正在后台准备。'; attachments.appendChild(loading); renderPagination(); return; }
                if (libraryError) { var failed = document.createElement('li'); failed.className = 'yxf-empty'; failed.textContent = libraryError; attachments.appendChild(failed); renderPagination(); return; }
                var filtered = visibleItems();
                if (!filtered.length) { var empty = document.createElement('li'); empty.className = 'yxf-empty'; empty.textContent = items.length ? '没有符合条件的媒体文件。' : '图库暂无媒体文件，请先上传文件。'; attachments.appendChild(empty); renderPagination(); return; }
                filtered.forEach(function(item){
                    var node = document.createElement('li'); node.className = 'yxf-attachment' + (active && active.id === item.id ? ' is-selected' : ''); node.setAttribute('data-id', item.id); node.setAttribute('title', item.name);
                    if (item.kind === 'image') { var image = document.createElement('img'); image.src = item.url; image.alt = item.name; image.loading = 'lazy'; node.appendChild(image); }
                    else { var icon = document.createElement('div'); icon.className = 'yxf-file-icon'; icon.innerHTML = '<b>' + (item.kind === 'video' ? '▶' : item.kind === 'audio' ? '♫' : '⌁') + '</b><span>' + item.name + '</span>'; node.appendChild(icon); }
                    node.addEventListener('click', function(){ showDetails(item, true); render(); renderUploadedThumbs(); }); attachments.appendChild(node);
                });
                renderPagination();
            };
            Array.prototype.forEach.call(document.querySelectorAll('[data-yxf-tab]'), function(button){ button.addEventListener('click', function(){ switchTab(button.getAttribute('data-yxf-tab')); }); });
            refreshTypeOptions();
            type.addEventListener('change', function(){ if (libraryLoaded) { clearSelection(); loadLibrary(1); } });
            search.addEventListener('input', function(){
                if (!libraryLoaded) return;
                window.clearTimeout(librarySearchTimer);
                librarySearchTimer = window.setTimeout(function(){ clearSelection(); loadLibrary(1); }, 260);
            });
            selectedUrl.addEventListener('input', function(){
                var value = selectedUrl.value.trim();
                // 用户编辑链接时，改用其手动输入的地址，不能再把先前选中的图库文件一并插入。
                if (!value) { clearSelection(); return; }
                if (active && value !== active.url) {
                    active = null;
                    selectedItems = [];
                    details.className = 'yxf-details is-empty';
                    details.textContent = '已使用手动输入的外部链接';
                    render();
                    renderUploadedThumbs();
                }
                updateFooter();
            });
            cancel.addEventListener('click', clearSelection);
            insert.addEventListener('click', function(){
                var url = selectedUrl.value.trim();
                if (!url) return;
                if (!selectedUrl.checkValidity()) { window.alert('请输入有效的外部链接地址。'); return; }
                var chosen = active && url === active.url && selectedItems.length ? selectedItems : [externalItem(url)];
                insertItems(chosen);
            });
            if (uploadChoose && uploadInput && uploadStart) {
                var dragDepth = 0;
                var preventFileDrop = function(event){ event.preventDefault(); event.stopPropagation(); };
                uploadChoose.addEventListener('click', function(){ uploadInput.click(); });
                uploadInput.addEventListener('change', function(){ addUploadFiles(uploadInput.files); });
                uploadStart.addEventListener('click', runUploadQueue);
                if (uploadDropzone && !uploadInput.disabled) {
                    uploadDropzone.addEventListener('dragenter', function(event){ preventFileDrop(event); dragDepth++; uploadDropzone.classList.add('is-dragover'); });
                    uploadDropzone.addEventListener('dragover', function(event){ preventFileDrop(event); event.dataTransfer.dropEffect = 'copy'; uploadDropzone.classList.add('is-dragover'); });
                    uploadDropzone.addEventListener('dragleave', function(event){ preventFileDrop(event); dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) uploadDropzone.classList.remove('is-dragover'); });
                    uploadDropzone.addEventListener('drop', function(event){ preventFileDrop(event); dragDepth = 0; uploadDropzone.classList.remove('is-dragover'); addUploadFiles(event.dataTransfer ? event.dataTransfer.files : null); });
                }
                renderUploadQueue();
            }
            if (initialTab === 'library') switchTab('library');
            render(); updateFooter();
            // 上传面板已经可交互后再预取首页；切到“全部文件”时即可直接展示。
            if (initialTab !== 'library') window.setTimeout(function(){ loadLibrary(1); }, 80);
        }());
        </script>
        <?php
    }
}

register_activation_hook(__FILE__, array('YouXianFeng_Gallery', 'activate'));
YouXianFeng_Gallery::init();
