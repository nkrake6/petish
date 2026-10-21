<?php
/** Moderated customer reviews. Independent of staff species-suitability reviews. */
defined('ABSPATH') || exit;

final class Petish_Customer_Reviews {
    const MANAGED = '_petish_review_managed';
    const META_MANAGED = '_petish_review_managed';
    const READY = '_petish_review_ready';
    const TITLE = '_petish_review_title';
    const VERIFIED = '_petish_review_verified';
    const VARIANT = '_petish_review_variant';
    const MEDIA = '_petish_review_media';
    const WITHDRAWN = '_petish_review_withdrawn';
    const VOTE_PREFIX = 'petish_review_vote_';
    const REPORT_PREFIX = 'petish_review_report_';
    const DELETING = '_petish_reviews_deleting';
    private static $internal = false;
    private static $locks = array();
    private static $booted = false;

    public static function enabled() {
        return 'yes' === get_option('petish_customer_reviews_enabled', 'no');
    }

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        add_action('transition_comment_status', array(__CLASS__, 'status_changed'), 30, 3);
        add_action('edit_comment', array(__CLASS__, 'comment_changed'), 30, 1);
        add_action('deleted_comment', array(__CLASS__, 'comment_deleted'), 30, 2);
        if (self::enabled()) {
            add_filter('pre_comment_approved', array(__CLASS__, 'native_submission_guard'), 100, 2);
            add_filter('rest_pre_insert_comment', array(__CLASS__, 'rest_submission_guard'), 100, 2);
        }
        add_filter('wp_update_comment_data', array(__CLASS__, 'update_guard'), 100, 3);
        add_filter('rest_prepare_comment', array(__CLASS__, 'rest_identity'), 100, 3);
        add_filter('comments_clauses', array(__CLASS__, 'public_query_guard'), 100, 2);
        foreach (array('added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta') as $hook) add_action($hook, array(__CLASS__, 'comment_meta_changed'), 10, 4);
        foreach (array('added_user_meta', 'updated_user_meta', 'deleted_user_meta') as $hook) add_action($hook, array(__CLASS__, 'identity_meta_changed'), 10, 4);
        add_filter('get_avatar', array(__CLASS__, 'avatar'), 100, 6);
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'delete_user'), 1);
        add_action('wpmu_delete_user', array(__CLASS__, 'delete_user'), 1);
        foreach (array(self::MANAGED, self::READY, self::TITLE, self::VERIFIED, self::VARIANT, self::MEDIA, self::WITHDRAWN) as $key) {
            register_meta('comment', $key, array('show_in_rest' => false, 'auth_callback' => static function () { return current_user_can('moderate_comments'); }));
        }
    }

    private static function error($code, $message) {
        return new WP_Error('petish_reviews_' . $code, __($message, 'petish-passport'));
    }
    private static function id($value) {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $value) ? (int) $value : 0;
    }
    private static function actor($uid) {
        return self::id($uid) && get_user_by('id', (int) $uid) && !get_user_meta((int) $uid, self::DELETING, true);
    }
    public static function managed($comment) {
        $comment = is_object($comment) ? $comment : get_comment(self::id($comment));
        return $comment && 'review' === $comment->comment_type && '1' === (string) get_comment_meta($comment->comment_ID, self::MANAGED, true);
    }
    private static function with_lock($scope, $callback) {
        global $wpdb;
        $name = 'petish-cr-' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->prefix . ':' . $scope), 0, 48);
        if (isset(self::$locks[$name])) return $callback();
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $name))) return self::error('busy', 'Your review activity is being updated. Please try again.');
        self::$locks[$name] = true;
        if (preg_match('/\Aactor:([1-9][0-9]*)\z/D', $scope, $actor)) {
            // A lock waiter must not trust identity cached before account deletion.
            clean_user_cache((int) $actor[1]);
            wp_cache_delete((int) $actor[1], 'user_meta');
        }
        try { return $callback(); }
        finally { unset(self::$locks[$name]); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }
    private static function product($id, $accepting = true) {
        $product = self::id($id) ? wc_get_product((int) $id) : false;
        if (!$product) return false;
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || 'publish' !== $parent->get_status() || !in_array($parent->get_type(), array('simple', 'variable'), true)
            || '' !== (string) get_post_field('post_password', $parent->get_id()) || ($accepting && !$parent->get_reviews_allowed())) return false;
        if ($product->is_type('variation') && 'publish' !== $product->get_status()) return false;
        return array('parent' => $parent, 'variant' => $product->is_type('variation') ? $product->get_id() : 0);
    }
    public static function is_public($comment) {
        $comment = is_object($comment) ? $comment : get_comment(self::id($comment));
        return self::managed($comment) && '1' === (string) get_comment_meta($comment->comment_ID, self::READY, true) && '1' === (string) $comment->comment_approved
            && !get_comment_meta($comment->comment_ID, self::WITHDRAWN, true)
            && self::actor((int) $comment->user_id) && false !== self::product((int) $comment->comment_post_ID, false);
    }
    private static function normalise($data) {
        if (!is_array($data)) return self::error('input', 'Check your review and try again.');
        $out = array();
        foreach (array('alias' => 40, 'title' => 100, 'body' => 3000) as $name => $limit) {
            if (!isset($data[$name]) || !is_string($data[$name])) return self::error('input', 'Enter a public reviewer name, title and review.');
            $value = trim('body' === $name ? sanitize_textarea_field($data[$name]) : sanitize_text_field($data[$name]));
            if ('' === $value || !preg_match('/\A.{1,' . $limit . '}\z/usD', $value)) return self::error('length', 'Keep your reviewer name to 40 characters, title to 100, and review to 3,000.');
            $out[$name] = $value;
        }
        if ((!is_int($data['rating'] ?? null) && !is_string($data['rating'] ?? null)) || !preg_match('/\A[1-5]\z/D', (string) $data['rating'])) return self::error('rating', 'Choose a product quality rating from 1 to 5 stars.');
        if (!in_array($data['consent'] ?? null, array(true, 1, '1'), true)) return self::error('consent', 'Confirm that you want your review and approved attachments published.');
        $out['rating'] = (int) $data['rating'];
        return $out;
    }
    private static function meta_saved($id, $key, $value) {
        update_comment_meta($id, $key, $value);
        $saved = get_comment_meta($id, $key, true);
        return is_array($value) ? $saved === $value : (string) $saved === (string) $value;
    }

    /** Trusted PHP API: the UI owns current-user binding, CSRF and POST checks. */
    public static function submit($uid, $data, $files = array()) {
        $uid = self::id($uid);
        if (!self::enabled() || !$uid || !self::actor($uid)) return self::error('signin', 'Sign in to submit a review.');
        $clean = self::normalise($data);
        if (is_wp_error($clean)) return $clean;
        $found = self::product($data['product_id'] ?? 0);
        if (!$found) return self::error('product', 'This product is not available for a review.');
        if (!is_array($files)) return self::error('files', 'Check your attachments and try again.');
        return self::with_lock('actor:' . $uid, static function () use ($uid, $clean, $found, $files) {
            global $wpdb;
            if (!self::actor($uid)) return self::error('signin', 'This account is not available for reviews.');
            $product_id = (int) $found['parent']->get_id();
            $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT c.comment_ID FROM {$wpdb->comments} c WHERE c.user_id=%d AND c.comment_post_ID=%d AND c.comment_type='review' AND EXISTS (SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1') LIMIT 1", $uid, $product_id, self::MANAGED));
            if ($existing) return self::error('duplicate', 'You already have a review for this product. Each product can earn review credit once, including its sizes and colours.');
            $recent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.user_id=%d AND c.comment_date_gmt>=%s AND EXISTS (SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1')", $uid, gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), self::MANAGED));
            if ($recent >= 5) return self::error('limit', 'You can submit up to five reviews in 24 hours. Please try again later.');
            $media = array();
            if (class_exists('Petish_Customer_Review_Media')) {
                $media = Petish_Customer_Review_Media::save_uploads($files, $uid);
                if (is_wp_error($media)) return $media;
            } elseif ($files) return self::error('media', 'Attachments are temporarily unavailable. Please try again.');
            $user = get_user_by('id', $uid);
            $verified = function_exists('wc_customer_bought_product') && wc_customer_bought_product($user->user_email, $uid, $found['variant'] ?: $product_id);
            $id = wp_insert_comment(array(
                'comment_post_ID' => $product_id, 'user_id' => $uid, 'comment_type' => 'review',
                'comment_author' => $clean['alias'], 'comment_author_email' => '',
                'comment_author_url' => '', 'comment_author_IP' => '', 'comment_agent' => '',
                'comment_content' => $clean['body'], 'comment_parent' => 0, 'comment_approved' => 0,
                'comment_date' => current_time('mysql'), 'comment_date_gmt' => current_time('mysql', true),
            ));
            if (!$id) {
                if ($media) Petish_Customer_Review_Media::delete_manifest($media, $uid);
                return self::error('save', 'Your review could not be saved. Please try again.');
            }
            $values = array(self::MANAGED => '1', self::TITLE => $clean['title'], 'rating' => $clean['rating'], self::VERIFIED => $verified ? '1' : '0',
                'verified' => $verified ? '1' : '0', self::VARIANT => $found['variant'], self::MEDIA => $media, self::READY => '1');
            foreach ($values as $key => $value) {
                if (!self::meta_saved($id, $key, $value)) {
                    // Hold is already private. Keep the comment if filesystem cleanup fails,
                    // retaining its media manifest for an administrator to retry safely.
                    $removed = !$media || Petish_Customer_Review_Media::delete_manifest($media, $uid);
                    if ($removed) wp_delete_comment($id, true);
                    return self::error('save', 'Your review could not be completed. Please contact the store before retrying.');
                }
            }
            if ($media && method_exists('Petish_Customer_Review_Media', 'commit_manifest')) Petish_Customer_Review_Media::commit_manifest($media, $uid);
            self::refresh($product_id);
            return (int) $id;
        });
    }

    public static function vote($uid, $review_id, $choice) {
        $uid = self::id($uid); $review_id = self::id($review_id);
        if (!self::enabled() || !$uid || !self::actor($uid)) return self::error('signin', 'Sign in to react to a review.');
        if (!in_array($choice, array('helpful', 'not-helpful', 'none'), true)) return self::error('vote', 'Choose Helpful or Not helpful.');
        return self::with_lock('actor:' . $uid, static function () use ($uid, $review_id, $choice) {
            if (!self::actor($uid)) return self::error('signin', 'This account is not available.');
            $comment = get_comment($review_id);
            if (!self::is_public($comment)) return self::error('unavailable', 'This review is not available for reactions.');
            if ((int) $comment->user_id === $uid) return self::error('self_vote', 'You cannot react to your own review.');
            $key = self::VOTE_PREFIX . $review_id;
            if ('none' === $choice) delete_user_meta($uid, $key);
            else update_user_meta($uid, $key, $choice);
            wp_cache_delete($uid, 'user_meta');
            $saved = get_user_meta($uid, $key, true);
            if (('none' === $choice && '' !== $saved) || ('none' !== $choice && $choice !== $saved)) return self::error('vote_save', 'Your reaction could not be saved. Please try again.');
            clean_comment_cache($review_id);
            if (!self::is_public(get_comment($review_id))) {
                delete_user_meta($uid, $key);
                return self::error('unavailable', 'This review is no longer available.');
            }
            $counts = self::reaction_counts($review_id);
            $counts['own_vote'] = 'none' === $choice ? '' : $choice;
            return $counts;
        });
    }
    public static function reaction_counts($review_id) {
        global $wpdb;
        $comment = get_comment(self::id($review_id));
        if (!self::is_public($comment)) return array('helpful' => 0, 'not_helpful' => 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT CASE WHEN v.meta_value='helpful' THEN v.user_id END) helpful, COUNT(DISTINCT CASE WHEN v.meta_value='not-helpful' THEN v.user_id END) not_helpful FROM {$wpdb->usermeta} v INNER JOIN {$wpdb->users} u ON u.ID=v.user_id WHERE v.meta_key=%s AND v.user_id<>%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} d WHERE d.user_id=v.user_id AND d.meta_key=%s AND d.meta_value<>'')", self::VOTE_PREFIX . $comment->comment_ID, (int) $comment->user_id, self::DELETING), ARRAY_A);
        return array('helpful' => max(0, (int) ($row['helpful'] ?? 0)), 'not_helpful' => max(0, (int) ($row['not_helpful'] ?? 0)));
    }
    public static function report($uid, $review_id, $reason = 'other') {
        $uid = self::id($uid); $review_id = self::id($review_id);
        if (!self::enabled() || !$uid || !self::actor($uid)) return self::error('signin', 'Sign in to report a review.');
        if (!in_array($reason, array('privacy', 'abuse', 'unsafe', 'other'), true)) return self::error('reason', 'Choose a report reason.');
        return self::with_lock('actor:' . $uid, static function () use ($uid, $review_id, $reason) {
            if (!self::actor($uid)) return self::error('signin', 'This account is not available.');
            if (!self::is_public($review_id)) return self::error('unavailable', 'This review is not available.');
            $key = self::REPORT_PREFIX . $review_id;
            $value = array('reason' => $reason, 'created' => gmdate('c'));
            update_user_meta($uid, $key, $value);
            return $value === get_user_meta($uid, $key, true) ? true : self::error('report_save', 'Your report could not be saved. Please try again.');
        });
    }
    public static function report_count($review_id) {
        global $wpdb;
        return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s", self::REPORT_PREFIX . self::id($review_id))));
    }

    /** Staff-only report queue; individual reporter identities are never projected. */
    public static function report_queue($filters = array()) {
        global $wpdb;
        if (!current_user_can('moderate_comments')) return self::error('moderation', 'You cannot manage review reports.');
        $pages = self::page_args($filters);
        $where = $wpdb->prepare("FROM {$wpdb->comments} c INNER JOIN {$wpdb->usermeta} r ON r.meta_key=CONCAT(%s,c.comment_ID) WHERE c.comment_type='review' AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1')", self::REPORT_PREFIX, self::MANAGED);
        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT c.comment_ID) " . $where);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT c.comment_ID id, COUNT(DISTINCT r.user_id) reports " . $where . " GROUP BY c.comment_ID ORDER BY MAX(r.umeta_id) DESC LIMIT %d OFFSET %d", $pages['per_page'], ($pages['page'] - 1) * $pages['per_page']), ARRAY_A);
        $items = array();
        foreach ((array) $rows as $row) {
            $comment = get_comment((int) $row['id']); if (!$comment) continue;
            $counts = array();
            foreach (array('privacy', 'abuse', 'unsafe', 'other') as $reason) {
                $pattern = $wpdb->esc_like('"reason";s:' . strlen($reason) . ':"' . $reason . '"') . '%';
                $counts[$reason] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value LIKE %s", self::REPORT_PREFIX . $comment->comment_ID, '%' . $pattern));
            }
            $items[] = array('id' => (int) $comment->comment_ID, 'alias' => $comment->comment_author,
                'title' => (string) get_comment_meta($comment->comment_ID, self::TITLE, true),
                'product_id' => (int) $comment->comment_post_ID, 'status' => (string) $comment->comment_approved,
                'reports' => (int) $row['reports'], 'reason_counts' => $counts);
        }
        return array_merge($pages, array('items' => $items, 'total' => $total, 'pages' => (int) ceil($total / $pages['per_page'])));
    }
    public static function resolve_reports($review_id, $moderator_id) {
        $review_id = self::id($review_id);
        if (!current_user_can('moderate_comments') || get_current_user_id() !== self::id($moderator_id)) return self::error('moderation', 'You cannot manage review reports.');
        if (!self::managed($review_id)) return self::error('unavailable', 'This review is not available.');
        return self::with_lock('reports:' . $review_id, static function () use ($review_id) {
            delete_metadata('user', 0, self::REPORT_PREFIX . $review_id, '', true);
            return 0 === self::report_count($review_id) ? true : self::error('reports_changed', 'Some reports remain or a new report arrived. Please check the review again.');
        });
    }

    public static function withdraw($uid, $review_id) {
        $uid = self::id($uid); $review_id = self::id($review_id);
        if (!self::enabled() || !$uid || !self::actor($uid)) return self::error('signin', 'Sign in to manage your review.');
        return self::with_lock('actor:' . $uid, static function () use ($uid, $review_id) {
            if (!self::actor($uid)) return self::error('signin', 'This account is not available.');
            $comment = get_comment($review_id);
            if (!self::managed($comment) || (int) $comment->user_id !== $uid) return self::error('owner', 'This review is not available in your account.');
            if (!self::meta_saved($review_id, self::WITHDRAWN, '1')) return self::error('withdraw', 'Your review could not be withdrawn. Please try again.');
            self::$internal = true;
            try { $result = wp_set_comment_status($review_id, 'hold', true); }
            finally { self::$internal = false; }
            self::refresh($comment->comment_post_ID);
            if (is_wp_error($result) || false === $result) return self::error('withdraw', 'Your review is hidden, but its moderation status needs a store administrator to check it.');
            return true;
        });
    }

    public static function tier($qualifying) {
        $count = max(0, (int) $qualifying);
        $tiers = array(
            array('key' => 'member', 'title' => 'New reviewer', 'color' => '#64748b', 'threshold' => 0),
            array('key' => 'scout', 'title' => 'Pet Scout', 'color' => '#30b7c6', 'threshold' => 50),
            array('key' => 'regular', 'title' => 'Regular Reviewer', 'color' => '#4d9866', 'threshold' => 100),
            array('key' => 'champion', 'title' => 'Community Champion', 'color' => '#d974a7', 'threshold' => 250),
            array('key' => 'legend', 'title' => 'Petish Legend', 'color' => '#b78926', 'threshold' => 500),
        );
        $index = 0;
        foreach ($tiers as $i => $tier) if ($count >= $tier['threshold']) $index = $i;
        return array_merge($tiers[$index], array('count' => $count, 'next_threshold' => $tiers[$index + 1]['threshold'] ?? null, 'next_title' => $tiers[$index + 1]['title'] ?? '', 'benefits_active' => false));
    }
    /** SQL aggregates avoid loading every review or reaction into a request. */
    public static function metrics($uid) {
        global $wpdb;
        $uid = self::id($uid);
        $result = array('published' => 0, 'pending' => 0, 'withdrawn' => 0, 'helpful' => 0, 'not_helpful' => 0, 'qualifying' => 0);
        if (!$uid || !self::actor($uid)) { $result['tier'] = self::tier(0); return $result; }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT c.comment_approved status, EXISTS(SELECT 1 FROM {$wpdb->commentmeta} w WHERE w.comment_id=c.comment_ID AND w.meta_key=%s AND w.meta_value<>'') withdrawn, COUNT(DISTINCT c.comment_post_ID) total FROM {$wpdb->comments} c WHERE c.user_id=%d AND c.comment_type='review' AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1') AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} ready WHERE ready.comment_id=c.comment_ID AND ready.meta_key='_petish_review_ready' AND ready.meta_value='1') GROUP BY c.comment_approved, withdrawn", self::WITHDRAWN, $uid, self::MANAGED), ARRAY_A);
        foreach ((array) $rows as $row) {
            if ((int) $row['withdrawn']) $result['withdrawn'] += (int) $row['total'];
            elseif ('1' === (string) $row['status']) $result['published'] += (int) $row['total'];
            elseif ('0' === (string) $row['status']) $result['pending'] += (int) $row['total'];
        }
        $result['qualifying'] = $result['published'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT CASE WHEN v.meta_value='helpful' THEN CONCAT(c.comment_ID,':',v.user_id) END) helpful, COUNT(DISTINCT CASE WHEN v.meta_value='not-helpful' THEN CONCAT(c.comment_ID,':',v.user_id) END) not_helpful FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID INNER JOIN {$wpdb->usermeta} v ON v.meta_key=CONCAT(%s,c.comment_ID) INNER JOIN {$wpdb->users} u ON u.ID=v.user_id WHERE c.user_id=%d AND c.comment_type='review' AND c.comment_approved='1' AND p.post_status='publish' AND p.post_password='' AND v.user_id<>c.user_id AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1') AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} ready WHERE ready.comment_id=c.comment_ID AND ready.meta_key='_petish_review_ready' AND ready.meta_value='1') AND NOT EXISTS(SELECT 1 FROM {$wpdb->commentmeta} w WHERE w.comment_id=c.comment_ID AND w.meta_key=%s AND w.meta_value<>'') AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} d WHERE d.user_id=v.user_id AND d.meta_key=%s AND d.meta_value<>'')", self::VOTE_PREFIX, $uid, self::MANAGED, self::WITHDRAWN, self::DELETING), ARRAY_A);
        $result['helpful'] = max(0, (int) ($row['helpful'] ?? 0)); $result['not_helpful'] = max(0, (int) ($row['not_helpful'] ?? 0));
        $result['tier'] = self::tier($result['qualifying']);
        return $result;
    }
    private static function page_args($filters) {
        $filters = is_array($filters) ? $filters : array();
        return array('page' => max(1, min(100000, (int) ($filters['page'] ?? 1))), 'per_page' => max(1, min(20, (int) ($filters['per_page'] ?? 10))));
    }
    private static function managed_query() {
        return array('relation' => 'AND', array('key' => self::MANAGED, 'value' => '1'), array('key' => self::READY, 'value' => '1'), array('relation' => 'OR', array('key' => self::WITHDRAWN, 'compare' => 'NOT EXISTS'), array('key' => self::WITHDRAWN, 'value' => '', 'compare' => '=')));
    }
    private static function summary($product_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT CAST(r.meta_value AS UNSIGNED) rating, COUNT(DISTINCT c.comment_ID) total FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} r ON r.comment_id=c.comment_ID AND r.meta_key='rating' INNER JOIN {$wpdb->users} u ON u.ID=c.user_id WHERE c.comment_post_ID=%d AND c.comment_type='review' AND c.comment_approved='1' AND r.meta_value IN ('1','2','3','4','5') AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key=%s AND m.meta_value='1') AND EXISTS(SELECT 1 FROM {$wpdb->commentmeta} ready WHERE ready.comment_id=c.comment_ID AND ready.meta_key='_petish_review_ready' AND ready.meta_value='1') AND NOT EXISTS(SELECT 1 FROM {$wpdb->commentmeta} w WHERE w.comment_id=c.comment_ID AND w.meta_key=%s AND w.meta_value<>'') AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} d WHERE d.user_id=c.user_id AND d.meta_key=%s AND d.meta_value<>'') GROUP BY CAST(r.meta_value AS UNSIGNED)", $product_id, self::MANAGED, self::WITHDRAWN, self::DELETING), ARRAY_A);
        $summary = array('count' => 0, 'average' => 0.0, 'distribution' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0)); $sum = 0;
        foreach ((array) $rows as $row) { $rating = (int) $row['rating']; $n = (int) $row['total']; $summary['distribution'][$rating] = $n; $summary['count'] += $n; $sum += $rating * $n; }
        if ($summary['count']) $summary['average'] = round($sum / $summary['count'], 1);
        return $summary;
    }
    public static function public_reviews($product_id, $filters = array()) {
        $pages = self::page_args($filters); $product = self::product($product_id, false);
        $empty = array_merge($pages, array('items' => array(), 'total' => 0, 'pages' => 0, 'summary' => array('count' => 0, 'average' => 0.0, 'distribution' => array(1=>0,2=>0,3=>0,4=>0,5=>0))));
        if (!$product) return $empty;
        $product_id = $product['parent']->get_id(); $meta = self::managed_query();
        $rating = (int) ($filters['rating'] ?? 0); if ($rating >= 1 && $rating <= 5) $meta[] = array('key' => 'rating', 'value' => (string) $rating);
        if (!empty($filters['verified'])) $meta[] = array('key' => self::VERIFIED, 'value' => '1');
        $media = $filters['media'] ?? 'all';
        if (in_array($media, array('photos','video'), true)) $meta[] = array('key' => self::MEDIA, 'value' => '"kind";s:' . ('photos' === $media ? '5:"photo"' : '5:"video"'), 'compare' => 'LIKE');
        $sort = $filters['sort'] ?? 'newest'; $args = array('post_id' => $product_id, 'type' => 'review', 'status' => 'approve', 'parent' => 0,
            'number' => $pages['per_page'], 'offset' => ($pages['page'] - 1) * $pages['per_page'], 'meta_query' => $meta, 'orderby' => 'comment_date_gmt', 'order' => 'oldest' === $sort ? 'ASC' : 'DESC');
        if (in_array($sort, array('highest', 'lowest'), true)) { $args['meta_key'] = 'rating'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'lowest' === $sort ? 'ASC' : 'DESC'; }
        $args['no_found_rows'] = false;
        $query = new WP_Comment_Query($args); $items = array();
        foreach ((array) $query->comments as $comment) if (self::is_public($comment)) $items[] = self::review_projection($comment);
        $total = (int) $query->found_comments;
        return array_merge($pages, array('items' => $items, 'total' => $total, 'pages' => (int) ceil($total / $pages['per_page']), 'summary' => self::summary($product_id)));
    }
    public static function owner_reviews($uid, $filters = array()) {
        $uid = self::id($uid); $pages = self::page_args($filters);
        $empty = array_merge($pages, array('items' => array(), 'total' => 0, 'pages' => 0));
        if (!$uid || !self::actor($uid) || (get_current_user_id() !== $uid && !current_user_can('moderate_comments') && !current_user_can('manage_woocommerce'))) return $empty;
        $status = $filters['status'] ?? 'all'; $meta = array(array('key' => self::MANAGED, 'value' => '1'));
        $args = array('petish_reviews_private' => true, 'user_id' => $uid, 'type' => 'review', 'status' => array('approve', 'hold', 'spam', 'trash'), 'parent' => 0, 'number' => $pages['per_page'], 'offset' => ($pages['page'] - 1) * $pages['per_page'], 'orderby' => 'comment_date_gmt', 'order' => 'DESC');
        if (in_array($status, array('published', 'pending'), true)) { $args['status'] = 'published' === $status ? 'approve' : 'hold'; $meta = self::managed_query(); }
        elseif ('withdrawn' === $status) $meta[] = array('key' => self::WITHDRAWN, 'value' => '1');
        $args['meta_query'] = $meta; $args['no_found_rows'] = false; $query = new WP_Comment_Query($args); $items = array();
        foreach ((array) $query->comments as $comment) $items[] = self::review_projection($comment, true);
        $total = (int) $query->found_comments;
        return array_merge($pages, array('items' => $items, 'total' => $total, 'pages' => (int) ceil($total / $pages['per_page'])));
    }
    public static function review_projection($comment, $owner = false) {
        $comment = is_object($comment) ? $comment : get_comment(self::id($comment));
        if (!self::managed($comment)) return false;
        $uid = (int) $comment->user_id;
        $privileged = $owner && ((int) get_current_user_id() === $uid || current_user_can('moderate_comments') || current_user_can('manage_woocommerce'));
        if (!$privileged && !self::is_public($comment)) return false;
        $status = get_comment_meta($comment->comment_ID, self::WITHDRAWN, true) ? 'withdrawn' : ('1' === (string) $comment->comment_approved ? 'published' : ('0' === (string) $comment->comment_approved ? 'pending' : 'rejected'));
        $metrics = self::metrics($uid); $product = wc_get_product($comment->comment_post_ID); $counts = self::reaction_counts($comment->comment_ID);
        $viewer = get_current_user_id(); $date = $comment->comment_date_gmt . ' UTC';
        return array('id' => (int) $comment->comment_ID, 'product_id' => (int) $comment->comment_post_ID, 'product_name' => $product ? $product->get_name() : __('Product no longer available', 'petish-passport'),
            'product_url' => $product && 'publish' === $product->get_status() ? get_permalink($product->get_id()) : '',
            'title' => (string) get_comment_meta($comment->comment_ID, self::TITLE, true), 'body' => $comment->comment_content,
            'alias' => $comment->comment_author, 'rating' => max(1, min(5, (int) get_comment_meta($comment->comment_ID, 'rating', true))),
            'status' => $status, 'date' => gmdate('c', strtotime($date)), 'date_label' => wp_date(get_option('date_format'), strtotime($date)),
            'verified' => '1' === (string) get_comment_meta($comment->comment_ID, self::VERIFIED, true),
            'media' => class_exists('Petish_Customer_Review_Media') ? Petish_Customer_Review_Media::project($comment, $privileged) : array(),
            'tier' => $metrics['tier'], 'review_count' => $metrics['qualifying'], 'helpful' => $counts['helpful'], 'not_helpful' => $counts['not_helpful'],
            'own_vote' => $viewer ? (string) get_user_meta($viewer, self::VOTE_PREFIX . $comment->comment_ID, true) : '',
            'can_vote' => $viewer && (int) $viewer !== $uid && self::is_public($comment));
    }

    private static function refresh($product_id) {
        if (class_exists('WC_Comments')) WC_Comments::clear_transients((int) $product_id);
        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients((int) $product_id);
        do_action('litespeed_purge_post', (int) $product_id);
    }
    public static function status_changed($new, $old, $comment) {
        if (!self::managed($comment)) return;
        if (in_array((string) $new, array('approved', 'approve', '1'), true)
            && (get_comment_meta($comment->comment_ID, self::WITHDRAWN, true) || '1' !== (string) get_comment_meta($comment->comment_ID, self::READY, true))) {
            // An owner withdrawal or interrupted submission is not moderator consent.
            // Re-hold before this request can make its response public.
            self::$internal = true;
            try { wp_set_comment_status($comment->comment_ID, 'hold', true); }
            finally { self::$internal = false; }
        }
        self::refresh($comment->comment_post_ID);
    }
    public static function comment_changed($id) {
        $comment = get_comment($id); if (self::managed($comment)) self::refresh($comment->comment_post_ID);
    }
    public static function comment_meta_changed($meta_id, $comment_id, $key, $value) {
        if (in_array($key, array(self::MANAGED, self::READY, self::WITHDRAWN, self::MEDIA, self::VERIFIED), true)) {
            // Query caches include the global comment epoch, not comment metadata.
            // A privacy flag must invalidate previously cached public ID lists.
            clean_comment_cache((int) $comment_id);
        }
    }
    public static function identity_meta_changed($meta_id, $user_id, $key, $value) {
        if (self::DELETING === $key) wp_cache_set_comments_last_changed();
    }
    public static function comment_deleted($id, $comment = null) {
        // The review's metadata is already gone; exact numeric action keys cannot
        // remove reactions belonging to any other review or Passport activity.
        delete_metadata('user', 0, self::VOTE_PREFIX . (int) $id, '', true);
        delete_metadata('user', 0, self::REPORT_PREFIX . (int) $id, '', true);
        if ($comment && 'review' === $comment->comment_type) self::refresh($comment->comment_post_ID);
    }
    public static function native_submission_guard($approved, $data) {
        if (self::enabled() && !self::$internal && !current_user_can('moderate_comments') && 'product' === get_post_type((int) ($data['comment_post_ID'] ?? 0))) return self::error('form', 'Please use the Petish review form in My Account to submit your product review.');
        return $approved;
    }
    public static function rest_submission_guard($prepared, $request) {
        if (is_wp_error($prepared)) return $prepared;
        $post_id = is_object($prepared) ? ($prepared->comment_post_ID ?? 0) : ($prepared['comment_post_ID'] ?? 0);
        if (self::enabled() && !current_user_can('moderate_comments') && 'product' === get_post_type((int) $post_id)) return self::error('form', 'Please use the Petish review form in My Account.');
        return $prepared;
    }
    public static function update_guard($data, $comment = array(), $raw = array()) {
        if (is_wp_error($data) || self::$internal || current_user_can('moderate_comments') || current_user_can('manage_woocommerce')) return $data;
        $id = (int) ($data['comment_ID'] ?? ($comment['comment_ID'] ?? 0));
        return self::managed($id) ? self::error('moderation', 'Review changes must be checked by the store before publication.') : $data;
    }
    /** Also protects native comments, feeds and Woo Store API on a failed hold write. */
    public static function public_query_guard($clauses, $query) {
        global $wpdb;
        if (($query->query_vars['petish_reviews_private'] ?? false) === true
            || (is_admin() && current_user_can('moderate_comments'))) return $clauses;
        $comments = $wpdb->comments;
        $clauses['where'] .= $wpdb->prepare(" AND (NOT EXISTS(SELECT 1 FROM {$wpdb->commentmeta} pgm WHERE pgm.comment_id={$comments}.comment_ID AND pgm.meta_key=%s AND pgm.meta_value='1') OR (EXISTS(SELECT 1 FROM {$wpdb->commentmeta} pgr WHERE pgr.comment_id={$comments}.comment_ID AND pgr.meta_key=%s AND pgr.meta_value='1') AND NOT EXISTS(SELECT 1 FROM {$wpdb->commentmeta} pgw WHERE pgw.comment_id={$comments}.comment_ID AND pgw.meta_key=%s AND pgw.meta_value<>'') AND EXISTS(SELECT 1 FROM {$wpdb->users} pgu WHERE pgu.ID={$comments}.user_id) AND NOT EXISTS(SELECT 1 FROM {$wpdb->usermeta} pgd WHERE pgd.user_id={$comments}.user_id AND pgd.meta_key=%s AND pgd.meta_value<>'') AND EXISTS(SELECT 1 FROM {$wpdb->posts} pgp WHERE pgp.ID={$comments}.comment_post_ID AND pgp.post_type='product' AND pgp.post_status='publish' AND pgp.post_password='')))", self::MANAGED, self::READY, self::WITHDRAWN, self::DELETING);
        return $clauses;
    }

    public static function rest_identity($response, $comment, $request) {
        if (!self::managed($comment) || (isset($request['context']) && 'edit' === $request['context'] && current_user_can('moderate_comments'))) return $response;
        if (!self::is_public($comment) && get_current_user_id() !== (int) $comment->user_id) return new WP_Error('rest_comment_invalid_id', 'Invalid comment ID.', array('status' => 404));
        if (is_object($response) && method_exists($response, 'get_data')) {
            $data = $response->get_data(); $data['author'] = 0; $data['author_url'] = ''; $data['author_avatar_urls'] = array();
            unset($data['author_email'], $data['author_ip'], $data['author_user_agent']);
            $response->set_data($data);
            if (method_exists($response, 'remove_link')) $response->remove_link('author');
        }
        return $response;
    }
    public static function avatar($html, $id_or_email, $size = 96, $default = '', $alt = '', $args = array()) {
        if (!is_object($id_or_email) || !isset($id_or_email->comment_ID) || !self::managed($id_or_email)) return $html;
        $initial = function_exists('mb_substr') ? mb_substr($id_or_email->comment_author, 0, 1) : substr($id_or_email->comment_author, 0, 1);
        return '<span class="petish-review-initial" aria-hidden="true">' . esc_html(strtoupper($initial)) . '</span>';
    }

    public static function exporters($items) { $items['petish-customer-reviews'] = array('exporter_friendly_name' => 'Petish customer reviews', 'callback' => array(__CLASS__, 'export')); return $items; }
    public static function erasers($items) { $items['petish-customer-reviews'] = array('eraser_friendly_name' => 'Petish customer reviews', 'callback' => array(__CLASS__, 'erase')); return $items; }
    public static function export($email, $page = 1) {
        global $wpdb;
        $user = get_user_by('email', $email); if (!$user) return array('data' => array(), 'done' => true);
        $page = max(1, (int) $page); $limit = 50; $offset = ($page - 1) * $limit; $data = array();
        $comments = get_comments(array('petish_reviews_private' => true, 'user_id' => $user->ID, 'type' => 'review', 'status' => array('approve', 'hold', 'spam', 'trash'), 'number' => $limit + 1, 'offset' => $offset, 'orderby' => 'comment_ID', 'order' => 'ASC', 'meta_key' => self::MANAGED, 'meta_value' => '1'));
        foreach (array_slice($comments, 0, $limit) as $c) $data[] = array('group_id' => 'petish-customer-reviews', 'group_label' => 'Product reviews', 'item_id' => 'review-' . $c->comment_ID, 'data' => array(
            array('name' => 'Public reviewer name', 'value' => $c->comment_author), array('name' => 'Title', 'value' => (string) get_comment_meta($c->comment_ID, self::TITLE, true)), array('name' => 'Review', 'value' => $c->comment_content), array('name' => 'Rating', 'value' => (string) get_comment_meta($c->comment_ID, 'rating', true)), array('name' => 'Product ID', 'value' => (string) $c->comment_post_ID), array('name' => 'Moderation status', 'value' => (string) $c->comment_approved)));
        $actions = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND (meta_key LIKE %s OR meta_key LIKE %s) ORDER BY umeta_id LIMIT %d OFFSET %d", $user->ID, $wpdb->esc_like(self::VOTE_PREFIX) . '%', $wpdb->esc_like(self::REPORT_PREFIX) . '%', $limit + 1, $offset), ARRAY_A);
        foreach (array_slice((array) $actions, 0, $limit) as $action) {
            $report = str_starts_with($action['meta_key'], self::REPORT_PREFIX); $value = $report ? maybe_unserialize($action['meta_value']) : $action['meta_value'];
            $data[] = array('group_id' => 'petish-customer-reviews', 'group_label' => 'Product reviews', 'item_id' => $action['meta_key'], 'data' => array(array('name' => 'Interaction', 'value' => $report ? 'Report' : 'Reaction'), array('name' => 'Details', 'value' => $report && is_array($value) ? (string) ($value['reason'] ?? '') : (string) $value)));
        }
        return array('data' => $data, 'done' => count($comments) <= $limit && count((array) $actions) <= $limit);
    }
    private static function erase_actor($uid, $limit = 50) {
        global $wpdb;
        return self::with_lock('actor:' . $uid, static function () use ($uid, $limit, $wpdb) {
            $comments = get_comments(array('petish_reviews_private' => true, 'user_id' => $uid, 'type' => 'review', 'status' => array('approve', 'hold', 'spam', 'trash'), 'number' => $limit, 'orderby' => 'comment_ID', 'order' => 'ASC', 'meta_key' => self::MANAGED, 'meta_value' => '1'));
            $removed = false; $retained = false;
            foreach ($comments as $comment) {
                // Revocation precedes filesystem removal and permanent comment deletion.
                if (!self::meta_saved($comment->comment_ID, self::WITHDRAWN, '1')) { $retained = true; continue; }
                if ('1' === (string) $comment->comment_approved) {
                    self::$internal = true;
                    try { $held = wp_set_comment_status($comment->comment_ID, 'hold', true); }
                    finally { self::$internal = false; }
                    if (is_wp_error($held) || false === $held) { $retained = true; continue; }
                }
                $manifest = get_comment_meta($comment->comment_ID, self::MEDIA, true);
                if (is_array($manifest) && $manifest && class_exists('Petish_Customer_Review_Media') && !Petish_Customer_Review_Media::delete_manifest($manifest, $uid)) { $retained = true; continue; }
                if (wp_delete_comment($comment->comment_ID, true)) $removed = true; else $retained = true;
            }
            $keys = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id=%d AND (meta_key LIKE %s OR meta_key LIKE %s) LIMIT %d", $uid, $wpdb->esc_like(self::VOTE_PREFIX) . '%', $wpdb->esc_like(self::REPORT_PREFIX) . '%', $limit));
            foreach ((array) $keys as $key) { delete_user_meta($uid, $key); if (get_user_meta($uid, $key, true) !== '') $retained = true; else $removed = true; }
            $left = (int) get_comments(array('petish_reviews_private' => true, 'user_id' => $uid, 'type' => 'review', 'status' => array('approve', 'hold', 'spam', 'trash'), 'count' => true, 'meta_key' => self::MANAGED, 'meta_value' => '1'));
            $actions_left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id=%d AND (meta_key LIKE %s OR meta_key LIKE %s)", $uid, $wpdb->esc_like(self::VOTE_PREFIX) . '%', $wpdb->esc_like(self::REPORT_PREFIX) . '%'));
            if (!$left && class_exists('Petish_Customer_Review_Media') && !Petish_Customer_Review_Media::cleanup_user($uid)) $retained = true;
            return array('items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array('Some review data needs another removal attempt.') : array(), 'done' => !$left && !$actions_left);
        });
    }
    public static function erase($email, $page = 1) {
        $user = get_user_by('email', $email);
        if (!$user) return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
        $result = self::erase_actor((int) $user->ID);
        return is_wp_error($result) ? array('items_removed' => false, 'items_retained' => true, 'messages' => array($result->get_error_message()), 'done' => true) : $result;
    }
    public static function delete_user($uid) {
        $uid = self::id($uid); if (!$uid) return;
        $result = self::with_lock('actor:' . $uid, static function () use ($uid) {
            update_user_meta($uid, self::DELETING, '1');
            if ('1' !== get_user_meta($uid, self::DELETING, true)) return self::error('erase', 'Review activity could not be paused. Please retry account deletion.');
            $result = self::erase_actor($uid, 5000);
            return is_wp_error($result) || !$result['done'] || $result['items_retained'] ? self::error('erase', 'Some review data could not be removed. Please use the privacy eraser before retrying account deletion.') : true;
        });
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), '', array('response' => 500));
    }
}
