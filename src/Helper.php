<?php
/**
 * Helper methods
 *
 * @package     Kirki
 * @category    Core
 * @author      Ari Stathopoulos (@aristath)
 * @copyright   Copyright (c) 2019, Ari Stathopoulos (@aristath)
 * @license    https://opensource.org/licenses/MIT
 * @since       1.0
 */

namespace Kirki\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A simple object containing static methods.
 */
class Helper {

	/**
	 * Recursive replace in arrays.
	 *
	 * @static
	 * @access public
	 * @param array $array The first array.
	 * @param array $array1 The second array.
	 * @return mixed
	 */
	public static function array_replace_recursive( $array, $array1 ) {
		if ( function_exists( 'array_replace_recursive' ) ) {
			return array_replace_recursive( $array, $array1 );
		}

		/**
		 * Handle the arguments, merge one by one.
		 *
		 * In PHP 7 func_get_args() changed the way it behaves but this doesn't mean anything in this case
		 * sinc ethis method is only used when the array_replace_recursive() function doesn't exist
		 * and that was introduced in PHP v5.3.
		 *
		 * Once WordPress-Core raises its minimum requirements we''' be able to remove this fallback completely.
		 */
		$args  = func_get_args(); // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue
		$array = $args[0];
		if ( ! is_array( $array ) ) {
			return $array;
		}
		$count = count( $args );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( is_array( $args[ $i ] ) ) {
				$array = self::recurse( $array, $args[ $i ] );
			}
		}
		return $array;
	}

	/**
	 * Helper method to be used from the array_replace_recursive method.
	 *
	 * @static
	 * @access public
	 * @param array $array The first array.
	 * @param array $array1 The second array.
	 * @return array
	 */
	public static function recurse( $array, $array1 ) {
		foreach ( $array1 as $key => $value ) {

			// Create new key in $array, if it is empty or not an array.
			if ( ! isset( $array[ $key ] ) || ( isset( $array[ $key ] ) && ! is_array( $array[ $key ] ) ) ) {
				$array[ $key ] = [];
			}

			// Overwrite the value in the base array.
			if ( is_array( $value ) ) {
				$value = self::recurse( $array[ $key ], $value );
			}
			$array[ $key ] = $value;
		}
		return $array;
	}

	/**
	 * Initialize the WP_Filesystem
	 *
	 * @static
	 * @access public
	 * @return object WP_Filesystem
	 */
	public static function init_filesystem() {
		$credentials = [];

		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}

		$method = defined( 'FS_METHOD' ) ? FS_METHOD : false;

		if ( 'ftpext' === $method ) {
			// If defined, set it to that, Else, set to NULL.
			$credentials['hostname'] = defined( 'FTP_HOST' ) ? preg_replace( '|\w+://|', '', FTP_HOST ) : null;
			$credentials['username'] = defined( 'FTP_USER' ) ? FTP_USER : null;
			$credentials['password'] = defined( 'FTP_PASS' ) ? FTP_PASS : null;

			// Set FTP port.
			if ( strpos( $credentials['hostname'], ':' ) && null !== $credentials['hostname'] ) {
				list( $credentials['hostname'], $credentials['port'] ) = explode( ':', $credentials['hostname'], 2 );
				if ( ! is_numeric( $credentials['port'] ) ) {
					unset( $credentials['port'] );
				}
			} else {
				unset( $credentials['port'] );
			}

			// Set connection type.
			if ( ( defined( 'FTP_SSL' ) && FTP_SSL ) && 'ftpext' === $method ) {
				$credentials['connection_type'] = 'ftps';
			} elseif ( ! array_filter( $credentials ) ) {
				$credentials['connection_type'] = null;
			} else {
				$credentials['connection_type'] = 'ftp';
			}
		}

		// The WordPress filesystem.
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once wp_normalize_path( ABSPATH . '/wp-admin/includes/file.php' ); // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude
			WP_Filesystem( $credentials );
		}

		return $wp_filesystem;
	}

	/**
	 * Returns the attachment object
	 *
	 * @static
	 * @access public
	 * @see https://pippinsplugins.com/retrieve-attachment-id-from-image-url/
	 * @param string $url URL to the image.
	 * @return int|string Numeric ID of the attachement.
	 */
	public static function get_image_id( $url ) {
		global $wpdb;
		if ( empty( $url ) ) {
			return 0;
		}

		$attachment = wp_cache_get( 'kirki_image_id_' . md5( $url ), null );
		if ( false === $attachment ) {
			$attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid = %s;", $url ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			wp_cache_add( 'kirki_image_id_' . md5( $url ), $attachment, null );
		}

		if ( ! empty( $attachment ) ) {
			return $attachment[0];
		}
		return 0;
	}

	/**
	 * Returns an array of the attachment's properties.
	 *
	 * @param string $url URL to the image.
	 * @return array
	 */
	public static function get_image_from_url( $url ) {
		$image_id = self::get_image_id( $url );
		$image    = wp_get_attachment_image_src( $image_id, 'full' );

		return [
			'url'       => $image[0],
			'width'     => $image[1],
			'height'    => $image[2],
			'thumbnail' => $image[3],
		];
	}

	/**
	 * Get an array of posts.
	 *
	 * @static
	 * @access public
	 * @param array $args Define arguments for the get_posts function.
	 * @return array
	 */
	public static function get_posts( $args ) {
		if ( is_string( $args ) ) {
			$args = add_query_arg(
				[
					'suppress_filters' => false,
				]
			);
		} elseif ( is_array( $args ) && ! isset( $args['suppress_filters'] ) ) {
			$args['suppress_filters'] = false;
		}

		// Get the posts.
		// TODO: WordPress.VIP.RestrictedFunctions.get_posts_get_posts.
		$posts = get_posts( $args );

		// Properly format the array.
		$items = [];
		foreach ( $posts as $post ) {
			$items[ $post->ID ] = $post->post_title;
		}
		wp_reset_postdata();

		return $items;
	}

	/**
	 * Get an array of publicly-querable taxonomies.
	 *
	 * @static
	 * @access public
	 * @return array
	 */
	public static function get_taxonomies() {
		$items = [];

		// Get the taxonomies.
		$taxonomies = get_taxonomies(
			[
				'public' => true,
			]
		);

		// Build the array.
		foreach ( $taxonomies as $taxonomy ) {
			$id           = $taxonomy;
			$taxonomy     = get_taxonomy( $taxonomy );
			$items[ $id ] = $taxonomy->labels->name;
		}

		return $items;
	}

	/**
	 * Get an array of publicly-querable post-types.
	 *
	 * @static
	 * @access public
	 * @return array
	 */
	public static function get_post_types() {
		$items = [];

		// Get the post types.
		$post_types = get_post_types(
			[
				'public' => true,
			],
			'objects'
		);

		// Build the array.
		foreach ( $post_types as $post_type ) {
			$items[ $post_type->name ] = $post_type->labels->name;
		}

		return $items;
	}

	/**
	 * Get an array of terms from a taxonomy
	 *
	 * @static
	 * @access public
	 * @param string|array $taxonomies See https://developer.wordpress.org/reference/functions/get_terms/ for details.
	 * @return array
	 */
	public static function get_terms( $taxonomies ) {
		$items = [];

		// Get the post types.
		$terms = get_terms( $taxonomies );

		// Build the array.
		foreach ( $terms as $term ) {
			$items[ $term->term_id ] = $term->name;
		}

		return $items;
	}

	/**
	 * Gets an array of material-design colors.
	 *
	 * @static
	 * @access public
	 * @param string $context Allows us to get subsets of the palette.
	 * @return array
	 */
	public static function get_material_design_colors( $context = 'primary' ) {
		if ( class_exists( '\Kirki\Core\Material_Colors' ) ) {
			return \Kirki\Core\Material_Colors::get_colors( $context );
		}
		return [];
	}

	/**
	 * Get an array of all available dashicons.
	 *
	 * @static
	 * @access public
	 * @return array
	 */
	public static function get_dashicons() {
		return [
			'admin-menu'     => [ 'menu', 'admin-site', 'dashboard', 'admin-post', 'admin-media', 'admin-links', 'admin-page', 'admin-comments', 'admin-appearance', 'admin-plugins', 'admin-users', 'admin-tools', 'admin-settings', 'admin-network', 'admin-home', 'admin-generic', 'admin-collapse', 'filter', 'admin-customizer', 'admin-multisite' ],
			'welcome-screen' => [ 'welcome-write-blog', 'welcome-add-page', 'welcome-view-site', 'welcome-widgets-menus', 'welcome-comments', 'welcome-learn-more' ],
			'post-formats'   => [ 'format-aside', 'format-image', 'format-gallery', 'format-video', 'format-status', 'format-quote', 'format-chat', 'format-audio', 'camera', 'images-alt', 'images-alt2', 'video-alt', 'video-alt2', 'video-alt3' ],
			'media'          => [ 'media-archive', 'media-audio', 'media-code', 'media-default', 'media-document', 'media-interactive', 'media-spreadsheet', 'media-text', 'media-video', 'playlist-audio', 'playlist-video', 'controls-play', 'controls-pause', 'controls-forward', 'controls-skipforward', 'controls-back', 'controls-skipback', 'controls-repeat', 'controls-volumeon', 'controls-volumeoff' ],
			'image-editing'  => [ 'image-crop', 'image-rotate', 'image-rotate-left', 'image-rotate-right', 'image-flip-vertical', 'image-flip-horizontal', 'image-filter', 'undo', 'redo' ],
			'tinymce'        => [ 'editor-bold', 'editor-italic', 'editor-ul', 'editor-ol', 'editor-quote', 'editor-alignleft', 'editor-aligncenter', 'editor-alignright', 'editor-insertmore', 'editor-spellcheck', 'editor-expand', 'editor-contract', 'editor-kitchensink', 'editor-underline', 'editor-justify', 'editor-textcolor', 'editor-paste-word', 'editor-paste-text', 'editor-removeformatting', 'editor-video', 'editor-customchar', 'editor-outdent', 'editor-indent', 'editor-help', 'editor-strikethrough', 'editor-unlink', 'editor-rtl', 'editor-break', 'editor-code', 'editor-paragraph', 'editor-table' ],
			'posts'          => [ 'align-left', 'align-right', 'align-center', 'align-none', 'lock', 'unlock', 'calendar', 'calendar-alt', 'visibility', 'hidden', 'post-status', 'edit', 'trash', 'sticky' ],
			'sorting'        => [ 'external', 'arrow-up', 'arrow-down', 'arrow-right', 'arrow-left', 'arrow-up-alt', 'arrow-down-alt', 'arrow-right-alt', 'arrow-left-alt', 'arrow-up-alt2', 'arrow-down-alt2', 'arrow-right-alt2', 'arrow-left-alt2', 'sort', 'leftright', 'randomize', 'list-view', 'exerpt-view', 'grid-view' ],
			'social'         => [ 'share', 'share-alt', 'share-alt2', 'twitter', 'rss', 'email', 'email-alt', 'facebook', 'facebook-alt', 'googleplus', 'networking' ],
			'wordpress_org'  => [ 'hammer', 'art', 'migrate', 'performance', 'universal-access', 'universal-access-alt', 'tickets', 'nametag', 'clipboard', 'heart', 'megaphone', 'schedule' ],
			'products'       => [ 'wordpress', 'wordpress-alt', 'pressthis', 'update', 'screenoptions', 'info', 'cart', 'feedback', 'cloud', 'translation' ],
			'taxonomies'     => [ 'tag', 'category' ],
			'widgets'        => [ 'archive', 'tagcloud', 'text' ],
			'notifications'  => [ 'yes', 'no', 'no-alt', 'plus', 'plus-alt', 'minus', 'dismiss', 'marker', 'star-filled', 'star-half', 'star-empty', 'flag', 'warning' ],
			'misc'           => [ 'location', 'location-alt', 'vault', 'shield', 'shield-alt', 'sos', 'search', 'slides', 'analytics', 'chart-pie', 'chart-bar', 'chart-line', 'chart-area', 'groups', 'businessman', 'id', 'id-alt', 'products', 'awards', 'forms', 'testimonial', 'portfolio', 'book', 'book-alt', 'download', 'upload', 'backup', 'clock', 'lightbulb', 'microphone', 'desktop', 'tablet', 'smartphone', 'phone', 'index-card', 'carrot', 'building', 'store', 'album', 'palmtree', 'tickets-alt', 'money', 'smiley', 'thumbs-up', 'thumbs-down', 'layout' ],
		];
	}

	/**
	 * Compares the 2 values given the condition
	 *
	 * @param mixed  $value1   The 1st value in the comparison.
	 * @param mixed  $value2   The 2nd value in the comparison.
	 * @param string $operator The operator we'll use for the comparison.
	 * @return boolean whether The comparison has succeded (true) or failed (false).
	 */
	public static function compare_values( $value1, $value2, $operator ) {
		if ( '===' === $operator ) {
			return $value1 === $value2;
		}
		if ( '!==' === $operator ) {
			return $value1 !== $value2;
		}
		if ( ( '!=' === $operator || 'not equal' === $operator ) ) {
			return $value1 != $value2; // phpcs:ignore WordPress.PHP.StrictComparisons
		}
		if ( ( '>=' === $operator || 'greater or equal' === $operator || 'equal or greater' === $operator ) ) {
			return $value2 >= $value1;
		}
		if ( ( '<=' === $operator || 'smaller or equal' === $operator || 'equal or smaller' === $operator ) ) {
			return $value2 <= $value1;
		}
		if ( ( '>' === $operator || 'greater' === $operator ) ) {
			return $value2 > $value1;
		}
		if ( ( '<' === $operator || 'smaller' === $operator ) ) {
			return $value2 < $value1;
		}
		if ( 'contains' === $operator || 'in' === $operator ) {
			if ( is_array( $value1 ) && is_array( $value2 ) ) {
				foreach ( $value2 as $val ) {
					if ( in_array( $val, $value1 ) ) { // phpcs:ignore WordPress.PHP.StrictInArray
						return true;
					}
				}
				return false;
			}
			if ( is_array( $value1 ) && ! is_array( $value2 ) ) {
				return in_array( $value2, $value1 ); // phpcs:ignore WordPress.PHP.StrictInArray
			}
			if ( is_array( $value2 ) && ! is_array( $value1 ) ) {
				return in_array( $value1, $value2 ); // phpcs:ignore WordPress.PHP.StrictInArray
			}
			return ( false !== strrpos( $value1, $value2 ) || false !== strpos( $value2, $value1 ) );
		}
		return $value1 == $value2; // phpcs:ignore WordPress.PHP.StrictComparisons
	}
}
