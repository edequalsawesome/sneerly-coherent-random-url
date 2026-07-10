<?php
/**
 * Plugin Name: Sneerly Coherent Random Post
 * Description: Redirects URLs with ?random parameter to a truly random post and adds a Gutenberg block for random post buttons
 * Version: 2026.07.001
 * Author: eD! Thomas
 * Author URI: https://edequalsaweso.me
 * Text Domain: sneer-campaign-random
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Define plugin constants
define('SNEERLY_COHERENT_RANDOM_VERSION', '2026.07.001');
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
		
		// Tell caching layers to skip the ?random redirect request.
		// Note: muplugins_loaded has already fired by the time a regular
		// plugin loads, so that hook never ran — plugins_loaded does.
		add_action('plugins_loaded', array($this, 'manage_cache_plugins'), 0);
	}

	/**
	 * Manage compatibility with caching plugins
	 *
	 * Only the ?random redirect request itself needs to bypass caches;
	 * the destination post page is a normal permalink and stays cacheable.
	 *
	 * @return void
	 */
	public function manage_cache_plugins() {
		if (!isset($_GET['random'])) {
			return;
		}

		// Standard constants respected by WP Super Cache, W3TC, WP Rocket,
		// WP Fastest Cache, and most other caching plugins.
		if (!defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}
		if (!defined('DONOTCACHEOBJECT')) {
			define('DONOTCACHEOBJECT', true);
		}
		if (!defined('DONOTCACHEDB')) {
			define('DONOTCACHEDB', true);
		}

		// LiteSpeed Cache uses an action instead of the constants
		// (no-op when LiteSpeed isn't installed).
		do_action('litespeed_control_set_nocache', 'random post redirect');

		// Send no-cache headers on this request.
		add_action('send_headers', 'nocache_headers', 0);
	}

	/**
	 * Check if the URL contains the random parameter and redirect if it does
	 * @return void
	 */
	public function check_for_random_parameter() {
		// Check if the random parameter exists in the URL
		if (!isset($_GET['random'])) {
			return;
		}

		// Only redirect normal frontend requests — never admin, AJAX, or cron.
		if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return;
		}

		// Create a cache-busting value for the destination URL.
		// Use the provided cb value if available, otherwise generate a new one.
		$unique_cache_buster = (isset($_GET['cb']) && is_string($_GET['cb'])) ?
			sanitize_text_field(wp_unslash($_GET['cb'])) . '_' . mt_rand(1000, 9999) :
			time() . '_' . mt_rand(1000, 9999);

		// Get random post
		$random_post = $this->get_random_post();

		// If we found a post, redirect to it
		if ($random_post) {
			$redirect_url = add_query_arg('nocache', $unique_cache_buster, get_permalink($random_post->ID));

			nocache_headers();
			wp_safe_redirect($redirect_url);
			exit;
		}
	}

	/**
	 * Get a random post from the database
	 * @return \WP_Post|null Post object if successful, null otherwise
	 */
	private function get_random_post() {
		// Get enabled post types
		$enabled_post_types = get_option('sneerly_coherent_post_types', $this->default_post_types);
		if (!is_array($enabled_post_types) || empty($enabled_post_types)) {
			$enabled_post_types = array('post');
		}

		// Drop post types that are no longer registered (e.g. a CPT plugin
		// was deactivated after being enabled here) so we never pick a post
		// WordPress can no longer route to.
		$enabled_post_types = array_values(array_intersect(
			$enabled_post_types,
			array_keys($this->get_available_post_types())
		));
		if (empty($enabled_post_types)) {
			$enabled_post_types = array('post');
		}

		// Count posts eligible right now (published, enabled type, not in
		// recent history) so the random offset always lands on a real row.
		$post_history = $this->get_post_history();
		$eligible_count = $this->count_eligible_posts($enabled_post_types, $post_history);

		// Every eligible post has been shown recently — reset history.
		if ($eligible_count <= 0 && !empty($post_history)) {
			$post_history = array();
			$this->update_post_history($post_history);
			$eligible_count = $this->count_eligible_posts($enabled_post_types, $post_history);
		}

		if ($eligible_count <= 0) {
			return null;
		}

		// One query: a uniformly random offset into the eligible set.
		// Deterministic ordering + random offset replaces ORDER BY RAND(),
		// which forced a full filesort of every matching row per request.
		$args = array(
			'post_type'      => $enabled_post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'offset'         => mt_rand(0, $eligible_count - 1),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'cache_results'  => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);
		if (!empty($post_history)) {
			$args['post__not_in'] = $post_history;
		}

		$random_query = new WP_Query($args);
		if (!$random_query->have_posts()) {
			return null;
		}

		$post = $random_query->posts[0];
		$this->add_to_history($post->ID);

		return $post;
	}

	/**
	 * Count published posts of the given types, excluding recent history
	 *
	 * @param array $post_types  Post type slugs
	 * @param array $exclude_ids Post IDs to exclude
	 * @return int Number of eligible posts
	 */
	private function count_eligible_posts($post_types, $exclude_ids) {
		global $wpdb;

		$type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status = 'publish'";
		$params = $post_types;

		if (!empty($exclude_ids)) {
			$id_placeholders = implode(',', array_fill(0, count($exclude_ids), '%d'));
			$sql .= " AND ID NOT IN ({$id_placeholders})";
			$params = array_merge($params, array_map('intval', $exclude_ids));
		}

		return (int) $wpdb->get_var($wpdb->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound
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
			// Clamp server-side to the same 1-100 range the form field enforces.
			'sanitize_callback' => array($this, 'sanitize_history_limit'),
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
	 * Clamp the history limit to the documented 1-100 range
	 *
	 * @param mixed $value Submitted value
	 * @return int Sanitized history limit
	 */
	public function sanitize_history_limit($value) {
		return max(1, min(100, absint($value)));
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
		// Handle the nonce-protected Clear History action.
		$history_cleared = false;
		if (isset($_GET['clear_history']) && check_admin_referer('sneerly_clear_history')) {
			delete_transient($this->get_user_transient_name());
			$history_cleared = true;
		}

		// Get current history limit
		$history_limit = (int)get_option('sneerly_coherent_history_limit', $this->history_limit);
		
		// Get currently enabled post types
		$enabled_post_types = get_option('sneerly_coherent_post_types', $this->default_post_types);
		
		// Get available post types
		$available_post_types = $this->get_available_post_types();
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			<?php if ($history_cleared) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('History cleared.', 'sneerly-coherent-random'); ?></p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields('sneerly_coherent_random'); ?>
				
				<h2><?php esc_html_e('General Settings', 'sneerly-coherent-random'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sneerly_coherent_history_limit">History Size</label>
						</th>
						<td>
							<input type="number" id="sneerly_coherent_history_limit"
								   name="sneerly_coherent_history_limit"
								   value="<?php echo esc_attr($history_limit); ?>"
								   min="1" max="100"
								   aria-describedby="sneerly_coherent_history_limit_description" />
							<p class="description" id="sneerly_coherent_history_limit_description">
								Number of posts to remember and avoid repeating. Higher values prevent more repetition.
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e('Post Types', 'sneerly-coherent-random'); ?></h2>
				<p><?php esc_html_e('Select which post types to include in random selection:', 'sneerly-coherent-random'); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e('Include Post Types', 'sneerly-coherent-random'); ?>
						</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">
									<?php esc_html_e('Include Post Types', 'sneerly-coherent-random'); ?>
								</legend>
								<?php foreach ($available_post_types as $post_type => $label) : ?>
									<label style="display: block; margin-bottom: 8px;">
										<input type="checkbox"
											   name="sneerly_coherent_post_types[]"
											   value="<?php echo esc_attr($post_type); ?>"
											   <?php checked(in_array($post_type, $enabled_post_types)); ?> />
										<?php echo esc_html($label); ?>
										<?php if ($post_type === 'post') : ?>
											<em>(<?php esc_html_e('Standard Posts', 'sneerly-coherent-random'); ?>)</em>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e('The random post selection will only include the checked post types.', 'sneerly-coherent-random'); ?>
								</p>
							</fieldset>
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
				
				echo '<p><a href="' . esc_url(wp_nonce_url(add_query_arg('clear_history', '1'), 'sneerly_clear_history')) .
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
		// wp-scripts compiles src/editor.css into build/index.css.
		$style_path = SNEERLY_COHERENT_RANDOM_PLUGIN_DIR . 'build/index.css';
		
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
				SNEERLY_COHERENT_RANDOM_PLUGIN_URL . 'build/index.css',
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

		// Rewrite the button's href with cache busting. Non-greedy [^>]*
		// keeps the match inside a single tag, and the limit of 1 only
		// touches the button anchor itself.
		$new_content = preg_replace(
			'/<a([^>]*)href="[^"]*"([^>]*)>/',
			'<a$1href="?random&cb=' . esc_attr($unique_cache_buster) . '"$2>',
			$content,
			1
		);

		// preg_replace() returns null on PCRE failure — never blank the block.
		return ($new_content !== null) ? $new_content : $content;
	}
}

// Initialize the plugin
new Sneerly_Coherent_Random_Post();