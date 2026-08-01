<?php
/**
 * Standalone runner for testing Push MD round-trip fidelity against all live WP database posts & pages.
 *
 * Usage:
 *   php plugins/push-md/Tests/run-live-db-fidelity.php /path/to/wordpress/wp-load.php
 */

// If a path to wp-load.php was passed as an argument, require it.
if ( isset( $argv[1] ) && file_exists( $argv[1] ) ) {
	require_once $argv[1];
} elseif ( file_exists( dirname( __DIR__, 3 ) . '/wp-load.php' ) ) {
	require_once dirname( __DIR__, 3 ) . '/wp-load.php';
}

if ( ! function_exists( 'get_posts' ) && ! isset( $GLOBALS['wpdb'] ) ) {
	echo "Error: WordPress environment (wp-load.php) or database connection is not loaded.\n";
	echo "Usage: php plugins/push-md/Tests/run-live-db-fidelity.php /path/to/wordpress/wp-load.php\n";
	exit( 1 );
}

require_once __DIR__ . '/RoundTripFidelityTest.php';

$runner = new RoundTripFidelityTest();

$db_posts = array();
if ( function_exists( 'get_posts' ) ) {
	$db_posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );
} elseif ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
	$wpdb     = $GLOBALS['wpdb'];
	$db_posts = $wpdb->get_results( "SELECT ID, post_name, post_title, post_content, post_type FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' ORDER BY ID ASC" );
}

$limit = isset( $argv[2] ) && is_numeric( $argv[2] ) ? (int) $argv[2] : -1;
if ( $limit > 0 && count( $db_posts ) > $limit ) {
	$db_posts = array_slice( $db_posts, 0, $limit );
}

$total_found = count( $db_posts );
echo sprintf( "Found %d live published posts and pages in the database to test.\n\n", $total_found );
echo "Running round-trip fidelity checks (100%% read-only in-memory processing)...\n";
echo "-----------------------------------------------------------------------\n";

$passed     = 0;
$failed     = 0;
$skipped    = 0;
$strategies = array( 'word_append', 'word_prepend', 'heading_append' );

foreach ( $db_posts as $idx => $post ) {
	$post_id   = is_object( $post ) ? ( $post->ID ?? $idx ) : $idx;
	$post_type = is_object( $post ) ? ( $post->post_type ?? 'post' ) : 'post';
	$title     = is_object( $post ) ? ( $post->post_title ?? "Post #$post_id" ) : "Post #$post_id";
	$content   = trim( (string) ( is_object( $post ) ? $post->post_content : '' ) );

	if ( '' === $content ) {
		echo sprintf( "[SKIP] ID %d (%s: '%s') - Empty content\n", $post_id, strtoupper( $post_type ), $title );
		$skipped++;
		continue;
	}

	$strategy = $strategies[ $idx % count( $strategies ) ];
	$label    = sprintf( "%s ID %d ('%s')", strtoupper( $post_type ), $post_id, substr( $title, 0, 45 ) );

	try {
		$runner->run_single_fidelity_check( $label, $content, $strategy );
		echo sprintf( "[PASS] %s\n", $label );
		$passed++;
	} catch ( Exception $e ) {
		echo sprintf( "\n[FAIL #%d] %s:\n%s\n\n", $failed + 1, $label, $e->getMessage() );
		$failed++;
	} catch ( Throwable $t ) {
		echo sprintf( "\n[FAIL #%d] %s:\n%s\n\n", $failed + 1, $label, $t->getMessage() );
		$failed++;
	}
}

echo "\n========================================================\n";
echo " LIVE DATABASE ROUND-TRIP FIDELITY SUMMARY REPORT        \n";
echo "========================================================\n";
echo sprintf( "Total Found:   %d\n", $total_found );
echo sprintf( "Passed:        %d\n", $passed );
echo sprintf( "Failed:        %d\n", $failed );
echo sprintf( "Skipped Empty: %d\n", $skipped );
echo "========================================================\n";
