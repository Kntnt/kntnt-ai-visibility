<?php
/**
 * Adds relative references to actual translated home/nested/dated sources.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require __DIR__ . '/home-index-seed.php';

// Reuse the accepted static-home and translated hierarchy fixture.
$ids = get_option( 'kntnt_home_index_ids' );
foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
	$ids[ $locale ]['post'] = wp_insert_post( [
		'post_type' => 'post',
		'post_status' => 'publish',
		'post_name' => 'relative-story',
		'post_title' => 'post ' . $locale,
		'post_date' => '2026-10-08 12:00:00',
		'meta_input' => [ '_locale' => $locale ],
	] );
	wp_update_post( [ 'ID' => $ids[ $locale ]['post'], 'post_name' => 'relative-story' ] );
	foreach ( [ 'front', 'nested', 'post' ] as $role ) {
		$id = $ids[ $locale ][ $role ];
		$links = [
			'child' => 'child/', 'parent' => '../sibling/',
			'query' => '?view=print&next=%2Fpart#query', 'fragment' => '#local',
			'root' => '/shared/', 'absolute' => 'https://remote.test/absolute/',
			'protocol' => '//cdn.test/asset', 'mail' => 'mailto:reader@example.test',
		];
		$html = '<p>REFERENCE-SOURCE-' . $id . '</p><p>';
		foreach ( $links as $name => $url ) {
			$html .= '<a href="' . esc_attr( $url ) . '">' . $name . '-' . $id . '</a> ';
		}
		$html .= '<img src="images/picture.png?size=2#preview" alt="image-' . $id . '"></p>';
		wp_update_post( [ 'ID' => $id, 'post_content' => $html ] );
	}
}
update_post_meta( $ids['sv_SE']['post'], '_original_post', get_post_meta( $ids['en_GB']['post'], '_original_post', true ) );
update_option( 'kntnt_ai_visibility', [ 'content_types' => [ 'post' => [ 'llms_full' => true ] ] ] );
update_option( 'kntnt_relative_reference_ids', $ids );
copy( __DIR__ . '/relative-references-helper.php', WP_CONTENT_DIR . '/mu-plugins/relative-references-helper.php' );
flush_rewrite_rules();
