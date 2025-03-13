<?php
/*
* Plugin Name: Simple Auto-Poster for Bluesky
* Description: Automatically posts to Bluesky when a WordPress post is published, including featured images
* Version: 1.3
* Author: Emma Blackwell
* Author URI: https://themotorsport.net/
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: simple-auto-poster-for-bluesky
* Domain Path: /languages
*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class Simple_Bluesky_Poster {
    private $identifier;
    private $password;
    private $log_file;

    public function __construct() {
        add_action('publish_post', [$this, 'handle_post'], 10, 2);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('plugins_loaded', [$this, 'load_plugin_textdomain']);
        // Set up logging
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/bluesky_poster_log.txt';
        $this->log(__("Plugin initialized", 'simple-auto-poster-for-bluesky'));
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain('simple-auto-poster-for-bluesky', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    private function log($message) {
    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();

    if ($wp_filesystem->is_writable(dirname($this->log_file))) {
        $timestamp = gmdate('[Y-m-d H:i:s]');
        $log_message = $timestamp . ' ' . sanitize_text_field($message) . "\n";
        $existing_content = $wp_filesystem->get_contents($this->log_file);
        $updated_content = $existing_content . $log_message;
        $wp_filesystem->put_contents($this->log_file, $updated_content, FS_CHMOD_FILE);
        }
    }

    public function handle_post($post_ID, $post, $update = null) {
        $this->log(sprintf(
            /* translators: %d: Post ID */
            __('handle_post called for post ID: %d', 'simple-auto-poster-for-bluesky'),
            $post_ID
        ));
    
        // Clear 'bluesky_shared' meta if the post is cloned
        if (get_post_meta($post_ID, '_dp_original', true)) {
            $this->log(__('Cloned post detected, clearing bluesky_shared meta key', 'simple-auto-poster-for-bluesky'));
            delete_post_meta($post_ID, 'bluesky_shared');
        }
		
        // Check if this is a new post or an update
        if ($update && get_post_field('post_date', $post_ID) != get_post_field('post_modified', $post_ID)) {
            $this->log(__("This is a post update, not initial publish - skipping", 'simple-auto-poster-for-bluesky'));
            return;
        }
        
        if (get_post_status($post_ID) === 'publish' && get_post_type($post_ID) === 'post') {
            $this->log(__("Post is published and of type 'post', calling post_to_bluesky", 'simple-auto-poster-for-bluesky'));
            $this->post_to_bluesky($post_ID, $post);
        } else {
            $this->log(__("Post is not published or not of type 'post', skipping", 'simple-auto-poster-for-bluesky'));
        }
    }

    public function post_to_bluesky($post_ID, $post) {
        $this->log(sprintf(
            /* translators: %d: Post ID */
            __('post_to_bluesky called for post ID: %d', 'simple-auto-poster-for-bluesky'),
            $post_ID
        ));

        if (get_post_meta($post_ID, 'bluesky_shared', true)) {
            $this->log(__("Post already shared to Bluesky, skipping", 'simple-auto-poster-for-bluesky'));
            return;
        }

        $allowed_post_types = apply_filters('bluesky_allowed_post_types', ['post']);
        if (!in_array($post->post_type, $allowed_post_types)) {
            $this->log(sprintf(
                /* translators: %s: Post type */
                __("Post type not allowed: %s", 'simple-auto-poster-for-bluesky'),
                $post->post_type
            ));
            return;
        }
    
        if ($post->post_type !== 'post') {
            $this->log(__("Post type is not 'post', skipping", 'simple-auto-poster-for-bluesky'));
            return;
        }

        $this->identifier = get_option('bluesky_identifier');
        $this->password = get_option('bluesky_password');

        if (empty($this->identifier) || empty($this->password)) {
            $this->log(__("Bluesky credentials not set", 'simple-auto-poster-for-bluesky'));
            return;
        }

        $auth_url = 'https://bsky.social/xrpc/com.atproto.server.createSession';
        $post_url = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';

        $this->log(__("Authenticating with Bluesky", 'simple-auto-poster-for-bluesky'));
        $auth_response = wp_remote_post($auth_url, [
            'body' => wp_json_encode([
                'identifier' => $this->identifier,
                'password' => $this->password
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($auth_response)) {
            $this->log(sprintf(
                /* translators: %s: Error message */
                __("Bluesky authentication failed: %s", 'simple-auto-poster-for-bluesky'),
                $auth_response->get_error_message()
            ));
            return;
        }

        if (wp_remote_retrieve_response_code($auth_response) != 200) {
            $this->log(sprintf(
                /* translators: %d: HTTP status code */
                __("Bluesky authentication failed with status code: %d", 'simple-auto-poster-for-bluesky'),
                wp_remote_retrieve_response_code($auth_response)
            ));
            return;
        }

        $session_data = json_decode(wp_remote_retrieve_body($auth_response), true);
        $access_token = $session_data['accessJwt'];
        $did = $session_data['did'];

        $this->log(sprintf(
            /* translators: %s: DID */
            __("Authentication successful, DID: %s", 'simple-auto-poster-for-bluesky'),
            $did
        ));

        $permalink = get_permalink($post_ID);
        
        // Fetch OpenGraph data
		$og_data = $this->get_og_data($permalink);
		if (isset ( $og_data['published_time'] ) ){ $createdAt = $og_data['published_time']; } // timestamp scraped from post at $permalink
		else { $createdAt = gmdate('c'); } // default timestamp
		
		$post_data = [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'record' => [
				'text' => '', // Empty text field
				'createdAt' => $createdAt
			]
		];
    
        $image_blob = '';
        if (isset($image_path) && file_exists($image_path)) {
            $image_content = wp_remote_get($image_path);
            $image_mime_type = mime_content_type($image_path);
    
            if (strlen($image_content) <= 1000000) {
                $upload_response = wp_remote_post('https://bsky.social/xrpc/com.atproto.repo.uploadBlob', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => $image_mime_type,
                    ],
                    'body' => $image_content,
                ]);
    
                if (!is_wp_error($upload_response)) {
                    $upload_body = json_decode(wp_remote_retrieve_body($upload_response), true);
                    $image_blob = $upload_body['blob'] ?? '';
                }
            }
    
            if (isset($temp_image) && $temp_image && !isset($use_placeholder)) {
                wp_delete_file($temp_image);
            }
        }

        $external_embed = [
            '$type' => 'app.bsky.embed.external',
            'external' => [
                'uri' => $permalink,
                'title' => html_entity_decode($og_data['title'] ?? $post->post_title, ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode($og_data['description'] ?? wp_trim_words($post->post_content, 30, '...'), ENT_QUOTES, 'UTF-8'),
                'thumb' => $image_blob,
            ]
        ];

        $image_url = $og_data['image'] ?? get_the_post_thumbnail_url($post_ID, 'full');
        if ($image_url) {
            $this->log(sprintf(
                /* translators: %s: Image URL */
                __("Attempting to upload image: %s", 'simple-auto-poster-for-bluesky'),
                $image_url
            ));
            $uploaded_blob = $this->upload_image_to_bluesky($image_url, $access_token);
            if ($uploaded_blob) {
                $external_embed['external']['thumb'] = $uploaded_blob;
                $this->log(__("Image uploaded successfully", 'simple-auto-poster-for-bluesky'));
            } else {
                $this->log(__("Failed to upload image", 'simple-auto-poster-for-bluesky'));
            }
        } else {
            $this->log(__("No image URL found", 'simple-auto-poster-for-bluesky'));
        }

        $post_data['record']['embed'] = $external_embed;

        $this->log(sprintf(
            /* translators: %s: Post data */
            __("Posting to Bluesky with data: %s", 'simple-auto-poster-for-bluesky'),
            wp_json_encode($post_data)
        ));
        $post_response = wp_remote_post($post_url, [
            'body' => wp_json_encode($post_data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer $access_token"
            ]
        ]);

        if (is_wp_error($post_response)) {
            $this->log(sprintf(
                /* translators: %s: Error message */
                __("Failed to post to Bluesky: %s", 'simple-auto-poster-for-bluesky'),
                $post_response->get_error_message()
            ));
        } elseif (wp_remote_retrieve_response_code($post_response) != 200) {
            $this->log(sprintf(
                /* translators: %1$d: HTTP status code, %2$s: Response body */
                __('Failed to post to Bluesky. Status code: %1$d, Body: %2$s', 'simple-auto-poster-for-bluesky'),
                wp_remote_retrieve_response_code($post_response),
                wp_remote_retrieve_body($post_response)
            ));
        } else {
            $response_body = json_decode(wp_remote_retrieve_body($post_response), true);
            $this->log(sprintf(
                /* translators: %s: Response body */
                __("Successfully posted to Bluesky. Response: %s", 'simple-auto-poster-for-bluesky'),
                wp_json_encode($response_body)
            ));
            if (wp_remote_retrieve_response_code($post_response) === 200) {
            update_post_meta($post_ID, 'bluesky_shared', true);
            $this->log(sprintf(
                /* translators: %s: Response body */
                __("Successfully posted to Bluesky. Response: %s", 'simple-auto-poster-for-bluesky'),
                wp_json_encode($response_body)
            ));
        }
        
        }
    }

	private function get_og_data($url) {
		$response = wp_remote_get($url);
		$html = wp_remote_retrieve_body($response);
		
		$og_data = [];
		if (preg_match('/<meta property="og:title" content="(.*?)"/', $html, $match)) {
			$og_data['title'] = $match[1];
		}
		if (preg_match('/<meta property="og:description" content="(.*?)"/', $html, $match)) {
			$og_data['description'] = $match[1];
		}
		if (preg_match('/<meta property="og:image" content="(.*?)"/', $html, $match)) {
			$og_data['image'] = $match[1];
		}
		if (preg_match('/<meta property="article:published_time" content="(.*?)"/', $html, $match)) {
			$og_data['published_time'] = $match[1];
		}
		
		return $og_data;
	}

    private function upload_image_to_bluesky($image_url, $access_token) {
        $upload_url = 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob';
        
        $image_data = wp_remote_get($image_url);
        if (is_wp_error($image_data)) {
            $this->log(sprintf(
                /* translators: %s: Error message */
                __("Failed to fetch image: %s", 'simple-auto-poster-for-bluesky'),
                $image_data->get_error_message()
            ));
            return null;
        }
        
        $image_content = wp_remote_retrieve_body($image_data);
        $image_mime = wp_remote_retrieve_header($image_data, 'content-type');

        $upload_response = wp_remote_post($upload_url, [
            'body' => $image_content,
            'headers' => [
                'Content-Type' => $image_mime,
                'Authorization' => "Bearer $access_token"
            ]
        ]);

        if (is_wp_error($upload_response) || wp_remote_retrieve_response_code($upload_response) != 200) {
            $this->log(sprintf(
                /* translators: %1$d: HTTP status code, %2$s: Response body */
                __('Image upload failed: %1$d, %2$s', 'simple-auto-poster-for-bluesky'),
                wp_remote_retrieve_response_code($upload_response),
                wp_remote_retrieve_body($upload_response)
            ));
            return null;
        }

        $blob_data = json_decode(wp_remote_retrieve_body($upload_response), true);
        return $blob_data['blob'] ?? null;
    }

    public function add_settings_page() {
        add_options_page(
            __('Bluesky Settings', 'simple-auto-poster-for-bluesky'),
            __('Bluesky Auto-Poster', 'simple-auto-poster-for-bluesky'),
            'manage_options',
            'bluesky-settings',
            [$this, 'render_settings_page']
        );
    }
	
	public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'simple-auto-poster-for-bluesky'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('bluesky_settings');
        do_settings_sections('bluesky-settings');
        submit_button();
        echo '</form>';
        
        echo '<div style="margin-top: 30px; padding: 20px; background-color: #f0f0f0; border-radius: 5px;">';
        echo '<h3>' . esc_html__('Support the Developer', 'simple-auto-poster-for-bluesky') . '</h3>';
        echo '<p>' . esc_html__('This plugin is totally free for you. If you found it useful, please consider a donation for the developer ❤️', 'simple-auto-poster-for-bluesky') . '</p>';
        echo '<a rel="nofollow noopener noreferrer" href="https://www.paypal.com/donate/?hosted_button_id=MM2JAKMWX5QVE" target="_blank" class="button button-primary">' . esc_html__('Donate', 'simple-auto-poster-for-bluesky') . '</a>';
        echo '</div>';
        
        echo '</div>';
    }

    public function register_settings() {
        register_setting('bluesky_settings', 'bluesky_identifier');
        register_setting('bluesky_settings', 'bluesky_password');

        add_settings_section(
            'bluesky_settings_section',
            __('Bluesky Credentials', 'simple-auto-poster-for-bluesky'),
            null,
            'bluesky-settings'
        );

        add_settings_field(
            'bluesky_identifier',
            __('Bluesky Identifier', 'simple-auto-poster-for-bluesky'),
            [$this, 'render_identifier_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );
        add_settings_field(
            'bluesky_password',
            __('Bluesky App Password', 'simple-auto-poster-for-bluesky'),
            [$this, 'render_password_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );
    }

    public function render_identifier_field() {
        echo '<input type="text" name="bluesky_identifier" value="' . esc_attr(get_option('bluesky_identifier')) . '" />';
    }

    public function render_password_field() {
        echo '<input type="password" name="bluesky_password" value="' . esc_attr(get_option('bluesky_password')) . '" />';
    }
}

new Simple_Bluesky_Poster();