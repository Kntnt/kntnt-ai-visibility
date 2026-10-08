<?php
/**
 * Native fixture actions for permalink context changes, without cache purges.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Keep the custom type viewable in every ordinary request and mutation.
add_action( 'init', static function (): void {
    register_post_type( 'kntnt_lifecycle_book', [
        'public' => true,
        'query_var' => false,
        'rewrite' => [ 'slug' => 'books' ],
        'supports' => [ 'title', 'editor', 'revisions' ],
    ] );
} );

// Visible integration output exposes regeneration without reading cache files.
add_filter( 'the_content', static function ( string $html ): string {
    global $post;
    if ( $post instanceof WP_Post && str_ends_with( $post->post_name, '-ctx-revision' ) ) {
        $count = (int) get_option( 'kntnt_lifecycle_render_count', 0 ) + 1;
        update_option( 'kntnt_lifecycle_render_count', $count );
        return $html . '<p>REVISION-RENDER-' . $count . '</p>';
    }
    return $html;
} );
foreach ( [ 'llms_txt', 'llms_full_txt' ] as $artifact ) {
    add_filter( 'kntnt_ai_visibility_' . $artifact, static function ( string $document ) use ( $artifact ): string {
        $key = 'kntnt_lifecycle_build_' . $artifact;
        $count = (int) get_option( $key, 0 ) + 1;
        update_option( $key, $count );
        return $document . "\n<!-- BUILD-" . $artifact . '-' . $count . " -->\n";
    } );
}

/**
 * Seeds distinct context sources for each permalink mode.
 *
 * @since 0.5.2
 * @return array<string, mixed> Fixture IDs, never expected URLs.
 */
function kntnt_lifecycle_seed_context( string $mode ): array {

    // Exercise WordPress's normal option invalidation when changing the mode.
    global $wp_rewrite;
    $wp_rewrite->set_permalink_structure( $mode === 'plain' ? '' : '/%postname%/' );
    update_option( 'kntnt_ai_visibility', [ 'content_types' => [
        'post' => [ 'llms_full' => true ],
        'kntnt_lifecycle_book' => [ 'llms_full' => true ],
    ] ] );

    // Literal source markers and fixed dates make independent HTTP assertions.
    $context = [ 'mode' => $mode ];
    foreach ( [ 'dated', 'type', 'revision', 'rename', 'draft', 'private', 'trash', 'protect', 'delete' ] as $role ) {
        $context[ $role ] = wp_insert_post( [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => $mode . '-ctx-' . $role,
            'post_title' => 'Context ' . $mode . ' ' . $role,
            'post_content' => '<p>CONTEXT-' . $mode . '-' . $role . '</p>',
            'post_date' => '2020-01-02 12:00:00',
            'post_date_gmt' => '2020-01-02 12:00:00',
            'meta_input' => [ '_locale' => 'en_GB' ],
        ] );
    }

    // Parent paths include an independent Swedish hierarchy and deletion case.
    foreach ( [ 'parent', 'child', 'spare', 'remove', 'orphan', 'sv_parent', 'sv_child' ] as $role ) {
        $context[ $role ] = wp_insert_post( [
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => $mode . '-ctx-' . str_replace( 'sv_', '', $role ),
            'post_title' => 'Context ' . $mode . ' ' . $role,
            'post_content' => '<p>CONTEXT-' . $mode . '-' . $role . '</p>',
            'post_parent' => match ( $role ) {
                'child' => $context['parent'],
                'orphan' => $context['remove'],
                'sv_child' => $context['sv_parent'],
                default => 0,
            },
            'meta_input' => [ '_locale' => str_starts_with( $role, 'sv_' ) ? 'sv_SE' : 'en_GB' ],
        ] );
        wp_update_post( [
            'ID' => $context[ $role ],
            'post_name' => $mode . '-ctx-' . str_replace( 'sv_', '', $role ),
        ] );
    }

    // Category changes travel through the public term APIs and their hooks.
    foreach ( [ 'old', 'new' ] as $role ) {
        $term = wp_insert_term( $mode . '-category-' . $role, 'category' );
        $context['category_' . $role] = $term['term_id'];
    }
    wp_set_object_terms( $context['dated'], [ $context['category_old'] ], 'category' );
    flush_rewrite_rules();
    return $context;

}

/**
 * Completes a native lifecycle action before the control response returns.
 *
 * @since 0.5.2
 * @param array<string, mixed> $ids The current disposable fixture IDs.
 * @return array<string, mixed> Fixture IDs after the action.
 */
function kntnt_lifecycle_context_action( string $action, array $ids ): array {

    // Each mode has fresh sources, including sources removed in the first pass.
    if ( in_array( $action, [ 'context-pretty', 'context-plain' ], true ) ) {
        $ids['context'] = kntnt_lifecycle_seed_context( substr( $action, 8 ) );
        update_option( 'kntnt_lifecycle_ids', $ids );
        return $ids;
    }

    // Mutate stored post fields before subsequent public HTTP requests.
    $context = $ids['context'] ?? [];
    $mode = $context['mode'] ?? 'pretty';
    if ( $action === 'parent-rename' || $action === 'sv-parent-rename' ) {
        $role = $action === 'parent-rename' ? 'parent' : 'sv_parent';
        wp_update_post( [ 'ID' => $context[ $role ], 'post_name' => $mode . '-ctx-renamed-parent' ] );
    } elseif ( $action === 'child-reparent' ) {
        wp_update_post( [ 'ID' => $context['child'], 'post_parent' => $context['spare'] ] );
    } elseif ( $action === 'parent-delete' ) {
        wp_delete_post( $context['remove'], true );
    } elseif ( $action === 'post-type' ) {
        wp_update_post( [ 'ID' => $context['type'], 'post_type' => 'kntnt_lifecycle_book' ] );
    } elseif ( $action === 'date-structure' ) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure( '/%category%/%year%/%monthnum%/%day%/%postname%/' );
        flush_rewrite_rules();
    } elseif ( $action === 'post-date' ) {
        wp_update_post( [
            'ID' => $context['dated'],
            'post_date' => '2021-03-04 12:00:00',
            'post_date_gmt' => '2021-03-04 12:00:00',
        ] );
    } elseif ( $action === 'post-category' ) {
        wp_set_object_terms( $context['dated'], [ $context['category_new'] ], 'category' );
    } elseif ( $action === 'term-rename' ) {
        wp_update_term( $context['category_new'], 'category', [
            'name' => $mode . '-category-renamed',
            'slug' => $mode . '-category-renamed',
        ] );
    } elseif ( $action === 'term-delete' ) {
        wp_delete_term( $context['category_new'], 'category' );
    } elseif ( $action === 'front-index' || $action === 'front-ordinary' ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $ids['en_GB'][ $action === 'front-index' ? 'index' : 'front' ] );
    } elseif ( $action === 'blog' ) {
        update_option( 'show_on_front', 'posts' );
    } elseif ( $action === 'simple' ) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure( '/%postname%/' );
        flush_rewrite_rules();
    } elseif ( $action === 'revision-prepare' ) {
        foreach ( wp_get_post_revisions( $context['revision'] ) as $revision ) {
            wp_delete_post_revision( $revision->ID );
        }
    } elseif ( $action === 'revision' ) {
        $revision_id = wp_save_post_revision( $context['revision'] );
        wp_update_post( [ 'ID' => $revision_id, 'post_content' => '<p>UNPUBLISHED-REVISION</p>' ] );
        $ids['revision_id'] = $revision_id;
    } elseif ( $action === 'autosave' || $action === 'autosave-update' ) {
        require_once ABSPATH . 'wp-admin/includes/post.php';
        wp_set_current_user( 1 );
        $autosave_id = wp_create_post_autosave( [
            'post_ID' => $context['revision'],
            'post_type' => 'post',
            'post_title' => 'Unpublished autosave',
            'content' => $action === 'autosave' ? 'UNPUBLISHED-AUTOSAVE' : 'UNPUBLISHED-AUTOSAVE-UPDATED',
        ] );
        $ids['autosave_id'] = $autosave_id;
        $ids['autosave_parent'] = wp_is_post_autosave( $autosave_id );
    } elseif ( $action === 'ctx-rename' ) {
        wp_update_post( [
            'ID' => $context['rename'],
            'post_name' => $mode . '-ctx-renamed',
            'post_content' => '<p>CURRENT-' . $mode . '-source</p>',
        ] );
    } elseif ( in_array( $action, [ 'ctx-draft', 'ctx-private' ], true ) ) {
        $role = substr( $action, 4 );
        wp_update_post( [ 'ID' => $context[ $role ], 'post_status' => $role ] );
    } elseif ( $action === 'ctx-trash' ) {
        wp_trash_post( $context['trash'] );
    } elseif ( $action === 'ctx-protect' ) {
        wp_update_post( [ 'ID' => $context['protect'], 'post_password' => 'fixture-secret' ] );
    } elseif ( $action === 'ctx-delete' ) {
        wp_delete_post( $context['delete'], true );
    }

    return $ids;

}
