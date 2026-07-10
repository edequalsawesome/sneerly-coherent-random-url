<?php
/**
 * Plugin Name: Sneerly Coherent Random Post
 * Description: Redirects URLs with ?random parameter to a truly random post and adds a Gutenberg block for random post buttons
 * Version: 2.1.1
 * Author: eD! Thomas
 * Author URI: https://edequalsaweso.me
 * Text Domain: sneer-campaign-random
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Define plugin constants
define('SNEERLY_COHERENT_RANDOM_VERSION', '2.0');
define('SNEERLY_COHERENT_RANDOM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNEERLY_COHERENT_RANDOM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * The main plugin class
 */
class Sneerly_Coherent_Random_Post {

	/**
	 * Maximum number of posts to remember (to avoid repetition)
	 * @var int
	 */
	private $history_limit = 10;
	
	/**
	 * Transient name for storing post history
	 * @var string
	 */
	private $transient_name = 'sneerly_coherent_random_history';
	
	/**
	 * Default post types to include in randomization
	 * @var array
	 */
	private $default_post_types = array('post');
	
	/**
	 * Get user-specific transient name
	 * 
	 * @return string User-specific transient name
	 */
	private function get_user_transient_name() {
		// Get user ID or IP address for non-logged in users
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			// For non-logged in users, use hashed IP address
			$ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
			$user_identifier = md5($ip_address);
		} else {
			$user_identifier = $user_id;
		}
		
		return $this->transient_name . '_' . $user_identifier;
	}
	
	/**
	 * Initialize the plugin
	 */
	public function __construct() {
		// Hook into WordPress initialization for redirection
		add_action('init', array($this, 'check_for_random_parameter'));
		
		// Add settings page
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		
		// Register and enqueue block assets
		add_action('init', array($this, 'register_block'));
		add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
		
		// Add filters for managing caching plugins - move to an earlier hook
		add_action('muplugins_loaded', array($this, 'manage_cache_plugins'), 0);
	}
	
	/**
	 * Manage compatibility with caching plugins
	 * @return void
	 */
	public function manage_cache_plugins() {
		// Skip caching for requests with 'random' or 'nocache' parameter
		if (isset($_GET['random']) || isset($_GET['nocache'])) {
			// Define constant to prevent page caching
			if (!defined('DONOTCACHEPAGE')) {
				define('DONOTCACHEPAGE', true);
			}
			
			// Define constants to prevent object caching
			if (!defined('DONOTCACHEOBJECT')) {
				define('DONOTCACHEOBJECT', true);
			}
			
			// Define constants to prevent database caching
			if (!defined('DONOTCACHEDB')) {
				define('DONOTCACHEDB', true);
			}
			
			// WP Super Cache
			if (function_exists('wp_cache_is_enabled')) {
				define('DONOTCACHEPAGE', true);
			}
			
			// W3 Total Cache
			if (function_exists('w3tc_pgcache_flush')) {
				define('DONOTCACHEPAGE', true);
			}
			
			// WP Rocket
			if (function_exists('rocket_define_donotcachepage')) {
				define('DONOTCACHEPAGE', true);
				add_filter('rocket_override_donotcachepage', '__return_false');
			}
			
			// LiteSpeed Cache
			if (class_exists('LiteSpeed_Cache')) {
				do_action('litespeed_control_set_nocache', 'nocache for random parameter');
			}
			
			// WP Fastest Cache
			if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
				define('DONOTCACHEPAGE', true);
			}
			
			// Add cache-busting headers for all requests
			add_action('send_headers', array($this, 'add_cache_busting_headers'), 0);
		}
	}
	
	/**
	 * Add cache-busting headers for all responses
	 *
	 * @return void
	 */
	public function add_cache_busting_headers() {
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
		header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
	}

	/**
	 * Check if the URL contains the random parameter and redirect if it does
	 * @return void
	 */
	public function check_for_random_parameter() {
		// Check if the random parameter exists in the URL
		if (isset($_GET['random'])) {
			// Create a more robust cache-busting value
			// Use the provided cb value if available, otherwise generate a new one
			$unique_cache_buster = isset($_GET['cb']) ? 
				sanitize_text_field($_GET['cb']) . '_' . mt_rand(1000, 9999) : 
				time() . '_' . mt_rand(1000, 9999);
			
			// Get random post
			$random_post = $this->get_random_post();
			
			// If we found a post, redirect to it
			if ($random_post) {
				$redirect_url = get_permalink($random_post->ID);
				
				// Add more unique nocache parameter
				$redirect_url = add_query_arg('nocache', $unique_cache_buster, $redirect_url);
				
				// Set stronger cache control headers
				header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
				header('Cache-Control: post-check=0, pre-check=0', false);
				header('Pragma: no-cache');
				header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
				
				wp_redirect($redirect_url);
				exit;
			}
		}
	}

	/**
	 * Get a random post from the database
	 * @return \WP_Post|null Post object if successful, null otherwise
	 */
	private function get_random_post() {
		global $wpdb;
		
		// Get enabled post types
		$enabled_post_types = get_option('sneerly_coherent_post_types', $this->default_post_types);
		
		// If no post types are enabled, fallback to 'post'
		if (empty($enabled_post_types)) {
			$enabled_post_types = array('post');
		}
		
		// Format post types for SQL query
		$post_types_sql = "'" . implode("','", array_map('esc_sql', $enabled_post_types)) . "'";
		
		// Add cache-busting timestamp to the query
		$unique_timestamp = time() . mt_rand(10000, 99999);
		
		// Get the total count of published posts of enabled types
		$post_count = $wpdb->get_var("
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type IN ({$post_types_sql})
			AND post_status = 'publish'
			/* nocache: {$unique_timestamp} */
		");
		
		// Convert to integer and check if we have posts
		$post_count = (int)$post_count;
		if ($post_count <= 0) {
			return null;
		}
		
		// Get history of recently shown posts
		$post_history = $this->get_post_history();
		
		// Reset history if we've shown all posts
		if (count($post_history) >= $post_count) {
			$post_history = array();
			$this->update_post_history($post_history);
		}
		
		// Try up to 10 times to find a post not in history
		$attempts = 0;
		$max_attempts = 10;
		$post = null;
		
		while ($attempts < $max_attempts) {
			// Generate a truly random offset
			$random_offset = $post_count > 1 ? mt_rand(0, $post_count - 1) : 0;
			
			// Query to get a single random post excluding history
			$args = array(
				'post_type'      => $enabled_post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'offset'         => $random_offset,
				'no_found_rows'  => true,
				'cache_results'  => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'        => 'rand',
			);
			
			// Exclude posts from history
			if (!empty($post_history)) {
				$args['post__not_in'] = $post_history;
			}
			
			// Add a timestamp to prevent caching
			add_filter('posts_where', array($this, 'add_random_parameter_to_query'), 10, 1);
			
			// Run the query
			$random_query = new WP_Query($args);
			
			// Remove our filter
			remove_filter('posts_where', array($this, 'add_random_parameter_to_query'));
			
			// If we found a post not in history, use it
			if ($random_query->have_posts()) {
				$post = $random_query->posts[0];
				
				// Update history
				$this->add_to_history($post->ID);
				break;
			}
			
			$attempts++;
		}
		
		// If we couldn't find a non-repeated post after max attempts,
		// just get any random post as a fallback
		if ($post === null) {
			$random_offset = $post_count > 1 ? mt_rand(0, $post_count - 1) : 0;
			$args = array(
				'post_type'      => $enabled_post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'offset'         => $random_offset,
				'no_found_rows'  => true,
				'cache_results'  => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'        => 'rand',
			);
			
			add_filter('posts_where', array($this, 'add_random_parameter_to_query'), 10, 1);
			$random_query = new WP_Query($args);
			remove_filter('posts_where', array($this, 'add_random_parameter_to_query'));
			
			if ($random_query->have_posts()) {
				$post = $random_query->posts[0];
				$this->add_to_history($post->ID);
			}
		}
		
		return $post;
	}
	
	/**
	 * Add a random parameter to the query to prevent caching
	 *
	 * @param string $where The WHERE clause of the query
	 * @return string Modified WHERE clause
	 */
	public function add_random_parameter_to_query($where) {
		// Create a more complex random value with microsecond precision and session ID
		$random_val = mt_rand(1000, 9999999) . '_' . microtime(true) . '_' . session_id();
		$random_hash = md5($random_val);
		
		// Add a comment with the random hash to the SQL to make each query unique
		return $where . " /* random_query_nocache: {$random_hash} */ ";
	}
	
	/**
	 * Get the history of recently shown posts
	 *
	 * @return array Array of post IDs
	 */
	private function get_post_history() {
		$history = get_transient($this->get_user_transient_name());
		return (is_array($history)) ? $history : array();
	}
	
	/**
	 * Add a post ID to the history
	 *
	 * @param int $post_id The post ID to add to history
	 * @return void
	 */
	private function add_to_history($post_id) {
		$history = $this->get_post_history();
		
		// Add new post ID to the beginning of the array
		array_unshift($history, (int)$post_id);
		
		// Get history limit (default 10 if not set)
		$history_limit = (int)get_option('sneerly_coherent_history_limit', $this->history_limit);
		
		// Trim the array to the history limit
		if (count($history) > $history_limit) {
			$history = array_slice($history, 0, $history_limit);
		}
		
		$this->update_post_history($history);
	}
	
	/**
	 * Update the post history transient
	 *
	 * @param array $history Array of post IDs
	 * @return void
	 */
	private function update_post_history($history) {
		// Store for 1 hour (3600 seconds) instead of 24 hours
		set_transient($this->get_user_transient_name(), $history, 3600);
	}
	
	/**
	 * Add admin menu item under Settings
	 * @return void
	 */
	public function add_admin_menu() {
		add_options_page(
			'Sneerly Coherent Random Post Settings',
			'Sneerly Coherent Random',
			'manage_options',
			'sneerly-coherent-random',
			array($this, 'render_settings_page')
		);
	}
	
	/**
	 * Register plugin settings
	 * @return void
	 */
	public function register_settings() {
		// History limit setting
		register_setting('sneerly_coherent_random', 'sneerly_coherent_history_limit', array(
			'type' => 'integer',
			'description' => 'Number of posts to remember and avoid repeating',
			'sanitize_callback' => 'absint',
			'default' => $this->history_limit,
		));
		
		// Post types setting
		register_setting('sneerly_coherent_random', 'sneerly_coherent_post_types', array(
			'type' => 'array',
			'description' => 'Post types to include in randomization',
			'sanitize_callback' => array($this, 'sanitize_post_types'),
			'default' => $this->default_post_types,
		));
	}
	
	/**
	 * Sanitize post types array
	 * 
	 * @param array $post_types Array of post types
	 * @return array Sanitized array of post types
	 */
	public function sanitize_post_types($post_types) {
		if (!is_array($post_types)) {
			return array('post');
		}
		
		// Remove any non-existing post types
		$available_post_types = $this->get_available_post_types();
		$available_post_type_names = array_keys($available_post_types);
		
		return array_intersect($post_types, $available_post_type_names);
	}
	
	/**
	 * Get all available post types for settings
	 * 
	 * @return array Array of post types with labels
	 */
	private function get_available_post_types() {
		$post_types = get_post_types(array(
			'public' => true,
		), 'objects');
		
		$available_types = array();
		
		foreach ($post_types as $post_type) {
			// Skip attachments and other non-content post types
			if (in_array($post_type->name, array('attachment', 'revision', 'nav_menu_item'))) {
				continue;
			}
			
			$available_types[$post_type->name] = $post_type->label;
		}
		
		return $available_types;
	}
	
	/**
	 * Render the settings page
	 * @return void
	 */
	public function render_settings_page() {
		// Get current history limit
		$history_limit = (int)get_option('sneerly_coherent_history_limit', $this->history_limit);
		
		// Get currently enabled post types
		$enabled_post_types = get_option('sneerly_coherent_post_types', $this->default_post_types);
		
		// Get available post types
		$available_post_types = $this->get_available_post_types();
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields('sneerly_coherent_random'); ?>
				
				<h2><?php _e('General Settings', 'sneerly-coherent-random'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sneerly_coherent_history_limit">History Size</label>
						</th>
						<td>
							<input type="number" id="sneerly_coherent_history_limit" 
								   name="sneerly_coherent_history_limit" 
								   value="<?php echo esc_attr($history_limit); ?>" 
								   min="1" max="100" />
							<p class="description">
								Number of posts to remember and avoid repeating. Higher values prevent more repetition.
							</p>
						</td>
					</tr>
				</table>
				
				<h2><?php _e('Post Types', 'sneerly-coherent-random'); ?></h2>
				<p><?php _e('Select which post types to include in random selection:', 'sneerly-coherent-random'); ?></p>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<?php _e('Include Post Types', 'sneerly-coherent-random'); ?>
						</th>
						<td>
							<?php foreach ($available_post_types as $post_type => $label) : ?>
								<label style="display: block; margin-bottom: 8px;">
									<input type="checkbox" 
										   name="sneerly_coherent_post_types[]" 
										   value="<?php echo esc_attr($post_type); ?>"
										   <?php checked(in_array($post_type, $enabled_post_types)); ?> />
									<?php echo esc_html($label); ?>
									<?php if ($post_type === 'post') : ?>
										<em>(<?php _e('Standard Posts', 'sneerly-coherent-random'); ?>)</em>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php _e('The random post selection will only include the checked post types.', 'sneerly-coherent-random'); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>
			
			<h2>Usage</h2>
			<p>There are two ways to use the random post feature:</p>
			
			<h3>1. URL Parameter</h3>
			<p>Add <code>?random</code> to any URL on your site to redirect to a random post.</p>
			<p>Example: <code><?php echo esc_url(site_url('/?random')); ?></code></p>
			
			<h3>2. Gutenberg Block</h3>
			<p>Use the "Random Post Button" block in the editor to add a stylish button that links to a random post.</p>
			<p>Simply search for "random" in the block inserter and customize the button to your liking.</p>
			
			<h2>History</h2>
			<p>Sneerly Coherent Random Post will avoid showing the same post twice until <?php echo esc_html($history_limit); ?> different posts have been shown.</p>
			<?php
			$history = $this->get_post_history();
			if (!empty($history)) {
				echo '<h3>Recently shown posts:</h3>';
				echo '<ol>';
				foreach ($history as $post_id) {
					$title = get_the_title($post_id);
					$permalink = get_permalink($post_id);
					echo '<li><a href="' . esc_url($permalink) . '">' . esc_html($title) . '</a></li>';
				}
				echo '</ol>';
				
				echo '<p><a href="' . esc_url(add_query_arg('clear_history', '1')) . 
					 '" class="button">Clear History</a></p>';
			} else {
				echo '<p>No posts have been shown yet.</p>';
			}
			?>
		</div>
		<?php
	}
	
	/**
	 * Register the Gutenberg block
	 * @return void
	 */
	public function register_block() {
		// Only register if Gutenberg is available
		if (!function_exists('register_block_type')) {
			return;
		}
		
		// Register the block
		register_block_type('sneerly-coherent/random-post-button', array(
			'editor_script' => 'sneerly-coherent-random-button-editor',
			'editor_style'  => 'sneerly-coherent-random-button-editor-style',
			'render_callback' => array($this, 'render_block_callback'),
			'attributes' => array(
				'text' => array(
					'type' => 'string',
					'default' => 'Read a Random Post',
				),
				'backgroundColor' => array(
					'type' => 'string',
				),
				'textColor' => array(
					'type' => 'string',
				),
				'borderRadius' => array(
					'type' => 'number',
					'default' => 4,
				),
				'fontSize' => array(
					'type' => 'string',
					'default' => 'normal',
				),
				'useRandomLink' => array(
					'type' => 'boolean',
					'default' => true,
				),
				'customLink' => array(
					'type' => 'string',
					'default' => '',
				),
				'linkOpensInNewTab' => array(
					'type' => 'boolean',
					'default' => false,
				),
				'buttonWidth' => array(
					'type' => 'string',
					'default' => 'auto',
				),
			),
		));
	}
	
	/**
	 * Enqueue block editor assets
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		// Check if block editor assets exist
		$script_path = SNEERLY_COHERENT_RANDOM_PLUGIN_DIR . 'build/index.js';
		$style_path = SNEERLY_COHERENT_RANDOM_PLUGIN_DIR . 'build/editor.css';
		
		if (!file_exists($script_path)) {
			// If build files don't exist, show admin notice
			add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>';
				echo 'Sneerly Coherent Random Post: Block editor assets not found. Please run <code>npm run build</code> in the plugin directory.';
				echo '</p></div>';
			});
			return;
		}
		
		// Register the script
		wp_register_script(
			'sneerly-coherent-random-button-editor',
			SNEERLY_COHERENT_RANDOM_PLUGIN_URL . 'build/index.js',
			array(
				'wp-blocks',
				'wp-i18n',
				'wp-element',
				'wp-block-editor',
				'wp-components'
			),
			filemtime($script_path),
			true
		);
		
		// Register the style if it exists
		if (file_exists($style_path)) {
			wp_register_style(
				'sneerly-coherent-random-button-editor-style',
				SNEERLY_COHERENT_RANDOM_PLUGIN_URL . 'build/editor.css',
				array(),
				filemtime($style_path)
			);
		}
	}
	
	/**
	 * Block render callback
	 *
	 * @param array $attributes Block attributes
	 * @param string $content Block content
	 * @return string The block content
	 */
	public function render_block_callback($attributes, $content) {
		// Skip processing if not using random link
		if (!isset($attributes['useRandomLink']) || !$attributes['useRandomLink']) {
			return $content;
		}
		
		// Generate a unique timestamp for this page load
		$unique_cache_buster = time() . '_' . mt_rand(1000, 9999);
		
		// Make sure our link is properly formed with cache busting
		$content = preg_replace(
			'/<a(.*)href="[^"]*"(.*)>/',
			'<a$1href="?random&cb=' . $unique_cache_buster . '"$2>',
			$content
		);
		
		return $content;
	}
}

// Initialize the plugin
new Sneerly_Coherent_Random_Post();

/**
 * Activation hook
 */
function sneerly_coherent_random_activate() {
	// Create directory for block assets if it doesn't exist
	$build_dir = plugin_dir_path(__FILE__) . 'build';
	if (!file_exists($build_dir)) {
		wp_mkdir_p($build_dir);
	}
	
	// Add a flag to show setup notice
	add_option('sneerly_coherent_random_show_setup', true);
}
register_activation_hook(__FILE__, 'sneerly_coherent_random_activate');

/**
 * Admin notices for setup
 */
function sneerly_coherent_random_admin_notices() {
	// Check if we need to show setup notice
	if (get_option('sneerly_coherent_random_show_setup', false)) {
		// Only show to admins
		if (!current_user_can('manage_options')) {
			return;
		}
		
		// Check if block assets exist
		$script_path = plugin_dir_path(__FILE__) . 'build/index.js';
		if (!file_exists($script_path)) {
			?>
			<div class="notice notice-info is-dismissible">
				<h3>Sneerly Coherent Random Post - Block Setup</h3>
				<p>To enable the Gutenberg block feature, please run the following commands in the plugin directory:</p>
				<pre style="background: #f6f6f6; padding: 10px; border: 1px solid #ddd;">
cd <?php echo esc_html(plugin_dir_path(__FILE__)); ?>
npm install
npm run build</pre>
				<p>If you don't need the Gutenberg block feature, you can ignore this message.</p>
				<button type="button" class="notice-dismiss">
					<span class="screen-reader-text">Dismiss this notice.</span>
				</button>
			</div>
			<?php
		} else {
			// Block assets exist, no need to show notice anymore
			delete_option('sneerly_coherent_random_show_setup');
		}
	}
}
add_action('admin_notices', 'sneerly_coherent_random_admin_notices');

/**
 * Dismiss setup notice via AJAX
 */
function sneerly_coherent_random_dismiss_setup_notice() {
	if (current_user_can('manage_options')) {
		delete_option('sneerly_coherent_random_show_setup');
	}
	wp_die();
}
add_action('wp_ajax_sneerly_coherent_random_dismiss_setup', 'sneerly_coherent_random_dismiss_setup_notice');