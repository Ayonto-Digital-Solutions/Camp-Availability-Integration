<?php
/**
 * GitHub-based Plugin Updater.
 *
 * Checks a GitHub repository for new releases and integrates with the
 * WordPress plugin update system so the plugin can be updated directly
 * from the WordPress admin dashboard.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.59
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_GitHub_Updater {

	/**
	 * Instance.
	 *
	 * @var AS_CAI_GitHub_Updater|null
	 */
	private static $instance = null;

	/**
	 * GitHub repository owner/name.
	 *
	 * @var string
	 */
	private $repo = '';

	/**
	 * Plugin slug (directory name).
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Plugin basename (e.g. "as-camp-availability-integration/as-camp-availability-integration.php").
	 *
	 * @var string
	 */
	private $basename = '';

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $version = '';

	/**
	 * GitHub API response cache (transient name).
	 *
	 * @var string
	 */
	private $cache_key = 'as_cai_github_updater_cache';

	/**
	 * Cache duration in seconds (6 hours).
	 *
	 * @var int
	 */
	private $cache_duration = 21600;

	/**
	 * GitHub access token (optional, for private repos or higher rate limits).
	 *
	 * @var string
	 */
	private $access_token = '';

	/**
	 * Get instance.
	 *
	 * @return AS_CAI_GitHub_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->basename = defined( 'AS_CAI_PLUGIN_BASENAME' ) ? AS_CAI_PLUGIN_BASENAME : 'camp-availability-integration/as-camp-availability-integration.php';
		$this->slug     = dirname( $this->basename );
		$this->version  = defined( 'AS_CAI_VERSION' ) ? AS_CAI_VERSION : '0.0.0';

		// Repository is hardcoded — no configuration needed.
		$this->repo = 'zb-marc/Camp-Availability-Integration';

		// Optional access token for private repos.
		// Define AS_CAI_GITHUB_TOKEN in wp-config.php if repo is private:
		// define( 'AS_CAI_GITHUB_TOKEN', 'ghp_yourTokenHere' );
		$this->access_token = defined( 'AS_CAI_GITHUB_TOKEN' ) ? AS_CAI_GITHUB_TOKEN : '';

		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Inject our plugin info into WordPress update checks.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

		// Provide plugin information for the "View details" modal.
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

		// After update: rename extracted folder to match plugin slug.
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );

		// Clear cache when plugin list is force-refreshed.
		add_action( 'admin_init', array( $this, 'maybe_clear_cache' ) );
	}

	/**
	 * Fetch latest release info from GitHub API.
	 *
	 * @param bool $force_refresh Whether to bypass cache.
	 * @return object|false Release data or false on failure.
	 */
	private function get_latest_release( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( $this->cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );

		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
			'timeout' => 10,
		);

		if ( ! empty( $this->access_token ) ) {
			$args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body ) || ! isset( $body->tag_name ) ) {
			return false;
		}

		// Normalize version (strip leading "v" if present).
		$body->tag_name = ltrim( $body->tag_name, 'vV' );

		// Cache the result.
		set_transient( $this->cache_key, $body, $this->cache_duration );

		return $body;
	}

	/**
	 * Check for plugin updates.
	 *
	 * @param object $transient WordPress update transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $release->tag_name;

		if ( version_compare( $remote_version, $this->version, '>' ) ) {
			// Find the ZIP download URL.
			$download_url = $this->get_download_url( $release );

			if ( $download_url ) {
				$transient->response[ $this->basename ] = (object) array(
					'slug'        => $this->slug,
					'plugin'      => $this->basename,
					'new_version' => $remote_version,
					'url'         => 'https://github.com/' . $this->repo,
					'package'     => $download_url,
					'icons'       => array(),
					'banners'     => array(),
					'tested'      => '',
					'requires'    => '6.5',
					'requires_php' => '8.0',
				);
			}
		}

		return $transient;
	}

	/**
	 * Get the download URL from a release.
	 *
	 * Prefers a .zip asset upload; falls back to the source zipball.
	 *
	 * @param object $release GitHub release object.
	 * @return string|false Download URL.
	 */
	private function get_download_url( $release ) {
		// Check for uploaded ZIP asset first.
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->browser_download_url ) && preg_match( '/\.zip$/i', $asset->name ) ) {
					$url = $asset->browser_download_url;
					if ( ! empty( $this->access_token ) ) {
						$url = add_query_arg( 'access_token', $this->access_token, $url );
					}
					return $url;
				}
			}
		}

		// Fallback: Use source code zipball.
		if ( ! empty( $release->zipball_url ) ) {
			$url = $release->zipball_url;
			if ( ! empty( $this->access_token ) ) {
				$url = add_query_arg( 'access_token', $this->access_token, $url );
			}
			return $url;
		}

		return false;
	}

	/**
	 * Provide plugin information for the "View details" modal.
	 *
	 * @param false|object|array $result Plugin info result.
	 * @param string             $action API action.
	 * @param object             $args   API arguments.
	 * @return false|object Plugin info or false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$info = new stdClass();
		$info->name          = 'Camp Availability Integration';
		$info->slug          = $this->slug;
		$info->version       = $release->tag_name;
		$info->author        = '<a href="https://marc.mirschel.biz">Marc Mirschel</a>';
		$info->homepage      = 'https://github.com/' . $this->repo;
		$info->requires      = '6.5';
		$info->requires_php  = '8.0';
		$info->tested        = '';
		$info->downloaded    = 0;
		$info->last_updated  = isset( $release->published_at ) ? $release->published_at : '';
		$info->sections      = array(
			'description'  => 'Integriert den Availability Scheduler Timer mit dem Stachethemes Seat Planner für Camp-Buchungen.',
			'changelog'    => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : '',
		);
		$info->download_link = $this->get_download_url( $release );

		return $info;
	}

	/**
	 * After installation: Rename the extracted directory to match the plugin slug.
	 *
	 * GitHub source zips extract to "owner-repo-hash/" which won't match our slug.
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra data.
	 * @param array $result     Installation result.
	 * @return array Modified result.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $result;
		}

		$proper_destination = WP_PLUGIN_DIR . '/' . $this->slug;
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;

		// Reactivate plugin after update.
		activate_plugin( $this->basename );

		return $result;
	}

	/**
	 * Clear update cache if force-check is requested.
	 */
	public function maybe_clear_cache() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			delete_transient( $this->cache_key );
		}
	}
}
