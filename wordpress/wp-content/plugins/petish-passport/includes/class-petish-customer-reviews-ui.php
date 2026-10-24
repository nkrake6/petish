<?php
/** Customer review screens. State and authorization are enforced again by the core service. */
defined( 'ABSPATH' ) || exit;

final class Petish_Customer_Reviews_UI {
    const ENDPOINT = 'my-reviews';
    const DRAFT_KEY = 'petish_customer_review_draft';

    public static function boot() {
        if ( ! Petish_Customer_Reviews::enabled() ) { return; }
        add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
        add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'endpoint_url' ), 10, 4 );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu' ), 30 );
        add_filter( 'woocommerce_endpoint_my-reviews_title', static function () { return __( 'My reviews', 'petish-passport' ); } );
        add_action( 'woocommerce_account_my-reviews_endpoint', array( __CLASS__, 'dashboard' ) );
        add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'product_tabs' ), 99 );
        add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'product' ), 12 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_rating' ), 10 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 60 );
        add_action( 'template_redirect', array( __CLASS__, 'private_headers' ), 1 );
        add_action( 'add_meta_boxes_comment', array( __CLASS__, 'moderation_box' ) );
        add_action( 'admin_menu', array( __CLASS__, 'reports_menu' ) );
        add_action( 'admin_post_petish_customer_review_resolve_reports', array( __CLASS__, 'handle_resolve_reports' ) );
        foreach ( array( 'submit', 'vote', 'withdraw', 'report' ) as $operation ) {
            add_action( 'admin_post_petish_customer_review_' . $operation, array( __CLASS__, 'handle_' . $operation ) );
            add_action( 'admin_post_nopriv_petish_customer_review_' . $operation, array( __CLASS__, 'guest_redirect' ) );
        }
    }

    public static function register_endpoint() { add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES ); }
    public static function query_vars( $vars ) { $vars[ self::ENDPOINT ] = self::ENDPOINT; return $vars; }
    public static function endpoint_url( $url, $endpoint, $value, $permalink ) {
        return self::ENDPOINT === $endpoint ? add_query_arg( self::ENDPOINT, '1', $permalink ) : $url;
    }
    public static function url( $args = array() ) {
        return add_query_arg( array_merge( array( self::ENDPOINT => '1' ), $args ), wc_get_page_permalink( 'myaccount' ) );
    }
    public static function account_menu( $items ) {
        $out = array();
        foreach ( $items as $key => $label ) {
            if ( 'customer-logout' === $key ) { $out[ self::ENDPOINT ] = __( 'My reviews', 'petish-passport' ); }
            $out[ $key ] = $label;
        }
        if ( ! isset( $out[ self::ENDPOINT ] ) ) { $out[ self::ENDPOINT ] = __( 'My reviews', 'petish-passport' ); }
        return $out;
    }
    public static function on_dashboard() {
        return function_exists( 'is_account_page' ) && is_account_page() && is_wc_endpoint_url( self::ENDPOINT );
    }
    public static function private_headers() {
        if ( self::on_dashboard() || ( is_user_logged_in() && function_exists( 'is_product' ) && is_product() ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
            do_action( 'litespeed_control_set_nocache', 'Private customer review controls' );
            nocache_headers();
            header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
            header( 'Vary: Cookie', false );
        }
    }
    public static function enqueue() {
        if ( ! ( function_exists( 'is_account_page' ) && is_account_page() ) && ! ( function_exists( 'is_product' ) && is_product() ) ) { return; }
        $dir = dirname( __DIR__ );
        $base = plugin_dir_url( $dir . '/petish-passport.php' );
        $deps = wp_style_is( 'petish-design', 'enqueued' ) ? array( 'petish-design' ) : array();
        wp_enqueue_style( 'petish-customer-reviews', $base . 'assets/customer-reviews.css', $deps, substr( hash_file( 'sha256', $dir . '/assets/customer-reviews.css' ), 0, 16 ) );
        wp_enqueue_script( 'petish-customer-reviews', $base . 'assets/customer-reviews.js', array(), filemtime( $dir . '/assets/customer-reviews.js' ), true );
    }
    public static function product_tabs( $tabs ) { unset( $tabs['reviews'] ); return $tabs; }
    public static function product_rating() {
        global $product;
        if ( ! $product instanceof WC_Product || ! wc_review_ratings_enabled() || ! $product->get_rating_count() ) { return; }
        echo '<div class="woocommerce-product-rating">' . wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() );
        echo '<a href="#petish-customer-reviews" class="woocommerce-review-link" rel="nofollow">' . esc_html( sprintf( _n( '%s customer review', '%s customer reviews', $product->get_review_count(), 'petish-passport' ), number_format_i18n( $product->get_review_count() ) ) ) . '</a></div>';
    }

    private static function query( $key, $default = '' ) {
        return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
    }
    private static function value( $key, $multiline = false ) {
        if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { return ''; }
        return $multiline ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
    }
    public static function filters() {
        $sort = self::query( 'pcr_sort', 'newest' );
        $media = self::query( 'pcr_media', 'all' );
        return array(
            'page' => max( 1, min( 10000, absint( self::query( 'pcr_page', '1' ) ) ) ),
            'per_page' => 6,
            'sort' => in_array( $sort, array( 'newest', 'oldest', 'highest', 'lowest' ), true ) ? $sort : 'newest',
            'rating' => min( 5, absint( self::query( 'pcr_rating', '0' ) ) ),
            'media' => in_array( $media, array( 'all', 'photos', 'video' ), true ) ? $media : 'all',
            'verified' => '1' === self::query( 'pcr_verified' ),
        );
    }
    public static function product() {
        global $product;
        if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || post_password_required( $product->get_id() ) ) { return; }
        $filters = self::filters();
        $reviews = Petish_Customer_Reviews::public_reviews( $product->get_id(), $filters );
        if ( is_wp_error( $reviews ) ) { return; }
        $metrics = is_user_logged_in() ? Petish_Customer_Reviews::metrics( get_current_user_id() ) : null;
        $legacy = self::legacy_reviews( $product->get_id() );
        self::template( 'product', compact( 'product', 'filters', 'reviews', 'metrics', 'legacy' ) );
    }
    private static function legacy_reviews( $product_id ) {
        $page = max( 1, min( 10000, absint( self::query( 'pcr_earlier', '1' ) ) ) );
        $args = array( 'post_id' => $product_id, 'status' => 'approve', 'parent' => 0,
            'type__in' => array( 'review', 'comment', '' ),
            'meta_query' => array( array( 'key' => '_petish_review_managed', 'compare' => 'NOT EXISTS' ) ),
        );
        $total = (int) get_comments( array_merge( $args, array( 'count' => true ) ) );
        $items = get_comments( array_merge( $args, array( 'number' => 5, 'offset' => ( $page - 1 ) * 5, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) ) );
        return array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / 5 ) );
    }
    public static function dashboard() {
        if ( ! is_user_logged_in() ) { woocommerce_login_form(); return; }
        $uid = get_current_user_id();
        $metrics = Petish_Customer_Reviews::metrics( $uid );
        $status = self::query( 'pcr_status', 'published' );
        if ( ! in_array( $status, array( 'published', 'pending', 'withdrawn' ), true ) ) { $status = 'published'; }
        $reviews = Petish_Customer_Reviews::owner_reviews( $uid, array( 'status' => $status, 'page' => max( 1, min( 10000, absint( self::query( 'pcr_page', '1' ) ) ) ), 'per_page' => 6 ) );
        $product_id = absint( self::query( 'pcr_product', '0' ) );
        $product = $product_id ? wc_get_product( $product_id ) : null;
        if ( $product && ( 'publish' !== $product->get_status() || post_password_required( $product_id ) || ! $product->get_reviews_allowed() ) ) { $product = null; }
        $search = self::query( 'pcr_search' );
        $search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 80 ) : substr( $search, 0, 80 );
        $products = strlen( $search ) >= 2 ? wc_get_products( array( 'status' => 'publish', 'limit' => 12, 's' => $search, 'orderby' => 'title', 'order' => 'ASC' ) ) : array();
        $values = self::draft( $product ? $product->get_id() : 0 );
        self::template( 'dashboard', compact( 'metrics', 'reviews', 'product', 'status', 'search', 'products', 'values' ) );
    }
    private static function draft( $product_id ) {
        $empty = array( 'alias' => '', 'title' => '', 'body' => '', 'rating' => '' );
        if ( ! function_exists( 'WC' ) || ! WC()->session ) { return $empty; }
        $draft = WC()->session->get( self::DRAFT_KEY );
        if ( ! is_array( $draft ) || (int) ( $draft['uid'] ?? 0 ) !== get_current_user_id() || (int) ( $draft['product_id'] ?? 0 ) !== $product_id ) { return $empty; }
        WC()->session->__unset( self::DRAFT_KEY );
        return array_merge( $empty, $draft['values'] ?? array() );
    }
    private static function session() {
        // WooCommerce does not load frontend notice helpers for admin-post.php.
        if ( ! function_exists( 'wc_add_notice' ) ) { require_once WC_ABSPATH . 'includes/wc-notice-functions.php'; }
        if ( function_exists( 'WC' ) && ! WC()->session ) { WC()->initialize_session(); }
    }
    private static function authorize( $operation, $id = 0 ) {
        if ( ! Petish_Customer_Reviews::enabled() || ! is_user_logged_in() ) { self::guest_redirect(); }
        self::session();
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( self::value( 'pcr_nonce' ), 'petish_customer_review_' . $operation . '_' . absint( $id ) ) ) {
            wc_add_notice( __( 'Your session could not be verified. Please try again from My reviews.', 'petish-passport' ), 'error' );
            self::redirect( self::url() );
        }
    }
    public static function nonce( $operation, $id = 0 ) { wp_nonce_field( 'petish_customer_review_' . $operation . '_' . absint( $id ), 'pcr_nonce' ); }
    public static function guest_redirect() { self::redirect( wp_login_url( self::url() ) ); }
    private static function redirect( $url ) { wp_safe_redirect( $url, 303 ); exit; }
    private static function outcome( $result, $success ) {
        wc_add_notice( is_wp_error( $result ) ? esc_html( $result->get_error_message() ) : $success, is_wp_error( $result ) ? 'error' : 'success' );
    }
    public static function handle_submit() {
        $id = absint( self::value( 'product_id' ) );
        self::authorize( 'submit', $id );
        $data = array( 'product_id' => $id, 'alias' => self::value( 'alias' ), 'title' => self::value( 'title' ), 'body' => self::value( 'body', true ), 'rating' => self::value( 'rating' ), 'consent' => self::value( 'consent' ) );
        $files = array_intersect_key( $_FILES, array_flip( array( 'photos', 'video', 'avatar' ) ) );
        $result = Petish_Customer_Reviews::submit( get_current_user_id(), $data, $files );
        if ( is_wp_error( $result ) && WC()->session ) {
            WC()->session->set( self::DRAFT_KEY, array( 'uid' => get_current_user_id(), 'product_id' => $id, 'values' => $data ) );
        }
        self::outcome( $result, __( 'Thank you. Your review and media are awaiting moderation.', 'petish-passport' ) );
        self::redirect( is_wp_error( $result ) ? self::url( array( 'pcr_product' => $id ) ) . '#pcr-composer' : self::url( array( 'pcr_status' => 'pending' ) ) . '#pcr-my-reviews' );
    }
    private static function review_return( $id ) {
        $comment = get_comment( $id );
        $pid = $comment ? (int) $comment->comment_post_ID : 0;
        return $pid && 'publish' === get_post_status( $pid ) && ! post_password_required( $pid ) ? get_permalink( $pid ) . '#pcr-review-' . absint( $id ) : self::url();
    }
    public static function handle_vote() {
        $id = absint( self::value( 'review_id' ) ); self::authorize( 'vote', $id );
        $result = Petish_Customer_Reviews::vote( get_current_user_id(), $id, self::value( 'choice' ) );
        self::outcome( $result, __( 'Your feedback has been updated.', 'petish-passport' ) );
        self::redirect( self::review_return( $id ) );
    }
    public static function handle_withdraw() {
        $id = absint( self::value( 'review_id' ) ); self::authorize( 'withdraw', $id );
        $result = '1' === self::value( 'confirm' ) ? Petish_Customer_Reviews::withdraw( get_current_user_id(), $id ) : new WP_Error( 'confirmation', __( 'Confirm that you want to withdraw this review.', 'petish-passport' ) );
        self::outcome( $result, __( 'Your review has been withdrawn from public view.', 'petish-passport' ) );
        self::redirect( self::url( array( 'pcr_status' => 'withdrawn' ) ) . '#pcr-my-reviews' );
    }
    public static function handle_report() {
        $id = absint( self::value( 'review_id' ) ); self::authorize( 'report', $id );
        $result = Petish_Customer_Reviews::report( get_current_user_id(), $id, self::value( 'reason' ) );
        self::outcome( $result, __( 'Your report has been sent to the moderation team.', 'petish-passport' ) );
        self::redirect( self::review_return( $id ) );
    }
    public static function reports_menu() {
        add_submenu_page( 'edit-comments.php', __( 'Petish review reports', 'petish-passport' ), __( 'Review reports', 'petish-passport' ), 'moderate_comments', 'petish-review-reports', array( __CLASS__, 'reports_page' ) );
    }
    public static function reports_page() {
        if ( ! current_user_can( 'moderate_comments' ) ) { wp_die( esc_html__( 'You cannot view review reports.', 'petish-passport' ), '', array( 'response' => 403 ) ); }
        $page = max( 1, min( 10000, absint( self::query( 'pcr_page', '1' ) ) ) );
        $queue = Petish_Customer_Reviews::report_queue( array( 'page' => $page, 'per_page' => 20 ) );
        echo '<div class="wrap"><h1>' . esc_html__( 'Petish review reports', 'petish-passport' ) . '</h1><p>' . esc_html__( 'Review the concern and the original comment before resolving a report. Resolving reports does not change publication status; use the comment editor to hold or remove a review when needed.', 'petish-passport' ) . '</p>';
        if ( 'resolved' === self::query( 'pcr_report_result' ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'The reports have been marked as reviewed.', 'petish-passport' ) . '</p></div>'; }
        if ( is_wp_error( $queue ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Reports could not be loaded. Please try again.', 'petish-passport' ) . '</p></div></div>'; return; }
        if ( empty( $queue['items'] ) ) { echo '<p>' . esc_html__( 'No review reports are awaiting attention.', 'petish-passport' ) . '</p></div>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Review', 'petish-passport' ) . '</th><th>' . esc_html__( 'Reports', 'petish-passport' ) . '</th><th>' . esc_html__( 'Reasons', 'petish-passport' ) . '</th><th>' . esc_html__( 'Action', 'petish-passport' ) . '</th></tr></thead><tbody>';
        $reasons = array( 'privacy' => __( 'Privacy', 'petish-passport' ), 'abuse' => __( 'Abuse or misleading content', 'petish-passport' ), 'unsafe' => __( 'Unsafe advice', 'petish-passport' ), 'other' => __( 'Other', 'petish-passport' ) );
        foreach ( $queue['items'] as $item ) {
            $id = absint( $item['id'] ?? 0 );
            echo '<tr><td><strong><a href="' . esc_url( admin_url( 'comment.php?action=editcomment&c=' . $id ) ) . '">' . esc_html( $item['title'] ?? '' ) . '</a></strong><p>' . esc_html( $item['alias'] ?? '' ) . '</p><span>' . esc_html( $item['status'] ?? '' ) . '</span></td><td>' . esc_html( number_format_i18n( (int) ( $item['reports'] ?? 0 ) ) ) . '</td><td>';
            foreach ( $reasons as $key => $label ) { $amount = (int) ( $item['reason_counts'][ $key ] ?? 0 ); if ( $amount ) { echo '<p>' . esc_html( $label . ': ' . number_format_i18n( $amount ) ) . '</p>'; } }
            echo '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="petish_customer_review_resolve_reports"><input type="hidden" name="review_id" value="' . esc_attr( $id ) . '">';
            self::nonce( 'resolve_reports', $id );
            echo '<button type="submit" class="button">' . esc_html__( 'Mark reports reviewed', 'petish-passport' ) . '</button></form></td></tr>';
        }
        echo '</tbody></table>';
        self::pagination( $queue, admin_url( 'edit-comments.php?page=petish-review-reports' ), 'pcr_page', 'wpbody-content' );
        echo '</div>';
    }
    public static function handle_resolve_reports() {
        $id = absint( self::value( 'review_id' ) );
        if ( ! is_user_logged_in() || ! current_user_can( 'moderate_comments' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( self::value( 'pcr_nonce' ), 'petish_customer_review_resolve_reports_' . $id ) ) {
            wp_die( esc_html__( 'You cannot resolve this report. Reload the reports page and try again.', 'petish-passport' ), '', array( 'response' => 403 ) );
        }
        $result = Petish_Customer_Reviews::resolve_reports( $id, get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
        self::redirect( admin_url( 'edit-comments.php?page=petish-review-reports&pcr_report_result=resolved' ) );
    }
    public static function moderation_box() {
        if ( ! current_user_can( 'moderate_comments' ) ) { return; }
        add_meta_box( 'petish-review-moderation', __( 'Petish review and media', 'petish-passport' ), array( __CLASS__, 'moderation_panel' ), 'comment', 'normal', 'high' );
    }
    public static function moderation_panel( $comment ) {
        if ( ! $comment instanceof WP_Comment || ! current_user_can( 'edit_comment', $comment->comment_ID ) || '1' !== (string) get_comment_meta( $comment->comment_ID, '_petish_review_managed', true ) ) { return; }
        $review = Petish_Customer_Reviews::review_projection( $comment, true );
        if ( ! is_array( $review ) || is_wp_error( $review ) ) { return; }
        echo '<p><strong>' . esc_html( $review['title'] ?? '' ) . '</strong></p><p>' . esc_html( sprintf( __( '%1$s · Product quality: %2$d / 5', 'petish-passport' ), $review['alias'] ?? '', (int) ( $review['rating'] ?? 0 ) ) ) . '</p>';
        echo '<p>' . esc_html__( 'Review all attached media before approving. Approval publishes the text, public alias, avatar and all attached photos/video together. Low ratings are welcome.', 'petish-passport' ) . '</p>';
        foreach ( $review['media'] ?? array() as $media ) {
            if ( 'video' === ( $media['kind'] ?? '' ) ) {
                echo '<video controls playsinline preload="none" style="max-width:480px;width:100%"><source src="' . esc_url( $media['url'] ?? '' ) . '" type="video/mp4"></video>';
            } else {
                echo '<p><img src="' . esc_url( $media['url'] ?? '' ) . '" alt="' . esc_attr( 'avatar' === ( $media['kind'] ?? '' ) ? __( 'Reviewer avatar submitted for moderation', 'petish-passport' ) : __( 'Review photo submitted for moderation', 'petish-passport' ) ) . '" style="max-width:320px;max-height:260px;object-fit:contain"></p>';
            }
        }
        echo '<p>' . esc_html__( 'Use WordPress’s comment status controls to approve, hold, mark spam or trash the review. Private review media is included in that decision.', 'petish-passport' ) . '</p>';
        $reports = Petish_Customer_Reviews::report_count( $comment->comment_ID );
        if ( $reports ) { echo '<p><strong>' . esc_html( sprintf( _n( '%d report awaits attention.', '%d reports await attention.', $reports, 'petish-passport' ), $reports ) ) . '</strong> <a href="' . esc_url( admin_url( 'edit-comments.php?page=petish-review-reports' ) ) . '">' . esc_html__( 'Open review reports', 'petish-passport' ) . '</a></p>'; }

    }
    public static function template( $name, $data = array() ) { extract( $data, EXTR_SKIP ); include dirname( __DIR__ ) . '/templates/customer-reviews-' . $name . '.php'; }
    public static function stars( $rating ) {
        $rating = max( 0, min( 5, (int) $rating ) );
        return '<span class="pcr-stars" role="img" aria-label="' . esc_attr( sprintf( __( '%d out of 5 stars', 'petish-passport' ), $rating ) ) . '"><span aria-hidden="true">' . str_repeat( '★', $rating ) . '<span class="pcr-empty-stars">' . str_repeat( '★', 5 - $rating ) . '</span></span></span>';
    }
    public static function avatar( $review ) {
        $tier = $review['tier']['key'] ?? 'member';
        $allowed = array( 'member', 'scout', 'regular', 'champion', 'legend' );
        if ( ! in_array( $tier, $allowed, true ) ) { $tier = 'member'; }
        $photo = '';
        foreach ( $review['media'] ?? array() as $media ) { if ( 'avatar' === ( $media['kind'] ?? '' ) ) { $photo = $media['url'] ?? ''; break; } }
        $alias = (string) ( $review['alias'] ?? '' );
        $initial = function_exists( 'mb_substr' ) ? mb_substr( $alias, 0, 1 ) : substr( $alias, 0, 1 );
        return '<span class="pcr-avatar pcr-tier-' . esc_attr( $tier ) . '">' . ( $photo ? '<img src="' . esc_url( $photo ) . '" alt="' . esc_attr( sprintf( __( '%s’s chosen reviewer photo', 'petish-passport' ), $alias ) ) . '" loading="lazy" decoding="async">' : '<span aria-hidden="true">' . esc_html( strtoupper( $initial ?: 'P' ) ) . '</span>' ) . '</span>';
    }
    public static function media( $entries ) {
        $entries = array_filter( (array) $entries, static function ( $m ) { return in_array( $m['kind'] ?? '', array( 'photo', 'video' ), true ) && ! empty( $m['url'] ); } );
        if ( ! $entries ) { return; }
        echo '<div class="pcr-media">';
        foreach ( $entries as $media ) {
            if ( 'video' === $media['kind'] ) {
                echo '<video controls playsinline preload="none" aria-label="' . esc_attr__( 'Customer review video', 'petish-passport' ) . '"><source src="' . esc_url( $media['url'] ) . '" type="video/mp4"><a href="' . esc_url( $media['url'] ) . '">' . esc_html__( 'Open review video', 'petish-passport' ) . '</a></video>';
            } else {
                echo '<a href="' . esc_url( $media['url'] ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr__( 'Open customer review photo', 'petish-passport' ) . '"><img src="' . esc_url( $media['url'] ) . '" alt="' . esc_attr__( 'Customer-submitted product review photo', 'petish-passport' ) . '" loading="lazy" decoding="async"></a>';
            }
        }
        echo '</div>';
    }
    public static function pagination( $data, $base, $key = 'pcr_page', $anchor = 'petish-customer-reviews' ) {
        $page = (int) ( $data['page'] ?? 1 ); $pages = (int) ( $data['pages'] ?? 1 );
        if ( $pages < 2 ) { return; }
        echo '<nav class="pcr-pagination" aria-label="' . esc_attr__( 'Review pages', 'petish-passport' ) . '">';
        if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( $key, $page - 1, $base ) . '#' . $anchor ) . '">← ' . esc_html__( 'Previous', 'petish-passport' ) . '</a>'; }
        echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'petish-passport' ), $page, $pages ) ) . '</span>';
        if ( $page < $pages ) { echo '<a href="' . esc_url( add_query_arg( $key, $page + 1, $base ) . '#' . $anchor ) . '">' . esc_html__( 'Next', 'petish-passport' ) . ' →</a>'; }
        echo '</nav>';
    }
}
