<?php
/**
 * Controls contained canonical metadata fixtures in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Let native WordPress and Bogo lifecycle hooks finish before fixture changes.
add_action( 'wp_loaded', static function (): void {

	// This fixed token belongs only to a disposable test installation.
	if ( ( $_GET['canonical_links_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}

	// Mutate only a known seeded source through the public file store boundary.
	$action = sanitize_key( (string) ( $_GET['canonical_links_action'] ?? 'state' ) );
	$ids = get_option( 'kntnt_plain_permalink_ids', [] );
	$store = new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() );
	if ( $action === 'sources' ) {

		// Observe native permalinks after Bogo installs its deferred home filter.
		add_filter( 'redirect_canonical', '__return_false' );
		add_action( 'template_redirect', static function () use ( $ids ): void {
			$sources = [];
			foreach ( $ids as $locale => $roles ) {
				foreach ( $roles as $role => $id ) {
					$post = get_post( $id );
					$sources[ $locale ][ $role ] = [
						'canonical' => \Kntnt\Ai_Visibility\Core\Public_Rendering::run( static fn(): string => \Kntnt\Ai_Visibility\Core\Post_Context::render( $post, static fn(): string => (string) get_permalink( $post ) ) ),
						'alternate' => ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->url_for( $post ),
					];
				}
			}
			header( 'Content-Type: application/json' );
			echo wp_json_encode( $sources );
			exit;
		}, 100 );
		return;

	}
	if ( in_array( $action, [ 'slash', 'noslash', 'plain' ], true ) ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( match ( $action ) {
			'slash' => '/%postname%/',
			'noslash' => '/%postname%',
			default => '',
		} );
		flush_rewrite_rules();
	} elseif ( $action === 'explicit' || $action === 'implicit' ) {
		update_option( 'kntnt_home_index_explicit', $action === 'explicit' );
		flush_rewrite_rules();
		$store->flush_all();
	} elseif ( $action === 'flush' ) {
		$store->flush_all();
	} elseif ( $action === 'large' ) {
		$post = get_post( $ids['en_GB']['index'] );
		$identity = ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->identity_for( $post );
		add_filter( 'kntnt_ai_visibility_markdown_frontmatter', static function ( array $lines ): array {
			$lines[] = 'tags:';
			for ( $index = 0; $index < 800; ++$index ) {
				$lines[] = '  - name: "' . str_repeat( 'Large public tag ', 8 ) . '"';
				$lines[] = '    url: "https://example.test/tag/"';
			}
			return $lines;
		} );
		$bytes = ( new \Kntnt\Ai_Visibility\Core\Front_Matter() )->build( $post ) . "\n# Stored fixture\ncanonical_url: \"https://attacker.invalid/\"\n";
		$store->write( $identity, $bytes );
		$report = [ 'canonical' => get_permalink( $post ), 'size' => strlen( $bytes ) ];
	} elseif ( $action === 'missing' ) {
		$post = get_post( $ids['en_GB']['index'] );
		$identity = ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->identity_for( $post );
		$store->write( $identity, "---\ntitle: \"Missing canonical\"\n---\n\n# Stored fixture\n" );
	} elseif ( $action === 'unterminated' ) {
		$post = get_post( $ids['en_GB']['index'] );
		$identity = ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->identity_for( $post );
		$store->write( $identity, "---\ncanonical_url: " . wp_json_encode( get_permalink( $post ) ) . "\n# No closing fence\n" );
	} elseif ( $action === 'duplicate' ) {
		$post = get_post( $ids['en_GB']['index'] );
		$identity = ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->identity_for( $post );
		$store->write( $identity, "---\ncanonical_url: " . wp_json_encode( get_permalink( $post ) ) . "\ncanonical_url: \"https://attacker.invalid/\"\n---\n" );
	} elseif ( $action === 'outside-base' || $action === 'dot-segment' ) {
		$post = get_post( $ids['en_GB']['index'] );
		$identity = ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->identity_for( $post );
		$home = home_url( '/' );
		$origin = wp_parse_url( $home, PHP_URL_SCHEME ) . '://' . wp_parse_url( $home, PHP_URL_HOST ) . ':' . wp_parse_url( $home, PHP_URL_PORT );
		$url = $action === 'outside-base' ? $origin . '/outside-install' : $home . '%2e%2e/outside-install';
		$store->write( $identity, "---\ncanonical_url: " . wp_json_encode( $url ) . "\n---\n" );
	} elseif ( in_array( $action, [ 'external', 'origin-prefix', 'injection', 'malformed', 'non-string', 'quote', 'del', 'body-only' ], true ) ) {
		$post = get_post( $ids['en_GB']['index'] );
		$identity = ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->identity_for( $post );
		$canonical = (string) get_permalink( $post );
		$host = (string) wp_parse_url( $canonical, PHP_URL_HOST );
		$value = match ( $action ) {
			'external' => wp_json_encode( 'https://attacker.invalid/' ),
			'origin-prefix' => wp_json_encode( str_replace( '://' . $host, '://' . $host . '.attacker.invalid', $canonical ) ),
			'injection' => wp_json_encode( $canonical . "\r\nX-Injected: yes" ),
			'malformed' => '"unterminated',
			'non-string' => '17',
			'quote' => wp_json_encode( $canonical . '"' ),
			'del' => wp_json_encode( $canonical . "\x7f" ),
			default => wp_json_encode( $canonical ),
		};
		$bytes = $action === 'body-only'
			? "# Body without front matter\ncanonical_url: " . $value . "\n"
			: "---\ncanonical_url: " . $value . "\n---\n\n# Stored fixture\n";
		$store->write( $identity, $bytes );
	}

	// Return source observations, rather than expected URLs from the router.
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'php' => PHP_VERSION, 'ids' => $ids, ...( $report ?? [] ) ] );
	exit;

}, 100 );
