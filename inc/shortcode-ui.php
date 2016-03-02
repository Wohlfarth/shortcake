<?php

$_GLOBALS['shortcode_ui_shortcodes'] = array();

add_action( 'admin_enqueue_scripts',     'shortcode_ui_action_admin_enqueue_scripts' );
add_action( 'wp_enqueue_editor',         'shortcode_ui_action_wp_enqueue_editor' );
add_action( 'media_buttons',             'shortcode_ui_action_media_buttons' );
add_action( 'wp_ajax_bulk_do_shortcode', 'shortcode_ui_handle_ajax_bulk_do_shortcode' );
add_filter( 'wp_editor_settings',        'shortcode_ui_filter_wp_editor_settings', 10, 2 );

/**
 * Register UI for Shortcode
 *
 * @param  string $shortcode_tag
 * @param  array  $args
 * @return null
 */
function shortcode_ui_register_for_shortcode( $shortcode_tag, $args = array() ) {

	global $shortcode_ui_shortcodes;

	/**
	 * Filter the Shortcode UI options for all registered shortcodes.
	 *
	 * @since 0.6.0
	 *
	 * @param array $args           The configuration argument array specified in shortcode_ui_register_for_shortcode()
	 * @param string $shortcode_tag The shortcode base.
	 */
	$args = apply_filters( 'shortcode_ui_shortcode_args', $args, $shortcode_tag );

	/**
	 * Filter the Shortcode UI options for a specific registered shortcode.
	 *
	 * This dynamic filter uses the shortcode base and thus lets you hook on the options on a specific shortcode.
	 *
	 * @since 0.6.0
	 *
	 * @param array $args The configuration argument array specified in shortcode_ui_register_for_shortcode()
	 */
	$args = apply_filters( "shortcode_ui_shortcode_args_{$shortcode_tag}", $args );

	// inner_content=true is a valid argument, but we want more detail
	if ( isset( $args['inner_content'] ) && true === $args['inner_content'] ) {
		$args['inner_content'] = array(
			'label'       => esc_html__( 'Inner Content', 'shortcode-ui' ),
			'description' => '',
		);
	}

	if ( ! isset( $args['attrs'] ) ) {
		$args['attrs'] = array();
	}

	$args['shortcode_tag'] = $shortcode_tag;
	$shortcode_ui_shortcodes[ $shortcode_tag ] = $args;

	// Setup filter to handle decoding encoded attributes.
	add_filter( "shortcode_atts_{$shortcode_tag}", 'shortcode_ui_filter_shortcode_atts_decode_encoded', 5, 3 );

}

/**
 * Get configuration parameters for all shortcodes with UI.
 *
 * @return array
 */
function shortcode_ui_get_shortcodes() {

	global $shortcode_ui_shortcodes;

	if ( ! did_action( 'register_shortcode_ui' ) ) {

		/**
		 * Register shortcode UI for shortcodes.
		 *
		 * Can be used to register shortcode UI only when an editor is being enqueued.
		 *
		 * @param array $settings Settings array for the ective WP_Editor.
		 */
		do_action( 'register_shortcode_ui', array(), '' );
	}

	/**
	 * Filter the returned shortcode UI configuration parameters.
	 *
	 * Used to remove shortcode UI that's already been registered.
	 *
	 * @param array $shortcodes
	 */
	$shortcodes = apply_filters( 'shortcode_ui_shortcodes', $shortcode_ui_shortcodes );

	foreach ( $shortcodes as $shortcode => $args ) {

		foreach ( $args['attrs'] as $key => $value ) {
			foreach ( array( 'label', 'description' ) as $field ) {
				if ( ! empty( $value[ $field ] ) ) {
					$shortcodes[ $shortcode ]['attrs'][ $key ][ $field ] = wp_kses_post( $value[ $field ] );
				}
			}
		}

		foreach ( array( 'label', 'description' ) as $field ) {
			if ( ! empty( $args['inner_content'][ $field ] ) ) {
				$shortcodes[ $shortcode ]['inner_content'][ $field ] = wp_kses_post( $args['inner_content'][ $field ] );
			}
		}

	}

	return $shortcodes;
}

/**
 * Get UI configuration parameters for a given shortcode.
 *
 * @return array|false
 */
function shortcode_ui_get_shortcode( $shortcode_tag ) {

	$shortcodes = shortcode_ui_get_shortcodes();

	if ( isset( $shortcodes[ $shortcode_tag ] ) ) {
		return $shortcodes[ $shortcode_tag ];
	}

	return false;

}

/**
 * When a WP_Editor is initialized on a page, call the 'register_shortcode_ui' action.
 *
 * This action can be used to register styles and shortcode UI for any
 * shortcake-powered shortcodes, only on views which actually include a WP
 * Editor.
 */
function shortcode_ui_filter_wp_editor_settings( $settings, $editor_id ) {

	if ( ! did_action( 'register_shortcode_ui' ) ) {

		/**
		 * Register shortcode UI for shortcodes.
		 *
		 * Can be used to register shortcode UI only when an editor is being enqueued.
		 *
		 * @param array $settings Settings array for the ective WP_Editor.
		 */
		do_action( 'register_shortcode_ui', $settings, $editor_id );
	}

	return $settings;
}

/**
 * Enqueue scripts and styles used in the admin.
 *
 * Editor styles needs to be added before wp_enqueue_editor.
 *
 * @param array $editor_supports Whether or not the editor being enqueued has 'tinymce' or 'quicktags'
 */
function shortcode_ui_action_admin_enqueue_scripts( $editor_supports ) {
	$plugin_url = trailingslashit( plugin_dir_url( dirname( __FILE__ ) ) );
	add_editor_style( $plugin_url . 'css/shortcode-ui-editor-styles.css' );
}

/**
 * Enqueue scripts and styles needed for shortcode UI.
 */
function shortcode_ui_enqueue() {

	if ( did_action( 'enqueue_shortcode_ui' ) ) {
		return;
	}

	wp_enqueue_media();

	$shortcodes = array_values( shortcode_ui_get_shortcodes() );
	$current_post_type = get_post_type();
	if ( $current_post_type ) {
		foreach ( $shortcodes as $key => $args ) {
			if ( ! empty( $args['post_type'] ) && ! in_array( $current_post_type, $args['post_type'], true ) ) {
				unset( $shortcodes[ $key ] );
			}
		}
	}

	if ( empty( $shortcodes ) ) {
		return;
	}

	usort( $shortcodes, 'shortcode_ui_compare_shortcodes_by_label' );

	// Load minified version of wp-js-hooks if not debugging.
	$wp_js_hooks_file = 'wp-js-hooks' . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.min' : '' ) . '.js';

	wp_enqueue_script( 'shortcode-ui-js-hooks', plugin_dir_url( dirname( __FILE__ ) ) . 'lib/wp-js-hooks/' . $wp_js_hooks_file, array(), '2015-03-19' );
	wp_enqueue_script( 'shortcode-ui', plugin_dir_url( dirname( __FILE__ ) ) . 'js/build/shortcode-ui.js', array( 'jquery', 'backbone', 'mce-view', 'shortcode-ui-js-hooks' ) );
	wp_enqueue_style( 'shortcode-ui', plugin_dir_url( dirname( __FILE__ ) ) . 'css/shortcode-ui.css', array() );

	wp_localize_script( 'shortcode-ui', ' shortcodeUIData', array(
		'shortcodes'      => $shortcodes,
		'strings'         => array(
			'media_frame_title'                 => __( 'Insert Post Element', 'shortcode-ui' ),
			'media_frame_menu_insert_label'     => __( 'Insert Post Element', 'shortcode-ui' ),
			'media_frame_menu_update_label'     => __( '%s Details', 'shortcode-ui' ), // Substituted in JS
			'media_frame_toolbar_insert_label'  => __( 'Insert Element', 'shortcode-ui' ),
			'media_frame_toolbar_update_label'  => __( 'Update', 'shortcode-ui' ),
			'media_frame_no_attributes_message' => __( 'There are no attributes to configure for this Post Element.', 'shortcode-ui' ),
			'mce_view_error'                    => __( 'Failed to load preview', 'shortcode-ui' ),
			'search_placeholder'                => __( 'Search', 'shortcode-ui' ),
			'insert_content_label'              => __( 'Insert Content', 'shortcode-ui' ),
		),
		'nonces'     => array(
			'preview'        => wp_create_nonce( 'shortcode-ui-preview' ),
			'thumbnailImage' => wp_create_nonce( 'shortcode-ui-get-thumbnail-image' ),
		),
	) );

	// add templates to the footer, instead of where we're at now
	add_action( 'admin_print_footer_scripts', 'shortcode_ui_action_admin_print_footer_scripts' );

	/**
	 * Fires after shortcode UI assets have been enqueued.
	 *
	 * Will only fire once per page load.
	 */
	do_action( 'enqueue_shortcode_ui' );
}

/**
 * Enqueue shortcode UI assets when the editor is enqueued.
 */
function shortcode_ui_action_wp_enqueue_editor() {

	shortcode_ui_enqueue();

	/**
	 * Fires after shortcode UI assets have been loaded for the editor.
	 *
	 * Will fire every time the editor is loaded.
	 */
	do_action( 'shortcode_ui_loaded_editor' );
}

/**
 * Output an "Add Post Element" button with the media buttons.
 */
function shortcode_ui_action_media_buttons( $editor_id ) {
	printf( '<button type="button" class="button shortcake-add-post-element" data-editor="%s">' .
		'<span class="wp-media-buttons-icon dashicons dashicons-migrate"></span> %s' .
		'</button>',
		esc_attr( $editor_id ),
		esc_html__( 'Add Post Element', 'shortcode-ui' )
	);
}

/**
 * Output required underscore.js templates in the footer
 */
function shortcode_ui_action_admin_print_footer_scripts() {

	echo shortcode_ui_get_view( 'media-frame' ); // WPCS: xss ok
	echo shortcode_ui_get_view( 'list-item' ); // WPCS: xss ok
	echo shortcode_ui_get_view( 'edit-form' ); // WPCS: xss ok

	/**
	 * Fires after base shortcode UI templates have been loaded.
	 *
	 * Allows custom shortcode UI field types to load their own templates.
	 */
	do_action( 'print_shortcode_ui_templates' );
}

/**
 * Helper function for displaying a PHP template file.
 *
 * Template args array is extracted and passed to the template file.
 *
 * @param  string $template full template file path. Or name of template file in inc/templates.
 * @return string                 the template contents
 */
function shortcode_ui_get_view( $template ) {

	if ( ! file_exists( $template ) ) {

		$template_dir = plugin_dir_path( dirname( __FILE__ ) ) . 'inc/templates/';
		$template     = $template_dir . $template . '.tpl.php';

		if ( ! file_exists( $template ) ) {
			return '';
		}
	}

	ob_start();
	include $template;

	return ob_get_clean();
}

/**
 * Sort labels alphabetically.
 *
 * @param array $a
 * @param array $b
 * @return int
 */
function shortcode_ui_compare_shortcodes_by_label( $a, $b ) {
	return strcmp( $a['label'], $b['label'] );
}

/**
 * Render a shortcode body for preview.
 */
function shortcode_ui_render_shortcode_for_preview( $shortcode, $post_id = null ) {

	if ( ! defined( 'SHORTCODE_UI_DOING_PREVIEW' ) ) {
		define( 'SHORTCODE_UI_DOING_PREVIEW', true );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return esc_html__( "Something's rotten in the state of Denmark", 'shortcode-ui' );
	}

	if ( ! empty( $post_id ) ) {
		// @codingStandardsIgnoreStart
		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );
		// @codingStandardsIgnoreEnd
	}

	ob_start();
	/**
	 * Fires before shortcode is rendered in preview.
	 *
	 * @param string $shortcode Full shortcode including attributes
	 */
	do_action( 'shortcode_ui_before_do_shortcode', $shortcode );
	echo do_shortcode( $shortcode ); // WPCS: xss ok
	/**
	 * Fires after shortcode is rendered in preview.
	 *
	 * @param string $shortcode Full shortcode including attributes
	 */
	do_action( 'shortcode_ui_after_do_shortcode', $shortcode );

	return ob_get_clean();
}

/**
 * Get a bunch of shortcodes to render in MCE preview.
 */
function shortcode_ui_handle_ajax_bulk_do_shortcode() {

	if ( is_array( $_POST['queries'] ) ) {

		$responses = array();

		foreach ( $_POST['queries'] as $posted_query ) {

			// Don't sanitize shortcodes — can contain HTML kses doesn't allow (e.g. sourcecode shortcode)
			if ( ! empty( $posted_query['shortcode'] ) ) {
				$shortcode = stripslashes( $posted_query['shortcode'] );
			} else {
				$shortcode = null;
			}
			if ( isset( $posted_query['post_id'] ) ) {
				$post_id = intval( $posted_query['post_id'] );
			} else {
				$post_id = null;
			}

			$responses[ $posted_query['counter'] ] = array(
				'query' => $posted_query,
				'response' => shortcode_ui_render_shortcode_for_preview( $shortcode, $post_id ),
			);
		}

		wp_send_json_success( $responses );
		exit;
	}

}

/**
 * Decode any encoded attributes.
 *
 * @param array $out   The output array of shortcode attributes.
 * @param array $pairs The supported attributes and their defaults.
 * @param array $atts  The user defined shortcode attributes.
 * @return array $out  The output array of shortcode attributes.
 */
function shortcode_ui_filter_shortcode_atts_decode_encoded( $out, $pairs, $atts ) {

	global $shortcode_ui_shortcodes;

	// Get current shortcode tag from the current filter
	// by stripping `shortcode_atts_` from start of string.
	$shortcode_tag = substr( current_filter(), 15 );

	if ( ! isset( $shortcode_ui_shortcodes[ $shortcode_tag ] ) ) {
		return $out;
	}

	$fields = Shortcode_UI_Fields::get_instance()->get_fields();
	$args   = $shortcode_ui_shortcodes[ $shortcode_tag ];

	foreach ( $args['attrs'] as $attr ) {

		$default = isset( $fields[ $attr['type'] ]['encode'] ) ? $fields[ $attr['type'] ]['encode'] : false;
		$encoded = isset( $attr['encode'] ) ? $attr['encode'] : $default;

		if ( $encoded && isset( $out[ $attr['attr'] ] ) ) {
			$out[ $attr['attr'] ] = rawurldecode( $out[ $attr['attr'] ] );
		}
	}

	return $out;

}