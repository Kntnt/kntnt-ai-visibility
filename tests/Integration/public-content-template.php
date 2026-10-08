<?php
/**
 * The real public HTML template for the field-backed fixture.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

while ( have_posts() ) {
	// This exact renderer, rather than arbitrary meta, defines the public body.
	the_post();
	echo '<main>' . kntnt_public_content_body( get_post() ) . '</main>';
}
