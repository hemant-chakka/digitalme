<?php

declare( strict_types=1 );

namespace LearnDash\Hub\Component;

use LearnDash\Hub\Framework\Base;
use LearnDash\Hub\Traits\Formats;
use LearnDash\Hub\Traits\License;

defined( 'ABSPATH' ) || exit;

/**
 * This class handle all stuffs relate to API.
 */
class API extends Base {
	use License;
	use Formats;

	const OPTION_NAME_PLUGIN_SLUGS = 'learndash-hub-projects-slugs';

	/**
	 * The API base URL.
	 *
	 * @var string
	 */
	public $base = LICENSING_SITE . '/wp-json/' . BASE_REST;

	/**
	 * Trigger a license verification.
	 *
	 * @param string $email The email that registered with LearnDash.
	 * @param string $license_key The license key provided when registered.
	 * @param bool   $force_check Force check the license status.
	 *
	 * @return \WP_Error|bool
	 */
	public function verify_license( string $email, string $license_key, bool $force_check = false ) {
		if ( ! $force_check ) {
			$license_status = $this->get_license_status();

			if ( '' !== $license_status ) {
				return ! is_wp_error( $license_status ) ? true : $license_status;
			}
		}

		$response = $this->do_api_request(
			'/site/auth',
			'POST',
			array(
				'site_url'    => site_url(),
				'license_key' => $license_key,
				'email'       => $email,
				'stats'       => $this->build_site_stats(),
			)
		);

		/**
		 * Fires after the license verification.
		 *
		 * @since 4.18.0
		 *
		 * @param \WP_Error|bool $license_response    `WP_Error` on failure, `true` on success.
		 * @param string         $license_email        License email.
		 * @param string         $license_key          License key.
		 */
		do_action(
			'learndash_licensing_management_license_verified',
			! is_wp_error( $response ) ? true : $response,
			$email,
			$license_key
		);

		$this->update_license_status( $response, $email, $license_key );

		return ! is_wp_error( $response ) ? true : $response;
	}


	/**
	 * Query the site stats.
	 *
	 * @since 4.18.0
	 *
	 * @return array
	 */
	public function build_site_stats(): array {
		global $wp_version;

		return array(
			'versions' => array(
				'wp' => $wp_version,
			),
			'network'  => array(
				'multisite'         => (int) is_multisite(),
				'network_activated' => 0,
				'active_sites'      => $this->get_multisite_active_sites(),
			),
		);
	}

	/**
	 * Gets multi-site active site count.
	 *
	 * @since 4.18.0
	 *
	 * @return int
	 */
	public function get_multisite_active_sites(): int {
		global $wpdb;

		if ( ! is_multisite() ) {
			$active_sites = 1;
		} else {
			$active_sites = (int) $wpdb->get_var(
				"
					SELECT
						COUNT( `blog_id` )
					FROM
						`{$wpdb->blogs}`
					WHERE
						`public` = '1'
						AND `archived` = '0'
						AND `spam` = '0'
						AND `deleted` = '0'
				"
			);
		}

		return $active_sites;
	}


	/**
	 * Return all the projects, and cache it.
	 */
	public function get_projects() {
		if ( defined( 'LEARNDASH_HUB_FETCH_ERROR' ) ) {
			return new \WP_Error( 'License Error', LEARNDASH_HUB_FETCH_ERROR );
		}
		$cached = get_site_option( 'learndash-hub-projects-api' );
		if ( is_array( $cached )
			&& isset( $cached['last_check'] )
			&& strtotime( '+1 hour', $cached['last_check'] ) < time() ) {
			$cached = array();
		}

		if ( ! is_array( $cached ) || empty( $cached['projects'] ) || is_wp_error( $cached['projects'] ) ) {
			delete_site_option( 'learndash_hub_fetch_projects' );
			delete_site_option( 'learndash_hub_update_plugins_cache' );
			$projects = $this->do_api_request( '/repo/plugins' );
			if ( is_wp_error( $projects ) ) {
				// page load cache.
				define( 'LEARNDASH_HUB_FETCH_ERROR', $projects->get_error_message() );
			}
			$cached = array(
				'projects'   => $projects,
				'last_check' => time(),
			);

			update_site_option( 'learndash-hub-projects-api', $cached );
			// we update the slugs here too, because the projects is an array of ['slug'=>project_data]

			if ( ! is_array( $projects ) ) {
				return [];
			}

			$slugs = array_keys( $projects );
			update_site_option( self::OPTION_NAME_PLUGIN_SLUGS, $slugs );
		}

		return $cached['projects'];
	}

	/**
	 * Remove the domain from API side
	 *
	 * @return array|\WP_Error
	 */
	public function remove_domain() {
		return $this->do_api_request(
			'/site/auth',
			'DELETE'
		);
	}
}
