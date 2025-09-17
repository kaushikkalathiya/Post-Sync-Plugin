<?php

if (!defined('ABSPATH')) {
    exit;
}

class Post_Sync_Plugin
{
    const OPTION_KEY = 'psp_options';
    const LOG_TABLE = 'psp_sync_logs';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }

    public function admin_menu()
    {
        add_menu_page('Post Sync', 'Post Sync', 'manage_options', 'post-sync', array($this, 'settings_page'));
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opts = $this->get_options();
?>
        <div class="wrap">
            <h1>Post Sync Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('psp_settings'); ?>
                <?php do_settings_sections('psp_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>Mode</th>
                        <td>
                            <label><input type="radio" name="<?php echo self::OPTION_KEY; ?>[mode]" value="host" <?php checked('host', $opts['mode']); ?>> Host</label>
                            &nbsp;
                            <label><input type="radio" name="<?php echo self::OPTION_KEY; ?>[mode]" value="target" <?php checked('target', $opts['mode']); ?>> Target</label>
                        </td>
                    </tr>
                </table>

                <?php if ($opts['mode'] === 'host'): ?>
                    <h2>Targets</h2>
                    <table class="form-table" id="psp-targets-table">
                        <tr>
                            <th>Target URL</th>
                            <th>Key (auto)</th>
                            <th>Actions</th>
                        </tr>
                        <?php
                        $targets = is_array($opts['targets']) ? $opts['targets'] : array();
                        foreach ($targets as $idx => $t):
                            $url = esc_attr($t['url'] ?? '');
                            $key = esc_attr($t['key'] ?? '');
                        ?>
                            <tr>
                                <td><input style="width:400px;" type="text" name="<?php echo self::OPTION_KEY; ?>[targets][<?php echo $idx; ?>][url]" value="<?php echo $url; ?>"></td>
                                <td><input type="text" readonly name="<?php echo self::OPTION_KEY; ?>[targets][<?php echo $idx; ?>][key]" value="<?php echo $key; ?>"></td>
                                <td><a class="button psp-remove-target" href="#">Remove</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="3"><a id="psp-add-target" class="button" href="#">Add New Target</a></td>
                        </tr>
                    </table>
                    <p class="submit"><button class="button button-primary">Save Changes</button></p>
                <?php else: ?>
                    <h2>Target Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th>Key</th>
                            <td><input style="width:400px;" type="text" name="<?php echo self::OPTION_KEY; ?>[target_key]" value="<?php echo esc_attr($opts['target_key']); ?>"></td>
                        </tr>
                        <tr>
                            <th>Translation Language</th>
                            <td>
                                <select name="<?php echo self::OPTION_KEY; ?>[translation_lang]">
                                    <option value="fr" <?php selected('fr', $opts['translation_lang']); ?>>French</option>
                                    <option value="es" <?php selected('es', $opts['translation_lang']); ?>>Spanish</option>
                                    <option value="hi" <?php selected('hi', $opts['translation_lang']); ?>>Hindi</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Allowed Host (bind key)</th>
                            <td><input style="width:400px;" type="text" name="<?php echo self::OPTION_KEY; ?>[allowed_host]" placeholder="https://host-site.example" value="<?php echo esc_attr($opts['allowed_host']); ?>">
                                <p class="description">Optional: the Host site URL that this key is valid for.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>ChatGPT Key</th>
                            <td><input style="width:400px;" type="text" name="<?php echo self::OPTION_KEY; ?>[chatgpt_key]" value="<?php echo esc_attr($opts['chatgpt_key']); ?>"></td>
                        </tr>
                    </table>
                    <p class="submit"><button class="button button-primary">Save Changes</button></p>
                <?php endif; ?>
            </form>
        </div>
<?php
    }

    public function register_settings()
    {
        register_setting('psp_settings', self::OPTION_KEY, array('sanitize_callback' => array($this, 'sanitize_options')));
    }

    public function sanitize_options($input)
    {
        // Ensure structure
        if (!is_array($input)) return array();
        $input = wp_parse_args($input, array('mode' => 'host', 'targets' => array()));

        // If host mode, ensure targets have keys
        if ($input['mode'] === 'host' && !empty($input['targets']) && is_array($input['targets'])) {
            $seen = array();
            foreach ($input['targets'] as $i => $t) {
                $url = trim($t['url'] ?? '');
                $key = trim($t['key'] ?? '');
                if (empty($key)) {
                    // generate a >=16 char key
                    $key = substr(bin2hex(random_bytes(12)), 0, 24);
                }
                // ensure uniqueness
                while (in_array($key, $seen)) {
                    $key = substr(bin2hex(random_bytes(12)), 0, 24);
                }
                $seen[] = $key;
                $input['targets'][$i]['url'] = esc_url_raw($url);
                $input['targets'][$i]['key'] = $key;
            }
        }

        // If target mode, sanitize fields
        if ($input['mode'] === 'target') {
            $input['target_key'] = trim($input['target_key'] ?? '');
            $input['translation_lang'] = in_array($input['translation_lang'] ?? '', array('fr', 'es', 'hi')) ? $input['translation_lang'] : 'fr';
            $input['chatgpt_key'] = trim($input['chatgpt_key'] ?? '');
            $input['allowed_host'] = trim($input['allowed_host'] ?? '');
        }

        return $input;
    }

     public function activate()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_role varchar(10) NOT NULL,
            action varchar(20) NOT NULL,
            host_post_id bigint(20) NULL,
            target_post_id bigint(20) NULL,
            target_url varchar(255) NULL,
            status varchar(20) NOT NULL,
            message text NULL,
            time_taken float NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function get_options()
    {
        $defaults = array(
            'mode' => 'host',
            'targets' => array(), // each: ['url' => '', 'key' => '']
            'target_key' => '',
            'translation_lang' => 'fr',
            'allowed_host' => '',
            'chatgpt_key' => '',
        );
        $opts = get_option(self::OPTION_KEY, array());
        return wp_parse_args($opts, $defaults);
    }
}
