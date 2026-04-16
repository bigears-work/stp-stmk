<?php
/**
 * Event Queries für GenerateBlocks Query Loop
 *
 * Filtert die WP_Query-Args des GB Query Loop Blocks anhand
 * einer CSS-Klasse, die im Block Editor vergeben wird:
 *
 *   sg-events-upcoming → Kommende Events (>= heute, ASC)
 *   sg-events-past     → Vergangene Events (< heute, DESC)
 *
 * Einrichtung im Block Editor:
 *   Query Block → Erweitert → Zusätzliche CSS-Klasse(n)
 *   → "sg-events-upcoming" oder "sg-events-past" eintragen
 *
 * @package Stolpersteine
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'generateblocks_query_loop_args', function( array $query_args, array $attributes ): array {

	// Nur im Frontend ausführen.
	if ( is_admin() ) {
		return $query_args;
	}

	$classes = $attributes['className'] ?? '';
	$today   = wp_date( 'Y-m-d' ); // Lokale Zeitzone (Europe/Vienna).

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
