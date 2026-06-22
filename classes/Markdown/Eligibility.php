<?php
/**
 * Decides whether a post may have a Markdown alternate.
 *
 * A request resolves to a Markdown alternate only for a single, public,
 * published entry (docs/adr/0009). This class is the gate: published status, a
 * viewable post type that is not an attachment, and membership of the eligible
 * post-type set. The default set is every publicly-queryable type; a settings
 * field narrows it and a filter is the programmatic escape hatch. The viewable
 * check is a hard guard the override cannot bypass, so non-public content can
 * never be cached and served by the early router.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;

/**
 * The single-public-published-entry eligibility rule for Markdown alternates.
 *
 * @since 0.1.0
 */
final class Eligibility {

	/**
	 * Binds eligibility to the settings registry it reads its scope from.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings_Registry $settings The settings registry.
	 */
	public function __construct( private readonly Settings_Registry $settings ) {}

	/**
	 * Reports whether a post may be served as a Markdown alternate.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The candidate post.
	 * @return bool
	 */
	public function is_eligible( \WP_Post $post ): bool {

		// Only published, non-attachment, viewable entries qualify; attachments
		// are listings of media, not singular content.
		if ( $post->post_status !== 'publish' ) {
			return false;
		}
		if ( $post->post_type === 'attachment' || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}

		// The post type must also be in the eligible set (default, narrowed by
		// the setting, then adjustable by the filter).
		return in_array( $post->post_type, $this->eligible_types(), true );

	}

	/**
	 * Resolves the eligible post-type set: default, then setting, then filter.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The eligible post-type slugs.
	 */
	private function eligible_types(): array {

		// Start from the configured override, falling back to every
		// publicly-queryable type when the owner has set none.
		$configured = $this->settings->value( 'markdown', 'post_types' );
		$configured = is_array( $configured ) ? array_values( array_filter( $configured, 'is_string' ) ) : [];
		$types = $configured !== [] ? $configured : $this->default_types();

		// The filter is the developer escape hatch mirroring the setting.
		$types = apply_filters( 'kntnt_ai_visibility_eligible_post_types', $types );

		return is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : [];

	}

	/**
	 * Returns every publicly-queryable post type except attachments.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string>
	 */
	private function default_types(): array {

		// Every publicly-queryable type is eligible by default; attachments are
		// removed because they are media listings, not singular content.
		$types = get_post_types( [ 'publicly_queryable' => true ], 'names' );
		unset( $types['attachment'] );

		return array_values( $types );

	}

}
