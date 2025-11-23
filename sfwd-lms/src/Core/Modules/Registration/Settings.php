<?php
/**
 * LearnDash Registration settings class.
 *
 * @since 4.16.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core\Modules\Registration;

use LearnDash_Theme_Register_LD30;
use LearnDash_Settings_Section_Registration_Pages;

/**
 * Service provider class for registration.
 *
 * @since 4.16.0
 */
class Settings {
	/**
	 * Sets the new install appearance.
	 *
	 * This is hooked to the `learndash_initialization_new_install` action and overrides the default
	 * appearance of the registration pages from classic to modern if this is a new install.
	 *
	 * @since 4.16.0
	 *
	 * @return void
	 */
	public function action_set_new_install_appearance(): void {
		LearnDash_Settings_Section_Registration_Pages::set_setting(
			LearnDash_Settings_Section_Registration_Pages::$setting_registration_appearance,
			LearnDash_Theme_Register_LD30::$variation_modern
		);
	}
}
