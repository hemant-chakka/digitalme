<?php
/**
 * Provider for initializing the LearnDash Core plugin.
 *
 * @since 4.6.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core;

use LearnDash\Core\Template\Breakpoints;
use StellarWP\Learndash\lucatume\DI52\ContainerException;
use StellarWP\Learndash\lucatume\DI52\ServiceProvider;
use StellarWP\Learndash\StellarWP\Assets\Asset;
use StellarWP\Learndash\StellarWP\Assets\Assets;

/**
 * Class Provider for the LearnDash Core.
 *
 * @since 4.6.0
 */
class Provider extends ServiceProvider {
	/**
	 * Registers the service provider bindings.
	 *
	 * @since 4.6.0
	 *
	 * @throws ContainerException If the registration fails.
	 *
	 * @return void
	 */
	public function register(): void {
		// Registering implementations.
		$this->container->register( Libraries\Provider::class );

		$this->register_actions();

		$this->container->register( Licensing\Provider::class );
		$this->container->register( Settings\Provider::class );
		$this->container->register( Modules\Provider::class );
		$this->container->register( Infrastructure\Provider::class );

		// Registering in-progress features.

		// bail early if in-progress features are not enabled.
		if ( ! defined( 'LEARNDASH_ENABLE_IN_PROGRESS_FEATURES' ) || ! LEARNDASH_ENABLE_IN_PROGRESS_FEATURES ) { // @phpstan-ignore-line -- constant can be changed.
			return;
		}

		// Breezy template.
		if ( defined( 'LEARNDASH_ENABLE_FEATURE_BREEZY_TEMPLATE' ) && LEARNDASH_ENABLE_FEATURE_BREEZY_TEMPLATE ) { // @phpstan-ignore-line -- constant can be changed.
			$this->container->register( Themes\Provider::class );
		}
	}

	/**
	 * Register actions.
	 *
	 * @since 4.16.0
	 *
	 * @return void
	 */
	public function register_actions(): void {
		add_action( 'init', [ $this, 'register_scripts' ], 1 );
	}

	/**
	 * Registers the core LearnDash scripts that can be enqueued.
	 *
	 * These are global-level, core scripts that are used throughout the plugin.
	 *
	 * @since 4.16.0
	 *
	 * @return void
	 */
	public function register_scripts(): void {
		Asset::add( 'learndash-main', 'js/main.js' )
			->add_to_group( 'learndash-core' )
			->register();

		Asset::add( 'learndash-breakpoints', 'js/breakpoints.js' )
			->add_to_group( 'learndash-core' )
			->set_dependencies( 'learndash-main' )
			->add_localize_script(
				'learndash.views.breakpoints',
				[
					'list' => Breakpoints::get(),
				]
			)
			->register();
	}
}
