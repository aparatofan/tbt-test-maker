<?php
/**
 * Capability management for TBT Test Maker.
 *
 * The whole teacher-facing surface — building and editing tests — hangs
 * off a single capability, TBTTM_CAP ("tbttm_manage"). Site configuration (pages,
 * role grants, bull pictures) stays on manage_options.
 *
 * v1 grants TBTTM_CAP to administrators only, but because nothing is hard-coded
 * to a user ID, any role can be granted the same capability from the settings
 * page to gain authoring rights without wp-admin access.
 *
 * Mirrors TBTD_Capabilities (Translation Drill) so the tools behave the same way.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Capabilities {

	/**
	 * Bumped whenever the capability set changes, so an existing install picks
	 * the caps up on upgrade. register_activation_hook does not fire for a
	 * plugin that is already active, so activation alone is not enough.
	 */
	const CAPS_VERSION = '1';

	/**
	 * Roles that should receive the management capability.
	 *
	 * Read from the saved setting, defaulting to administrators. Filterable so
	 * a future "teacher" role can be granted authoring rights in code too.
	 *
	 * @return string[]
	 */
	public static function managing_roles() {
		$roles = get_option( 'tbttm_manager_roles', array( 'administrator' ) );
		if ( ! is_array( $roles ) || empty( $roles ) ) {
			$roles = array( 'administrator' );
		}
		/**
		 * Filter the list of roles granted the TBT Test Maker management capability.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'tbttm_managing_roles', $roles );
	}

	/**
	 * Grant the management capability to the configured roles.
	 */
	public static function add_caps() {
		foreach ( self::managing_roles() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && ! $role->has_cap( TBTTM_CAP ) ) {
				$role->add_cap( TBTTM_CAP );
			}
		}
	}

	/**
	 * Grant the caps once per capability version. Runs on every request, so it
	 * short-circuits on an option read in the common case.
	 */
	public static function maybe_add_caps() {
		if ( get_option( 'tbttm_caps_version' ) === self::CAPS_VERSION ) {
			return;
		}
		self::add_caps();
		update_option( 'tbttm_caps_version', self::CAPS_VERSION );
	}

	/**
	 * Sync the management capability across all roles to match a selection.
	 * Roles in the list gain the cap; all others lose it. Used by the settings
	 * page. (Administrators always manage via manage_options regardless.)
	 *
	 * @param string[] $selected_roles Role slugs that should have the cap.
	 */
	public static function sync_role_caps( $selected_roles ) {
		$selected = array_map( 'strval', (array) $selected_roles );
		$roles    = wp_roles();
		if ( ! $roles ) {
			return;
		}
		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $role_slug, $selected, true ) ) {
				if ( ! $role->has_cap( TBTTM_CAP ) ) {
					$role->add_cap( TBTTM_CAP );
				}
			} elseif ( $role->has_cap( TBTTM_CAP ) ) {
				$role->remove_cap( TBTTM_CAP );
			}
		}
	}

	/**
	 * Remove the management capability from every role. Used on uninstall.
	 */
	public static function remove_caps() {
		$roles = wp_roles();
		if ( ! $roles ) {
			return;
		}
		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && $role->has_cap( TBTTM_CAP ) ) {
				$role->remove_cap( TBTTM_CAP );
			}
		}
	}

	/**
	 * Can the current (or a given) user manage tests?
	 *
	 * Administrators always count, so access never depends solely on the custom
	 * capability having been attached to the role.
	 *
	 * @param int|null $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage( $user_id = null ) {
		if ( null === $user_id ) {
			return current_user_can( TBTTM_CAP ) || current_user_can( 'manage_options' );
		}
		return user_can( $user_id, TBTTM_CAP ) || user_can( $user_id, 'manage_options' );
	}
}
