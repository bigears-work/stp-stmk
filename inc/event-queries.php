<?php
/**
 * Event Queries for GenerateBlocks Query Loop
 *
 * Filters the WP_Query args of the GB Query Loop block based on
 * a CSS class assigned in the Block Editor:
 *
 *   sg-events-upcoming → Upcoming events (>= today, ASC)
 *   sg-events-past     → Past events (< today, DESC)
 *
 * @package Stolpersteine
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'generateblocks_query_loop_args', function( array $query_args, array $attributes ): array {

	// Only run on the frontend.
	if ( is_admin() ) {
		return $query_args;
	}

	$classes = $attributes['className'] ?? '';
	$today   = wp_date( 'Y-m-d' ); // Local time zone.

	if ( str_contains( $classes, 'sg-events-upcoming' ) ) {
		$query_args = array_merge( $query_args, [
			'meta_key' => '_event_start_date',
			'orderby'  => 'meta_value',
			'order'    => 'ASC',
			'meta_query' => [
				[
					'key'     => '_event_start_date',
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'DATE',
				],
			],
		] );
	}

	if ( str_contains( $classes, 'sg-events-past' ) ) {
		$query_args = array_merge( $query_args, [
			'meta_key' => '_event_start_date',
			'orderby'  => 'meta_value',
			'order'    => 'DESC',
			'meta_query' => [
				[
					'key'     => '_event_start_date',
					'value'   => $today,
					'compare' => '<',
					'type'    => 'DATE',
				],
			],
		] );
	}

	return $query_args;

}, 10, 2 );
