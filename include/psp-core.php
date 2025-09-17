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
        // Host hooks
        add_action('publish_post', array($this, 'handle_post_publish'), 10, 2);
        add_action('post_updated', array($this, 'handle_post_update'), 10, 3);
        // REST for target
        add_action('rest_api_init', array($this, 'register_rest_routes'));
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

    /* ---------------------- Host side ---------------------- */
    public function handle_post_publish($post_ID, $post)
    {
        $this->maybe_push_post($post_ID, $post, 'publish');
    }

    public function handle_post_update($post_ID, $post_after, $post_before)
    {
        // Only handle posts
        if ($post_after->post_type !== 'post') return;
        $this->maybe_push_post($post_ID, $post_after, 'update');
    }

    private function maybe_push_post($post_ID, $post, $action)
    {
        $opts = $this->get_options();
        if ($opts['mode'] !== 'host') return;
        if ($post->post_status !== 'publish' && $action === 'publish') return;

        $targets = is_array($opts['targets']) ? $opts['targets'] : array();
        if (empty($targets)) return;

        $payload = $this->build_post_payload($post_ID);

        foreach ($targets as $t) {
            $target_url = rtrim($t['url'], '/') . '/wp-json/psp/v1/sync';
            $key = $t['key'] ?? '';
            $start = microtime(true);
            $res = $this->send_to_target($target_url, $key, $payload);
            $time = microtime(true) - $start;
            $status = isset($res['success']) && $res['success'] ? 'success' : 'failed';
            $message = isset($res['message']) ? $res['message'] : (is_array($res) ? json_encode($res) : strval($res));
            $this->insert_log('host', $action, $post_ID, $res['target_post_id'] ?? null, $target_url, $status, $message, $time);
            // Save mapping on host when success
            if (!empty($res['success']) && $res['success'] && !empty($res['target_post_id'])) {
                $this->save_mapping($post_ID, $target_url, $res['target_post_id']);
            }
        }
    }

    private function save_mapping($host_post_id, $target_url, $target_post_id)
    {
        $maps = get_option('psp_mappings', array());
        if (!isset($maps[$host_post_id]) || !is_array($maps[$host_post_id])) $maps[$host_post_id] = array();
        $maps[$host_post_id][$target_url] = $target_post_id;
        update_option('psp_mappings', $maps);
    }

    private function build_post_payload($post_ID)
    {
        $post = get_post($post_ID);
        if (!$post) return array();

        // Get categories and tags
        $cats = wp_get_post_categories($post_ID, array('fields' => 'names'));
        $tags = wp_get_post_tags($post_ID, array('fields' => 'names'));

        // Featured image
        $thumb_id = get_post_thumbnail_id($post_ID);
        $thumb_url = $thumb_id ? wp_get_attachment_url($thumb_id) : '';

        return array(
            'host_site' => home_url(),
            'host_post_id' => $post_ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'categories' => $cats,
            'tags' => $tags,
            'featured_image' => $thumb_url,
            'modified' => $post->post_modified,
        );
    }

    private function send_to_target($url, $key, $payload)
    {
        if (empty($key) || empty($url)) {
            return array('success' => false, 'message' => 'Missing key or url');
        }

        $body = wp_json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', $body . '|' . $timestamp, $key);

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-PSP-Key' => $key,
                'X-PSP-Timestamp' => $timestamp,
                'X-PSP-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        );

        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) {
            return array('success' => false, 'message' => $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        return array_merge(array('http_code' => $code, 'success' => $code >= 200 && $code < 300), is_array($data) ? $data : array('message' => $body));
    }


    /* ---------------------- REST / Target side ---------------------- */
    public function register_rest_routes()
    {
        register_rest_route('psp/v1', '/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_sync_post'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_sync_post(
        WP_REST_Request $request
    ) {
        $start = microtime(true);
        $opts = $this->get_options();
        if ($opts['mode'] !== 'target') {
            return new WP_REST_Response(array('success' => false, 'message' => 'Site not in target mode'), 400);
        }

        $provided_key = $request->get_header('x-psp-key');
        $timestamp = $request->get_header('x-psp-timestamp');
        $signature = $request->get_header('x-psp-signature');
        $body = $request->get_body();

        if (empty($provided_key) || empty($timestamp) || empty($signature)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Missing auth headers'), 401);
        }

        // Validate key matches configured key
        $local_key = $opts['target_key'];
        if ($provided_key !== $local_key) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid key'), 403);
        }

        // Timestamp freshness (5 minutes)
        if (abs(time() - intval($timestamp)) > 300) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Stale timestamp'), 403);
        }

        $calc = hash_hmac('sha256', $body . '|' . $timestamp, $local_key);
        if (!hash_equals($calc, $signature)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid signature'), 403);
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['host_post_id'])) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid payload'), 400);
        }

        // Domain binding: if configured, ensure host matches
        $allowed_host = $opts['allowed_host'] ?? '';
        if (!empty($allowed_host)) {
            $incoming_host = $data['host_site'] ?? '';
            $allowed_host_host = parse_url(rtrim($allowed_host, '/'), PHP_URL_HOST) ?: rtrim($allowed_host, '/');
            $incoming_host_host = parse_url(rtrim($incoming_host, '/'), PHP_URL_HOST) ?: rtrim($incoming_host, '/');
            if (strtolower($allowed_host_host) !== strtolower($incoming_host_host)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Host mismatch'), 403);
            }
        }

        // Only sync posts
        $host_post_id = intval($data['host_post_id']);

        try {
            $lang = $opts['translation_lang'] ?? 'fr';
            $chatgpt_key = $opts['chatgpt_key'] ?? '';

            $translated_title = $this->translate_html_chunks($data['title'] ?? '', $lang, $chatgpt_key);
            $translated_content = $this->translate_html_chunks($data['content'] ?? '', $lang, $chatgpt_key);
            $translated_excerpt = $this->translate_html_chunks($data['excerpt'] ?? '', $lang, $chatgpt_key);

            // Find existing mapping
            $existing = get_posts(array(
                'post_type' => 'post',
                'meta_key' => '_psp_host_post_id',
                'meta_value' => $host_post_id,
                'posts_per_page' => 1,
            ));

            $post_arr = array(
                'post_title' => wp_strip_all_tags($translated_title),
                'post_content' => $translated_content,
                'post_excerpt' => wp_strip_all_tags($translated_excerpt),
                'post_status' => 'publish',
                'post_type' => 'post',
            );

            if (!empty($existing)) {
                $post_arr['ID'] = $existing[0]->ID;
                $target_post_id = wp_update_post($post_arr, true);
            } else {
                $target_post_id = wp_insert_post($post_arr, true);
            }

            if (is_wp_error($target_post_id)) {
                throw new Exception('Post insert/update error: ' . $target_post_id->get_error_message());
            }

            // Set meta mapping
            update_post_meta($target_post_id, '_psp_host_post_id', $host_post_id);

            // Categories
            if (!empty($data['categories']) && is_array($data['categories'])) {
                $cat_ids = array();
                foreach ($data['categories'] as $cname) {
                    $term = get_term_by('name', $cname, 'category');
                    if (!$term) {
                        $term = wp_insert_term($cname, 'category');
                        if (!is_wp_error($term) && isset($term['term_id'])) $cat_ids[] = intval($term['term_id']);
                    } else {
                        $cat_ids[] = intval($term->term_id);
                    }
                }
                if (!empty($cat_ids)) wp_set_post_categories($target_post_id, $cat_ids);
            }

            // Tags
            if (!empty($data['tags']) && is_array($data['tags'])) {
                wp_set_post_tags($target_post_id, $data['tags']);
            }

            // Featured image
            if (!empty($data['featured_image'])) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $img = media_sideload_image($data['featured_image'], $target_post_id, null, 'id');
                if (!is_wp_error($img) && $img) {
                    set_post_thumbnail($target_post_id, $img);
                }
            }

            $time_taken = microtime(true) - $start;
            $this->insert_log('target', 'sync', $host_post_id, $target_post_id, $_SERVER['REMOTE_ADDR'] ?? '', 'success', 'Synced', $time_taken);

            return new WP_REST_Response(array('success' => true, 'target_post_id' => $target_post_id), 200);
        } catch (Exception $e) {
            $time_taken = microtime(true) - $start;
            $this->insert_log('target', 'sync', $host_post_id, null, $_SERVER['REMOTE_ADDR'] ?? '', 'failed', $e->getMessage(), $time_taken);
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
    }

    private function translate_html_chunks($html, $lang, $chatgpt_key)
    {
        // If no key provided, skip translation and return original
        if (empty($chatgpt_key)) return $html;

        // Split into blocks by common block-level boundaries while keeping delimiters
        $pieces = preg_split('/(<\/p>|<br\s*\/?>|<\/div>|<\/li>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        // Recombine to chunks approx 1800-2300 chars
        $chunks = array();
        $current = '';
        foreach ($pieces as $part) {
            $current .= $part;
            if (mb_strlen($current) > 2000) {
                $chunks[] = $current;
                $current = '';
            }
        }
        if (strlen($current) > 0) $chunks[] = $current;

        $translated = '';
        foreach ($chunks as $chunk) {
            $t = $this->translate_via_chatgpt($chunk, $lang, $chatgpt_key);
            // Fallback to original chunk if translation failed
            if ($t === false) $t = $chunk;
            $translated .= $t;
        }
        return $translated;
    }

    private function translate_via_chatgpt($html_fragment, $lang, $chatgpt_key)
    {
        $system = "You are an expert translator. Translate the user's HTML fragment into $lang while preserving all HTML tags, attributes, and structure. Do not add extra text or explanations — only return the translated HTML fragment. Keep URLs, code, and inline HTML intact.";

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $html_fragment),
        );

        $body = array(
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'temperature' => 0.0,
        );

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $chatgpt_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 30,
        ));

        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        $b = wp_remote_retrieve_body($resp);
        $data = json_decode($b, true);
        if ($code >= 200 && $code < 300 && isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        return false;
    }

   

    /* ---------------------- Logging ---------------------- */
    private function insert_log($site_role, $action, $host_post_id, $target_post_id, $target_url, $status, $message, $time_taken)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $wpdb->insert($table, array(
            'site_role' => $site_role,
            'action' => $action,
            'host_post_id' => $host_post_id,
            'target_post_id' => $target_post_id,
            'target_url' => $target_url,
            'status' => $status,
            'message' => $message,
            'time_taken' => $time_taken,
            'created_at' => current_time('mysql'),
        ));
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
