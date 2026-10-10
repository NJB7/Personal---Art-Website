<?php
/**
 * Snippet: Wander into the Unknown
 * A StumbleUpon-style button that sends visitors to a random public page or project.
 *
 * Usage: add this as a PHP snippet set to "Run everywhere" (it needs to run on the
 * front end), then place the shortcode [wander_button] on your homepage.
 * Optional: [wander_button label="Wander into the Unknown"]
 *
 * Uses nba_admin_only_pages() from your "Add Action (Admin Only)" snippet, so any
 * page you add to that list is automatically left out of the shuffle too.
 *
 * Colours:
 *   #2a0052  dark purple (site background, dark text on light buttons)
 *   #ffffde  light yellow (button)
 *   #fa14b9  pink (hover)
 *   #ffffeb  very pale yellow (focus ring)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types the button can send people to.
 * Add or remove types here. Types that don't exist on the site are skipped.
 */
function nb_wander_post_types() {
	$types = apply_filters( 'nb_wander_post_types', array( 'page', 'project', 'journal', 'work') );
	return array_values( array_filter( $types, 'post_type_exists' ) );
}

/**
 * Slugs of admin-only pages (add-content, add-post, link-errors, etc.).
 * Pulled from your admin-only snippet so there is one list to maintain.
 */
function nb_wander_hidden_slugs() {
	if ( function_exists( 'nba_admin_only_pages' ) ) {
		return (array) nba_admin_only_pages();
	}
	// Fallback if the admin-only snippet is switched off.
	return array( 'add-content', 'add-post', 'add-pin', 'add-project', 'add-book', 'add-journal', 'add-work', 'link-errors' );
}

/**
 * Is this post allowed as a destination?
 */
function nb_wander_is_allowed( $post ) {
	// Only published, public, non-password-protected content.
	if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
		return false;
	}

	// Never the homepage / posts page.
	$front = (int) get_option( 'page_on_front' );
	$blog  = (int) get_option( 'page_for_posts' );
	if ( in_array( (int) $post->ID, array( $front, $blog ), true ) ) {
		return false;
	}

	// Admin-only pages, and anything nested underneath them.
	if ( 'page' === $post->post_type ) {
		$hidden = nb_wander_hidden_slugs();
		if ( in_array( $post->post_name, $hidden, true ) ) {
			return false;
		}
		foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
			if ( in_array( get_post_field( 'post_name', $ancestor_id ), $hidden, true ) ) {
				return false;
			}
		}
	}

	// Projects: skip any whose Status is Idea (In progress, Finished and
	// "Not set" are all fine). Change the list with the filter below if you
	// ever want to hide other statuses too.
	if ( 'project' === $post->post_type ) {
		$hide_statuses = (array) apply_filters( 'nb_wander_hidden_project_statuses', array( 'idea' ) );
		$status        = (string) get_post_meta( $post->ID, '_project_status', true );
		if ( in_array( $status, $hide_statuses, true ) ) {
			return false;
		}
	}

	// Anything else with "add content" in its slug or template, just in case.
	$haystack = strtolower( $post->post_name . ' ' . get_page_template_slug( $post ) );
	if ( preg_match( '/add[-_ ]?content/', $haystack ) ) {
		return false;
	}

	// Extra exclusions: IDs or slugs you want to keep out of the shuffle.
	$excluded = apply_filters( 'nb_wander_excluded', array() ); // e.g. array( 123, 'contact' )
	if ( in_array( (int) $post->ID, array_map( 'intval', $excluded ), true )
		|| in_array( $post->post_name, $excluded, true ) ) {
		return false;
	}

	// Final say for other snippets: return false to block a post.
	return (bool) apply_filters( 'nb_wander_allow_post', true, $post );
}

/**
 * Handle /?wander=1 : pick a random allowed post and redirect.
 */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['wander'] ) || is_admin() ) {
		return;
	}

	$ids = get_posts( array(
		'post_type'      => nb_wander_post_types(),
		'post_status'    => 'publish',
		'has_password'   => false,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	// Don't send people back to the page they just came from, if possible.
	$current = isset( $_GET['from'] ) ? absint( $_GET['from'] ) : 0;
	shuffle( $ids );

	$target = '';
	foreach ( $ids as $id ) {
		if ( (int) $id === $current ) {
			continue;
		}
		$post = get_post( $id );
		if ( $post && nb_wander_is_allowed( $post ) ) {
			$target = get_permalink( $post );
			break;
		}
	}

	nocache_headers();
	wp_safe_redirect( $target ? $target : home_url( '/' ), 302 );
	exit;
} );

/**
 * Shortcode: [wander_button label="Wander into the Unknown"]
 */
add_shortcode( 'wander_button', function ( $atts ) {
	$atts = shortcode_atts( array(
		'label' => 'Wander into the Unknown',
	), $atts, 'wander_button' );

	$args = array( 'wander' => 1 );
	if ( is_singular() ) {
		$args['from'] = get_the_ID();
	}
	$url = add_query_arg( $args, home_url( '/' ) );

	return sprintf(
		'<p class="nb-wander-wrap"><a class="nb-wander-button" href="%s" rel="nofollow">%s</a></p>',
		esc_url( $url ),
		esc_html( $atts['label'] )
	);
} );

/**
 * Button styling using the site colours.
 */
add_action( 'wp_head', function () {
	?>
	<style>
		.nb-wander-wrap { text-align: center; margin: 1.5em 0; }
		a.nb-wander-button {
			display: inline-block;
			padding: .8em 1.6em;
			border: 2px solid #ffffde;
			border-radius: 999px;
			background: #ffffde;
			color: #2a0052;
			text-decoration: none;
			font-weight: 600;
			letter-spacing: .03em;
			transition: transform .15s ease, background .15s ease, border-color .15s ease;
		}
		a.nb-wander-button:hover,
		a.nb-wander-button:focus {
			background: #fa14b9;
			border-color: #fa14b9;
			color: #2a0052;
			text-decoration: none;
			transform: translateY(-2px);
		}
		a.nb-wander-button:focus-visible { outline: 2px solid #ffffeb; outline-offset: 3px; }
	</style>
	<?php
} );
