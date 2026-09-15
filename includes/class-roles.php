<?php
/**
 * Who may work the queue.
 *
 * Every screen of the plugin required manage_options, which is the capability
 * to change the whole site. A newsroom could only let someone approve a story
 * by also letting them install plugins, delete users and change the theme.
 *
 * The queue now needs a capability of its own. Administrators and editors are
 * given it, and a News moderator role carries it with nothing else that
 * matters. Settings and Logs & Tools stay with administrators.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Roles {

	/**
	 * Capability to read, edit, approve and reject queue items.
	 */
	const MODERATE = 'wpnc_moderate_news';

	/**
	 * Role for someone who should do that and nothing else.
	 */
	const ROLE = 'wpnc_news_moderator';

	/**
	 * Option recording which version of the roles is installed.
	 */
	const VERSION_OPTION = 'wpnc_roles_version';

	/**
	 * Bumped whenever install() grants something new.
	 */
	const VERSION = '1';

	/**
	 * Screens a moderator is shown.
	 */
	const MODERATOR_TABS = array( 'dashboard', 'moderation' );

	/**
	 * Grant the capability and create the role. Safe to run repeatedly.
	 */
	public static function install() {
		foreach ( array( 'administrator', 'editor' ) as $name ) {
			$role = get_role( $name );

			if ( $role && ! $role->has_cap( self::MODERATE ) ) {
				$role->add_cap( self::MODERATE );
			}
		}

		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				wpnc__( 'News moderator', 'ناظر خبر' ),
				array(
					'read'         => true,
					'edit_posts'   => true,
					'upload_files' => true,
					self::MODERATE => true,
				)
			);
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Install on sites that updated rather than activated.
	 */
	public static function maybe_install() {
		if ( self::VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * Whether the current user may work the queue.
	 *
	 * manage_options counts too: an administrator's capabilities are loaded
	 * before the grant above runs, so on the first request after an update
	 * they would otherwise be locked out of their own queue.
	 *
	 * @return bool
	 */
	public static function can_moderate() {
		return current_user_can( self::MODERATE ) || current_user_can( 'manage_options' );
	}

	/**
	 * The capability the menu entry is registered with, for this user.
	 *
	 * @return string
	 */
	public static function menu_capability() {
		return current_user_can( 'manage_options' ) ? 'manage_options' : self::MODERATE;
	}

	/**
	 * The screens a user may open.
	 *
	 * @param array $tabs      Every screen, keyed by name, in display order.
	 * @param bool  $can_admin Whether the user administers the site.
	 * @return array
	 */
	public static function visible_tabs( $tabs, $can_admin ) {
		if ( $can_admin ) {
			return $tabs;
		}

		return array_intersect_key( (array) $tabs, array_flip( self::MODERATOR_TABS ) );
	}
}
