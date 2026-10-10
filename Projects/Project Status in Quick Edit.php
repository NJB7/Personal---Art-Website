/**
 * Project Status in list + Quick Edit
 * Needs the "Project Journal" snippet (it saves _project_status).
 */

function pjq_statuses() {
	if ( function_exists( 'pj_statuses' ) ) {
		return pj_statuses();
	}
	return array(
		'idea'        => 'Idea',
		'in-progress' => 'In progress',
		'finished'    => 'Finished',
	);
}

// 1. Column in the Projects list.
add_filter( 'manage_project_posts_columns', function ( $cols ) {
	$new = array();
	foreach ( $cols as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['pj_status'] = 'Status';
		}
	}
	if ( ! isset( $new['pj_status'] ) ) {
		$new['pj_status'] = 'Status';
	}
	return $new;
} );

add_action( 'manage_project_posts_custom_column', function ( $column, $post_id ) {
	if ( 'pj_status' !== $column ) {
		return;
	}
	$val      = get_post_meta( $post_id, '_project_status', true );
	$statuses = pjq_statuses();
	$label    = isset( $statuses[ $val ] ) ? $statuses[ $val ] : '';
	echo '<span id="pj-status-' . (int) $post_id . '" data-status="' . esc_attr( $val ) . '">' . esc_html( $label ? $label : '—' ) . '</span>';
}, 10, 2 );

// 2. Dropdown inside Quick Edit.
add_action( 'quick_edit_custom_box', function ( $column, $post_type ) {
	if ( 'pj_status' !== $column || 'project' !== $post_type ) {
		return;
	}
	wp_nonce_field( 'pj_status_quick', 'pj_status_nonce', false );
	echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col">';
	echo '<label><span class="title">Status</span>';
	echo '<select name="pj_status_quick"><option value="">— Not set —</option>';
	foreach ( pjq_statuses() as $key => $label ) {
		echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select></label></div></fieldset>';
}, 10, 2 );

// 3. Fill the dropdown with the current value when Quick Edit opens.
add_action( 'admin_footer-edit.php', function () {
	$screen = get_current_screen();
	if ( ! $screen || 'project' !== $screen->post_type ) {
		return;
	}
	echo '<script>
	jQuery(function ($) {
		if (typeof inlineEditPost === "undefined") { return; }
		var orig = inlineEditPost.edit;
		inlineEditPost.edit = function (id) {
			orig.apply(this, arguments);
			var postId = (typeof id === "object") ? this.getId(id) : id;
			var val = $("#pj-status-" + postId).data("status") || "";
			$("#edit-" + postId + " select[name=pj_status_quick]").val(val);
		};
	});
	</script>';
} );

// 4. Save it.
add_action( 'save_post_project', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( isset( $_REQUEST['bulk_edit'] ) ) {
		return; // leave bulk edits alone
	}
	if ( ! isset( $_POST['pj_status_nonce'] ) || ! wp_verify_nonce( $_POST['pj_status_nonce'], 'pj_status_quick' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['pj_status_quick'] ) ) {
		return;
	}
	$val = sanitize_key( wp_unslash( $_POST['pj_status_quick'] ) );
	if ( '' === $val || ! array_key_exists( $val, pjq_statuses() ) ) {
		delete_post_meta( $post_id, '_project_status' );
	} else {
		update_post_meta( $post_id, '_project_status', $val );
	}
} );
