<?php
/**
 * Public helper functions for Picot Subscription Membership.
 *
 * @package Picot_Subscription_Membership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check whether a user may access a protected post.
 *
 * @param int $post_id Post ID.
 * @param int $user_id User ID. Defaults to the current user.
 * @return bool
 */
function picot_membership_user_can_access( $post_id, $user_id = 0 ) {
	return Picot_Subscription_Membership_Content::user_can_access( (int) $post_id, (int) $user_id );
}

/**
 * Get the membership record for a user.
 *
 * @param int $user_id User ID. Defaults to the current user.
 * @return object|null
 */
function picot_membership_get_user_membership( $user_id = 0 ) {
	return Picot_Subscription_Membership_Membership::get_for_user( $user_id ? (int) $user_id : get_current_user_id() );
}

/**
 * Check whether a user has active membership access.
 *
 * @param int $user_id User ID. Defaults to the current user.
 * @return bool
 */
function picot_membership_is_active( $user_id = 0 ) {
	return Picot_Subscription_Membership_Membership::is_active( $user_id ? (int) $user_id : get_current_user_id() );
}

/**
 * Get the effective membership access expiry for a user.
 *
 * @param int $user_id User ID. Defaults to the current user.
 * @return string|null
 */
function picot_membership_get_access_until( $user_id = 0 ) {
	$membership = picot_membership_get_user_membership( $user_id );
	return $membership ? $membership->effective_access_until : null;
}
