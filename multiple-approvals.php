<?php 
/*
Plugin Name: Multiple Approvals
Description: Require mutiple users (Editor or Administrator) to approve a post before it is considered approved. 
Plugin URI: http://code.tseivan.com/
Version: 0.1
Author: Ivan Tse
Author URI: http://tseivan.com
License: GPLv2 or later

Copyright 2011 Ivan Tse (email : ivan.tse1@gmail.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/

/** 
 * Add CSS and JS
 */
define( 'MAIT_CSS', plugin_dir_url( __FILE__ ).'css/' );
define( 'MAIT_JS', plugin_dir_url( __FILE__ ).'js/' );
wp_enqueue_style( 'mait_meta_box_css', MAIT_CSS.'style.css' );

// Only insert JS on the post editing page
add_action( 'load-post.php', 'mait_insertjs' );
function mait_insertjs(){
	global $current_user;
	$post_id=$_GET['post'];
	wp_enqueue_script( 'mait_ajax_js_handle', MAIT_JS.'ajax.js', array('jquery'), '', true );
	$protocol = isset( $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
	$params = array(
		'ajaxurl' => admin_url( 'admin-ajax.php', $protocol ),
		'postid' => $post_id,
		'userid' => $current_user->ID
	);
	wp_localize_script( 'mait_ajax_js_handle', 'mait_params', $params );
}

/** 
 * Activation and deactivation functions
 */
register_activation_hook( __FILE__, 'mait_activate' );
register_deactivation_hook( __FILE__, 'mait_deactivate' );
function mait_activate(){
	// Declare default options (when activated for the first time)
	if ( !get_option( 'mait_options' ) ){
		$defaults = array();
		$defaults['min'] = 1;
		$defaults['users'] = array();
		$defaults['publish_own'] = true;
		add_option( 'mait_options', $defaults );
	}
}
function mait_deactivate(){

}

/** 
 * Restrict the 'publish_post' capability. If user has 'approve_post', 
 * then 'publish_post' gets overriden. However, if 'publish_own' option 
 * is set to true in the plugin settings page, then allow user to publish
 * their own post (only exception to the overriding). 
 */
add_filter( 'map_meta_cap', 'mait_restrict_publish_post', 10, 4 );
function mait_restrict_publish_post( $caps, $cap, $user_id, $args ){
	$options = get_option( 'mait_options' );
	$publish_own = $options['publish_own'];
	if ( $cap == 'publish_posts' ){
		$post = get_post( $args[0] );
		if ( user_can( $user_id, 'approve_posts' ) ){
			// equivalent to saying if 'publish_own' is true and user is author of the post, then allow 'publish_post'
			if  ( !$publish_own || $post->post_author != $user_id ){
				$caps[] = 'mait_nonexistent_cap';
			}
		}
	}
	return $caps;
}

/** 
 * Add and edit the post editing page by adding/editing/displaying meta boxes
 */
add_action( 'add_meta_boxes', 'mait_add_post_meta_box', 10, 2 );
function mait_add_post_meta_box( $post_type, $post ){
	$status = $post->post_status;
	// hide default submit meta box since its capability is being overriden
	if ( current_user_can('approve_posts') && !current_user_can('publish_posts') && ( $status == 'pending' || $status == 'publish' ) ){
		add_meta_box( 'mait_post_meta_box', 'Publish', 'mait_display_post_meta_box', 'post', 'side', 'high' );
		remove_meta_box( 'submitdiv', 'post', 'side' );
	}
}

// If user cannot 'approve_post', display the status in the default submit meta box
add_action( 'post_submitbox_misc_actions', 'mait_edit_publish_box' );
function mait_edit_publish_box(){
	global $post;
	$status = $post->post_status;
	if ( $status == 'pending' || $status == 'publish' ){
		$approval_status = get_post_meta( $post->ID, 'mait_approval_status', true );
		$approval_requirement = get_option( 'mait_options' );
		$min = $approval_requirement['min'];
		echo '<div class="misc-pub-section mait-fix misc-pub-section-last"><span id="mait_approval_status">Approval Status: <strong>'.$approval_status.' / '.$min.'</strong></span></div>';
	}
}

function mait_display_post_meta_box( $post ){
	global $current_user;
	
	// Use nonce for verification
	$mait_nonce = wp_create_nonce( 'mait_nonce' );
	
	// Retrieve necessary data
	$approval_users = get_post_meta( $post->ID, 'mait_approval_user', false ); 
	$approval_status = get_post_meta( $post->ID, 'mait_approval_status', true );
	$options = get_option( 'mait_options' );
	$min = $options['min'];
	
	?><div id="mait_approval_box"><?php
	$published = $approval_status == $min || $post->post_status == 'publish';
	$progress = $published ? 100 : ( $approval_status / $min ) * 100 + 1; 
	if ( $post->post_status == 'publish' ){
		$min = $approval_status;
	}
		?>
		<span id='mait_header'>Approvals</span> ( <span id="mait_approval_number"><?php echo $approval_status; ?></span> / <?php echo $min; ?> )
		<div id="mait_progress_bar" title="Click to toggle the approval list">
			<div style="width: <?php echo $progress; ?>%;" id="mait_progress"><?php if ( $published ) echo 'Published'; ?></div>
		</div>
		<div id="mait_approval_list">
			<?php if ( empty( $approval_users  ) ){ 
				echo 'No one approved this post yet.';
			}
			else{
				echo mait_list_approval_users( $approval_users );
			}?></div>
	</div>
	<div id="mait_approval_buttons">
	<?php
	if ( !$published ){
		if ( in_array( $current_user->ID, $approval_users ) ){?>
			<div id="mait_remove_approval" class="submitbox mait_options_bar"><a class="submitdelete" href="<?php echo $mait_nonce; ?>">Remove your approval</a></div>
		<?php }
		else { ?>
		<div id="mait_approve" class="mait_options_bar"><strong><a href="<?php echo $mait_nonce; ?>" class="button-primary">Approve</a></strong>
		</div>
		<?php 
		} 
	}?>
	<div id="mait_save_button"><?php
		submit_button( 'Save Changes', 'secondary', 'submit', false );?> 
		</div>
	</div>
	<?php
}

function mait_list_approval_users( $approval_users ){
	$html_string = '';
	foreach ( $approval_users as $approval_user ){
		if ( $approval_user == 'Approval Override ' ){
			$html_string = $approval_user . '<br />';
		}
		else{
			$user_info = get_userdata( $approval_user );
			$html_string.= $user_info->user_login . '<br />';
		}
	}
	return $html_string;
}

function mait_approval_status( $approval_status ){
	$options = get_option( 'mait_options' );
	$min = $options['min'];
	if ( $min == $approval_status ){
		return 100;
	}
	else{
		return $approval_status/$min * 100;
	}
}

add_action('wp_ajax_mait_approve_post', 'mait_approve_post');
function mait_approve_post(){
	if ( wp_verify_nonce( $_REQUEST['nonce'] , 'mait_nonce' ) ){
		add_post_meta( $_REQUEST['postid'], 'mait_approval_user', $_REQUEST['userid'], false );
		$approval_status = get_post_meta( $_REQUEST['postid'], 'mait_approval_status', true );
		update_post_meta( $_REQUEST['postid'], 'mait_approval_status', $approval_status + 1 , $approval_status );
		$approval_status = get_post_meta( $_REQUEST['postid'], 'mait_approval_status', true );
		$approval_users = get_post_meta( $_REQUEST['postid'], 'mait_approval_user', false );
		$response = array(
			'what' => 'mait_approve_post',
			'action' => 'mait_approve_post',
			'id' => 1,
			'data' => mait_list_approval_users( $approval_users ),
			'supplemental' => array(
				'status' => mait_approval_status( $approval_status ),
				'approvals' => $approval_status
				)
		);
		if (  mait_approval_status( $approval_status ) == 100 ){
			wp_publish_post( $_REQUEST['postid'] );
		}
	}
	else{
		$response = array(
			'what' => 'mait_approve_post',
			'action' => 'mait_approve_post',
			'id' => 0,
			'data' => 'error'
		);
	}
	$xmlResponse = new WP_Ajax_Response($response);
	$xmlResponse->send();
	exit();
}
add_action('wp_ajax_mait_remove_approval', 'mait_remove_approval');
function mait_remove_approval(){
	if ( wp_verify_nonce( $_REQUEST['nonce'] , 'mait_nonce' ) ){
		delete_post_meta( $_REQUEST['postid'], 'mait_approval_user', $_REQUEST['userid'] );
		$approval_status = get_post_meta( $_REQUEST['postid'], 'mait_approval_status', true );
		update_post_meta( $_REQUEST['postid'], 'mait_approval_status', $approval_status - 1, $approval_status );
		$approval_status = get_post_meta( $_REQUEST['postid'], 'mait_approval_status', true );
		$approval_users = get_post_meta( $_REQUEST['postid'], 'mait_approval_user', false );
		$message = empty( $approval_users ) ? 'No one approved this post yet.' : mait_list_approval_users( $approval_users );
		$response = array(
			'what' => 'mait_remove_approval',
			'action' => 'mait_remove_approval',
			'id' => 1,
			'data' => $message,
			'supplemental' => array('status' =>mait_approval_status( $approval_status ),
				'approvals' => $approval_status
				)
		);
	}
	else{
		$response = array(
			'what' => 'mait_remove_approval',
			'action' => 'mait_approve_post',
			'id' => 0,
			'data' => 'error'
		);
	}
	$xmlResponse = new WP_Ajax_Response($response);
	$xmlResponse->send();
	exit();
}

add_action( 'wp_insert_post', 'mait_create_post_meta' );
function mait_create_post_meta( $post_id ){
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;
	$status = get_post_status( $post_id );
	$approval_status = get_post_meta( $post_id, 'mait_approval_status', true );
	
	if ( $status == 'pending' && $approval_status == '' ){
		add_post_meta( $post_id, 'mait_approval_status', 0, true);
	}
}
add_action('admin_menu', 'mait_create_submenu');
add_action( 'admin_menu', 'mait_admin_init' );
function mait_create_submenu(){
	add_options_page( 'Multiple Approval Settings', 'Multiple Approvals', 'manage_options', 'mait_settings', 'mait_settings_page' );
}
/*
mait_settings - the name of the settings group
mait_options - the name of the option. this is what is saved in the options table
*/
function mait_admin_init(){
	register_setting( 'mait_settings', 'mait_options', 'mait_vertify_settings' );
	add_settings_section( 'mait_roles_caps', 'Approve Post Capability', 'mait_display_roles_caps', 'mait_settings');
	add_settings_section( 'mait_approval_requirements', 'Approval Requirements', 'mait_display_approval_requirements', 'mait_settings');
	add_settings_field( 'mait_users_approve_post', 'User groups', 'mait_display_users_approve_post', 'mait_settings', 'mait_roles_caps' );
	add_settings_field( 'mait_users_publish_own', 'Allow these users to publish their own posts?', 'mait_display_users_publish_own', 'mait_settings', 'mait_roles_caps' );
	add_settings_field( 'mait_min_approvals', 'Minimum Number of Approvals', 'mait_display_min_approvals', 'mait_settings', 'mait_approval_requirements' );
}
function mait_display_roles_caps(){
	echo 'Choose which user groups that can approve posts.';
}
function mait_vertify_settings( $input ){
	$valid = array();
	$valid['min'] = intval( $input['min'] );
	if ( is_array( $input['users'] ) ){
		$valid['users'] = $input['users'];
	}
	else{
		$valid['users'] = array( $input['users'] );
	}
	if ( $input['publish_own'] == 'yes' ){
		$valid['publish_own'] = true;
	}
	else{
		$valid['publish_own'] = false;
	}
	return $valid;
}
function mait_display_approval_requirements(){
	echo 'Choose your requirement settings';
}
function mait_display_users_publish_own(){
	$options = get_option( 'mait_options' );
	$checked = ( $options['publish_own'] ) ? ' checked="checked" ': '';
	echo '<label><input '.$checked.' value="yes" name="mait_options[publish_own]" type="checkbox" />';
}
function mait_display_users_approve_post(){
	global $wp_roles;
	$options = get_option( 'mait_options' );
	$users = $options['users'];
	if ( empty( $users ) ){foreach ( $wp_roles->get_names() as $role_slug => $role_name ){
	echo "<label><input value='$role_slug' name='mait_options[users][]' type='checkbox' /> $role_name</label><br />";
	}}
	else{
	foreach ( $wp_roles->get_names() as $role_slug => $role_name ){
		$checked = ( in_array( $role_slug, $users ) ) ? ' checked="checked" ' : '';
		echo "<label><input ".$checked." value='$role_slug' name='mait_options[users][]' type='checkbox' /> $role_name</label><br />";
	}}
}
add_action( 'update_option_mait_options', 'mait_update_options', 10, 2);
function mait_update_options( $prev, $after ){
	foreach ( array_diff( $prev['users'], $after['users'] ) as $remove_cap_role ){
		$role =& get_role( $remove_cap_role );
		if ( !empty( $role ) ){
			$role->remove_cap( 'approve_posts' );
		}
	}
	foreach ( array_diff( $after['users'], $prev['users'] ) as $add_cap_role ){
		$role =& get_role( $add_cap_role );
		if ( !empty( $role ) ){
			$role->add_cap( 'approve_posts' );
		}
	}
	if ( $prev['min'] != $after['min'] ){
		 $all_posts = get_posts('numberposts=-1&post_type=post&post_status=pending');
		 foreach ( $all_posts as $post ){
			delete_post_meta( $post->ID, 'mait_approval_user' );
			update_post_meta( $post->ID, 'mait_approval_status', 0 );
		 }
	}
}
add_action( 'publish_to_pending', 'mait_delete_approvals', 10, 1 );
function mait_delete_approvals( $post ){
	$post_id = $post->ID;
	delete_post_meta( $post_id, 'mait_approval_user' );
	update_post_meta( $post_id, 'mait_approval_status', 0 );
}
add_action( 'publish_post', 'mait_approval_override', 10, 2 );
function mait_approval_override( $post_id, $post ){
	$approval_status = get_post_meta( $post_id, 'mait_approval_status', true );
	if ( empty( $approval_status ) ){
		$approval_status = 0;}
	$options = get_option( 'mait_options' );
	$min = $options['min'];
	if ( $min != $approval_status ){
		delete_post_meta( $post_id, 'mait_approval_user' );
		update_post_meta( $post_id, 'mait_approval_status', $min );
		add_post_meta( $post_id , 'mait_approval_user', 'Approval Override ', false );
	}
}
function mait_display_min_approvals(){
	$options = get_option( 'mait_options' );
	$min = $options['min'];
	echo "<input id='mait_min_approvals' type='text' name='mait_options[min]' value='$min' />";
}
function mait_settings_page(){
?>
	<div class="wrap">
		<?php screen_icon( 'plugins' ); ?>
		<h2>Multiple Approvals Settings</h2>
		<form action="options.php" method="post">
			<?php settings_fields('mait_settings');
			do_settings_sections('mait_settings'); ?>
			<br />
			<input name="submit" type="submit" value="Save Changes" class="button-primary" />
		</form>
		<br />
		<p>Note: The 'approve_post' capability overrides the 'publish_post' capability. So if user groups has both these capabilities, 'publish_post' 
		will be restricted so that these users cannot bypass the approval system. Leave a user group with 'publish_post' capability without the 'approve_post'
		capability so that this user group can still monitor and override approval systems. If a post was approved and this user group changes the post to pending,
		the data regarding the approvals will be deleted. Also, if this user group changes the post from pending to published whilst users approving the post, this
		data will be lost as well.</p>
	</div>

<?php
}
?>