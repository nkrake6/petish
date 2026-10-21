<?php
/** Native acceptance proof using only journal-owned private staging fixtures. */
if (PHP_SAPI !== 'cli') { exit(1); }

const PETISH_CUSTOMER_REVIEWS_PROOF_CHECKS = array(
    'staging_scope_verified', 'feature_enabled', 'fixtures_created', 'guest_submission_denied',
    'rating_bounds_enforced', 'explicit_identity_required', 'consent_required', 'private_product_denied',
    'pending_submission_verified', 'pending_not_public', 'owner_isolation_verified',
    'pending_reactions_denied', 'duplicate_parent_review_denied', 'approval_publishes_review',
    'native_woocommerce_rating_updated', 'five_star_credit_verified', 'one_star_credit_verified',
    'withdrawn_native_queries_and_rest_denied', 'incomplete_review_credit_and_queries_denied',
    'native_unapproval_revokes_visibility', 'approval_idempotent', 'guest_reaction_denied',
    'self_reaction_denied', 'helpful_reaction_verified', 'repeat_vote_idempotent',
    'vote_switch_verified', 'vote_removal_verified', 'dashboard_received_counts_verified',
    'foreign_withdrawal_denied', 'withdrawal_revokes_visibility', 'withdrawal_retains_uniqueness',
    'tier_thresholds_verified', 'green_regular_reviewer_verified', 'public_identity_minimized',
    'malformed_upload_denied', 'media_limits_defined', 'media_manifest_limits_enforced', 'dashboard_rendered',
    'private_media_authorization_verified', 'private_media_projection_verified',
    'product_section_rendered', 'reviewer_form_nonce_present', 'staff_review_metadata_unchanged',
    'privacy_export_includes_spam_and_trash', 'privacy_erasure_removes_spam_and_trash',
    'privacy_erasure_removes_private_media',
    'browser_fixture_ready',
);
const PETISH_CUSTOMER_REVIEWS_CLEANUP_CHECKS = array(
    'journal_ownership_verified', 'owned_reviews_removed', 'owned_products_removed',
    'owned_users_removed', 'owned_media_removed', 'staff_review_metadata_preserved',
    'private_inputs_removed', 'fixture_cleanup_complete',
);

function petish_customer_reviews_proof_scope($work) {
    $root = '/home/u839205597/domains/staging.petish.biz/public_html';
    if (!is_string($work) || realpath($work) !== $work || is_link($work) || !is_dir($work)
        || (fileperms($work) & 0777) !== 0700 || !defined('ABSPATH') || rtrim(ABSPATH, '/') !== $root
        || realpath($root) !== $root || wp_get_environment_type() !== 'staging'
        || !defined('PETISH_STAGING_GUARD_ACTIVE') || PETISH_STAGING_GUARD_ACTIVE !== true
        || wp_parse_url(home_url('/'), PHP_URL_HOST) !== 'staging.petish.biz'
        || wp_parse_url(site_url('/'), PHP_URL_HOST) !== 'staging.petish.biz'
        || strpos($work . '/', $root . '/') === 0) {
        throw new RuntimeException('Invalid staging proof scope.');
    }
    return $root;
}

function petish_customer_reviews_proof_write($path, $value) {
    if (is_link($path) || (file_exists($path) && (!is_file($path) || realpath($path) !== $path))) {
        throw new RuntimeException('Invalid proof journal.');
    }
    $pending = $path . '.pending';
    if (file_exists($pending) || is_link($pending)) { throw new RuntimeException('Unfinished proof journal.'); }
    $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    $stream = fopen($pending, 'x+b');
    if (!$stream) { throw new RuntimeException('Proof journal could not be written.'); }
    try {
        if (!chmod($pending, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) {
            throw new RuntimeException('Proof journal could not be written.');
        }
    } finally { fclose($stream); }
    if (!rename($pending, $path)) { throw new RuntimeException('Proof journal could not be published.'); }
}

function petish_customer_reviews_proof_read($work) {
    petish_customer_reviews_proof_scope($work);
    $path = $work . '/customer-reviews-fixtures.json';
    $info = @lstat($path);
    if (!$info || is_link($path) || realpath($path) !== $path || !is_file($path)
        || ($info['mode'] & 0777) !== 0600 || $info['nlink'] !== 1 || $info['size'] > 32768) {
        throw new RuntimeException('Invalid proof journal.');
    }
    $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (($state['format'] ?? '') !== 'petish-customer-reviews-fixtures-v1'
        || ($state['work'] ?? '') !== $work || !preg_match('/^[a-f0-9]{32}$/D', $state['ownership'] ?? '')
        || !is_array($state['users'] ?? null) || !is_array($state['products'] ?? null)) {
        throw new RuntimeException('Invalid proof ownership.');
    }
    return $state;
}

function petish_customer_reviews_proof_staff_digest($excluded = array()) {
    global $wpdb;
    $where = $wpdb->prepare(' WHERE meta_key LIKE %s', $wpdb->esc_like('_petish_review_') . '%');
    if ($excluded) { $where .= ' AND post_id NOT IN (' . implode(',', array_map('absint', $excluded)) . ')'; }
    $rows = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}" . $where . ' ORDER BY post_id, meta_key, meta_id', ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) { throw new RuntimeException('Proof snapshot failed.'); }
    return hash('sha256', serialize($rows));
}

function petish_customer_reviews_proof_mark(&$report, $key, $passed) {
    if (!array_key_exists($key, $report['checks'])) { throw new RuntimeException('Unknown proof check.'); }
    $report['checks'][$key] = $passed === true;
    if ($passed !== true) { $report['failed_check'] = $key; throw new RuntimeException('Native proof check failed.'); }
}

function petish_customer_reviews_proof_capture($callback) {
    ob_start();
    try { $callback(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}

function petish_customer_reviews_proof_user($state, $role) {
    $entry = $state['users'][$role] ?? array();
    $user = get_user_by('login', $entry['login'] ?? '');
    if (!$user || (int)$user->ID !== ($entry['id'] ?? 0) || $user->user_email !== ($entry['email'] ?? '')
        || get_user_meta($user->ID, '_petish_reviews_fixture_owner', true) !== $state['ownership']) {
        throw new RuntimeException('Fixture account ownership failed.');
    }
    return $user;
}

/** The driver installs its transport/isolation guard before WordPress loads. */
function petish_customer_reviews_proof($work, $keep_for_browser = true) {
    $report = array('status' => 'failure', 'checks' => array_fill_keys(PETISH_CUSTOMER_REVIEWS_PROOF_CHECKS, false),
        'cleanup' => array(), 'failed_check' => '');
    $previous_user = get_current_user_id();
    $mail_block = static function() { return false; };
    $http_block = static function() { return new WP_Error('petish_review_proof_http', 'Outbound transport is disabled for the review proof.'); };
    add_filter('pre_wp_mail', $mail_block, PHP_INT_MAX);
    add_filter('pre_http_request', $http_block, PHP_INT_MAX);
    $state = null;
    try {
        petish_customer_reviews_proof_scope($work);
        petish_customer_reviews_proof_mark($report, 'staging_scope_verified', true);
        petish_customer_reviews_proof_mark($report, 'feature_enabled', class_exists('Petish_Customer_Reviews') && Petish_Customer_Reviews::enabled()
            && class_exists('Petish_Customer_Review_Media') && class_exists('Petish_Customer_Reviews_UI'));
        $journal = $work . '/customer-reviews-fixtures.json';
        if (file_exists($journal) || is_link($journal) || file_exists($work . '/browser-input.json')) {
            throw new RuntimeException('A previous fixture must be cleaned before another proof.');
        }
        $ownership = bin2hex(random_bytes(16)); $prefix = 'pcr-proof-' . substr($ownership, 0, 16);
        $state = array('format' => 'petish-customer-reviews-fixtures-v1', 'work' => $work,
            'ownership' => $ownership, 'prefix' => $prefix, 'users' => array(), 'products' => array(),
            'review_ids' => array(), 'staff_digest' => petish_customer_reviews_proof_staff_digest());
        foreach (array('owner', 'member', 'privacy') as $role) {
            $state['users'][$role] = array('id' => 0, 'login' => $prefix . '-' . $role,
                'email' => $prefix . '-' . $role . '@example.invalid', 'password' => wp_generate_password(32, true, true));
        }
        petish_customer_reviews_proof_write($journal, $state);
        foreach ($state['users'] as $role => $entry) {
            if (username_exists($entry['login']) || email_exists($entry['email'])) { throw new RuntimeException('Fixture account collision.'); }
            $id = wp_insert_user(array('user_login' => $entry['login'], 'user_email' => $entry['email'],
                'user_pass' => $entry['password'], 'display_name' => 'Private proof identity', 'role' => 'customer'));
            if (is_wp_error($id) || !$id) { throw new RuntimeException('Fixture account creation failed.'); }
            $state['users'][$role]['id'] = (int)$id;
            petish_customer_reviews_proof_write($journal, $state);
            if (!add_user_meta($id, '_petish_reviews_fixture_owner', $ownership, true)) { throw new RuntimeException('Fixture account marking failed.'); }
        }
        $owner = petish_customer_reviews_proof_user($state, 'owner')->ID;
        $member = petish_customer_reviews_proof_user($state, 'member')->ID;
        wp_set_current_user($owner);
        foreach (array('rated', 'critical', 'pending', 'submission', 'private') as $kind) {
            $product = $kind === 'rated' ? new WC_Product_Variable() : new WC_Product_Simple();
            $product->set_name('Petish review proof ' . $kind . ' ' . substr($ownership, 0, 8));
            $product->set_slug($prefix . '-' . $kind); $product->set_status($kind === 'private' ? 'draft' : 'publish');
            $product->set_catalog_visibility('visible'); $product->set_reviews_allowed(true);
            if ($kind !== 'rated') { $product->set_regular_price('1.00'); }
            $product->update_meta_data('_petish_reviews_fixture_owner', $ownership);
            $id = $product->save();
            if (!$id) { throw new RuntimeException('Fixture product creation failed.'); }
            $state['products'][$kind] = (int)$id;
            petish_customer_reviews_proof_write($journal, $state);
        }
        foreach (array('blue', 'pink') as $colour) {
            $variation = new WC_Product_Variation(); $variation->set_parent_id($state['products']['rated']);
            $variation->set_status('publish'); $variation->set_regular_price('1.00');
            $variation->set_attributes(array('colour' => $colour));
            $variation->update_meta_data('_petish_reviews_fixture_owner', $ownership);
            $id = $variation->save();
            if (!$id) { throw new RuntimeException('Fixture variation creation failed.'); }
            $state['products'][$colour] = (int)$id; petish_customer_reviews_proof_write($journal, $state);
        }
        WC_Product_Variable::sync($state['products']['rated']);
        petish_customer_reviews_proof_mark($report, 'fixtures_created', count($state['products']) === 7);
        $data = array('product_id' => $state['products']['blue'], 'rating' => 5,
            'alias' => 'Little Meadow Reviewer', 'title' => 'A useful synthetic product review',
            'body' => 'This is a synthetic staging review used to verify moderation and customer feedback.', 'consent' => '1');
        wp_set_current_user(0);
        petish_customer_reviews_proof_mark($report, 'guest_submission_denied', is_wp_error(Petish_Customer_Reviews::submit(0, $data)));
        wp_set_current_user($owner);
        $invalid_ratings = true;
        foreach (array(0, 6, '3.5', array(5)) as $rating) {
            $attempt = $data; $attempt['rating'] = $rating;
            $invalid_ratings = $invalid_ratings && is_wp_error(Petish_Customer_Reviews::submit($owner, $attempt));
        }
        petish_customer_reviews_proof_mark($report, 'rating_bounds_enforced', $invalid_ratings);
        $attempt = $data; $attempt['alias'] = '';
        petish_customer_reviews_proof_mark($report, 'explicit_identity_required', is_wp_error(Petish_Customer_Reviews::submit($owner, $attempt)));
        $attempt = $data; $attempt['consent'] = '';
        petish_customer_reviews_proof_mark($report, 'consent_required', is_wp_error(Petish_Customer_Reviews::submit($owner, $attempt)));
        $attempt = $data; $attempt['product_id'] = $state['products']['private'];
        petish_customer_reviews_proof_mark($report, 'private_product_denied', is_wp_error(Petish_Customer_Reviews::submit($owner, $attempt)));
        $id = Petish_Customer_Reviews::submit($owner, $data);
        if (is_wp_error($id) || !$id) { throw new RuntimeException('Native review submission failed.'); }
        $state['review_ids']['rated'] = (int)$id; petish_customer_reviews_proof_write($journal, $state);
        $comment = get_comment($id); $metrics = Petish_Customer_Reviews::metrics($owner);
        petish_customer_reviews_proof_mark($report, 'pending_submission_verified', $comment && $comment->comment_type === 'review'
            && $comment->comment_approved === '0' && (int)$comment->user_id === $owner
            && (int)$comment->comment_post_ID === $state['products']['rated'] && (int)get_comment_meta($id, 'rating', true) === 5
            && get_comment_meta($id, '_petish_review_ready', true) === '1'
            && (int)$metrics['pending'] === 1 && (int)$metrics['published'] === 0 && (int)$metrics['qualifying'] === 0);
        wp_set_current_user(0);
        $public = Petish_Customer_Reviews::public_reviews($state['products']['rated']);
        petish_customer_reviews_proof_mark($report, 'pending_not_public', empty($public['items']) && (int)$public['total'] === 0);
        wp_set_current_user($member);
        $foreign = Petish_Customer_Reviews::owner_reviews($owner);
        petish_customer_reviews_proof_mark($report, 'owner_isolation_verified', (is_wp_error($foreign) || empty(array_filter($foreign['items'])))
            && Petish_Customer_Reviews::review_projection(get_comment($id), true) === false);
        petish_customer_reviews_proof_mark($report, 'pending_reactions_denied', is_wp_error(Petish_Customer_Reviews::vote($member, $id, 'helpful')));
        wp_set_current_user($owner);
        $attempt = $data; $attempt['product_id'] = $state['products']['pink'];
        petish_customer_reviews_proof_mark($report, 'duplicate_parent_review_denied', is_wp_error(Petish_Customer_Reviews::submit($owner, $attempt)));
        if (!wp_set_comment_status($id, 'approve')) { throw new RuntimeException('Fixture approval failed.'); }
        $public = Petish_Customer_Reviews::public_reviews($state['products']['rated']);
        petish_customer_reviews_proof_mark($report, 'approval_publishes_review', (int)$public['total'] === 1 && (int)$public['items'][0]['id'] === (int)$id);
        $product = wc_get_product($state['products']['rated']);
        petish_customer_reviews_proof_mark($report, 'native_woocommerce_rating_updated', (int)$product->get_review_count() === 1
            && (float)$product->get_average_rating() === 5.0);
        $metrics = Petish_Customer_Reviews::metrics($owner);
        petish_customer_reviews_proof_mark($report, 'five_star_credit_verified', (int)$metrics['published'] === 1 && (int)$metrics['qualifying'] === 1);
        // Simulate a hold write failing after its privacy flag has been saved.
        // Warm the ordinary WordPress query cache before changing metadata.
        $public_args = array('post_id' => $state['products']['rated'], 'status' => 'approve', 'type' => 'review', 'fields' => 'ids');
        wp_set_current_user(0);
        $before_flag = array_map('intval', get_comments($public_args));
        try {
            update_comment_meta($id, Petish_Customer_Reviews::WITHDRAWN, '1');
            $after_flag = array_map('intval', get_comments($public_args));
            $request = new WP_REST_Request('GET', '/wp/v2/comments/' . $id);
            $request['id'] = $id; $request['context'] = 'view';
            $controller = new WP_REST_Comments_Controller();
            $rest = $controller->prepare_item_for_response(get_comment($id), $request);
            $withdrawn_safe = in_array((int)$id, $before_flag, true) && !in_array((int)$id, $after_flag, true)
                && get_comment($id)->comment_approved === '1' && is_wp_error($rest)
                && ($rest->get_error_data()['status'] ?? 0) === 404;
        } finally { delete_comment_meta($id, Petish_Customer_Reviews::WITHDRAWN); }
        petish_customer_reviews_proof_mark($report, 'withdrawn_native_queries_and_rest_denied', $withdrawn_safe
            && in_array((int)$id, array_map('intval', get_comments($public_args)), true));
        try {
            update_comment_meta($id, Petish_Customer_Reviews::READY, '0');
            $incomplete = Petish_Customer_Reviews::public_reviews($state['products']['rated']);
            $incomplete_metrics = Petish_Customer_Reviews::metrics($owner);
            $incomplete_safe = get_comment($id)->comment_approved === '1'
                && !in_array((int)$id, array_map('intval', get_comments($public_args)), true)
                && (int)$incomplete['total'] === 0 && (int)$incomplete['summary']['count'] === 0
                && (int)$incomplete_metrics['qualifying'] === 0 && (int)$incomplete_metrics['published'] === 0;
        } finally { update_comment_meta($id, Petish_Customer_Reviews::READY, '1'); }
        petish_customer_reviews_proof_mark($report, 'incomplete_review_credit_and_queries_denied', $incomplete_safe
            && in_array((int)$id, array_map('intval', get_comments($public_args)), true)
            && (int)Petish_Customer_Reviews::metrics($owner)['qualifying'] === 1);
        wp_set_current_user($owner);
        $attempt = $data; $attempt['product_id'] = $state['products']['critical']; $attempt['rating'] = 1;
        $attempt['title'] = 'A critical synthetic review'; $attempt['body'] = 'This one-star staging review must earn the same approved contribution credit.';
        $critical = Petish_Customer_Reviews::submit($owner, $attempt);
        if (is_wp_error($critical) || !$critical) { throw new RuntimeException('Critical fixture submission failed.'); }
        $state['review_ids']['critical'] = (int)$critical; petish_customer_reviews_proof_write($journal, $state);
        wp_set_comment_status($critical, 'approve'); $metrics = Petish_Customer_Reviews::metrics($owner);
        petish_customer_reviews_proof_mark($report, 'one_star_credit_verified', (int)$metrics['qualifying'] === 2 && (int)$metrics['published'] === 2);
        wp_set_comment_status($critical, 'hold');
        $hidden = Petish_Customer_Reviews::public_reviews($state['products']['critical']);
        petish_customer_reviews_proof_mark($report, 'native_unapproval_revokes_visibility', (int)$hidden['total'] === 0);
        wp_set_comment_status($critical, 'approve'); wp_set_comment_status($critical, 'approve');
        petish_customer_reviews_proof_mark($report, 'approval_idempotent', (int)Petish_Customer_Reviews::metrics($owner)['qualifying'] === 2);
        wp_set_current_user(0);
        petish_customer_reviews_proof_mark($report, 'guest_reaction_denied', is_wp_error(Petish_Customer_Reviews::vote(0, $id, 'helpful')));
        wp_set_current_user($owner);
        petish_customer_reviews_proof_mark($report, 'self_reaction_denied', is_wp_error(Petish_Customer_Reviews::vote($owner, $id, 'helpful')));
        wp_set_current_user($member);
        $vote = Petish_Customer_Reviews::vote($member, $id, 'helpful');
        petish_customer_reviews_proof_mark($report, 'helpful_reaction_verified', !is_wp_error($vote) && (int)$vote['helpful'] === 1 && (int)$vote['not_helpful'] === 0);
        $vote = Petish_Customer_Reviews::vote($member, $id, 'helpful');
        petish_customer_reviews_proof_mark($report, 'repeat_vote_idempotent', !is_wp_error($vote) && (int)$vote['helpful'] === 1);
        $vote = Petish_Customer_Reviews::vote($member, $id, 'not-helpful');
        petish_customer_reviews_proof_mark($report, 'vote_switch_verified', !is_wp_error($vote) && (int)$vote['helpful'] === 0 && (int)$vote['not_helpful'] === 1);
        $vote = Petish_Customer_Reviews::vote($member, $id, 'none');
        petish_customer_reviews_proof_mark($report, 'vote_removal_verified', !is_wp_error($vote) && (int)$vote['helpful'] === 0 && (int)$vote['not_helpful'] === 0);
        Petish_Customer_Reviews::vote($member, $id, 'helpful'); Petish_Customer_Reviews::vote($member, $critical, 'not-helpful');
        $member_metrics = Petish_Customer_Reviews::metrics($member); wp_set_current_user($owner);
        $owner_metrics = Petish_Customer_Reviews::metrics($owner);
        petish_customer_reviews_proof_mark($report, 'dashboard_received_counts_verified', (int)$owner_metrics['helpful'] === 1
            && (int)$owner_metrics['not_helpful'] === 1 && (int)$owner_metrics['qualifying'] === 2
            && (int)$member_metrics['helpful'] === 0 && (int)$member_metrics['not_helpful'] === 0);
        wp_set_current_user($member);
        petish_customer_reviews_proof_mark($report, 'foreign_withdrawal_denied', is_wp_error(Petish_Customer_Reviews::withdraw($member, $critical)));
        wp_set_current_user($owner); $withdrawn = Petish_Customer_Reviews::withdraw($owner, $critical);
        $hidden = Petish_Customer_Reviews::public_reviews($state['products']['critical']); $metrics = Petish_Customer_Reviews::metrics($owner);
        petish_customer_reviews_proof_mark($report, 'withdrawal_revokes_visibility', $withdrawn === true && (int)$hidden['total'] === 0 && (int)$metrics['not_helpful'] === 0);
        $attempt = $data; $attempt['product_id'] = $state['products']['critical'];
        petish_customer_reviews_proof_mark($report, 'withdrawal_retains_uniqueness', is_wp_error(Petish_Customer_Reviews::submit($owner, $attempt)));
        $tiers = array(); foreach (array(0, 49, 50, 99, 100, 249, 250, 499, 500) as $count) { $tiers[$count] = Petish_Customer_Reviews::tier($count); }
        petish_customer_reviews_proof_mark($report, 'tier_thresholds_verified', (int)$tiers[49]['threshold'] === 0
            && (int)$tiers[50]['threshold'] === 50 && (int)$tiers[99]['threshold'] === 50
            && (int)$tiers[100]['threshold'] === 100 && (int)$tiers[249]['threshold'] === 100
            && (int)$tiers[250]['threshold'] === 250 && (int)$tiers[499]['threshold'] === 250 && (int)$tiers[500]['threshold'] === 500);
        petish_customer_reviews_proof_mark($report, 'green_regular_reviewer_verified', $tiers[100]['title'] === 'Regular Reviewer'
            && preg_match('/green|#(?:4d9866|237a45|a8dfb8)/i', $tiers[100]['color']) === 1);
        $project = Petish_Customer_Reviews::review_projection(get_comment($id));
        $serialized = wp_json_encode($project);
        petish_customer_reviews_proof_mark($report, 'public_identity_minimized', ($project['alias'] ?? '') === $data['alias']
            && strpos($serialized, $state['users']['owner']['email']) === false
            && strpos($serialized, $state['users']['owner']['login']) === false
            && strpos($serialized, 'Private proof identity') === false
            && !array_intersect(array('email', 'user_email', 'user_id', 'comment_author_email'), array_keys($project)));
        $bad_upload = array('photos' => array('name' => array('danger.svg'), 'type' => array('image/svg+xml'),
            'tmp_name' => array('/nonexistent/review-upload'), 'error' => array(UPLOAD_ERR_OK), 'size' => array(50)));
        petish_customer_reviews_proof_mark($report, 'malformed_upload_denied', is_wp_error(Petish_Customer_Review_Media::save_uploads($bad_upload, $owner)));
        petish_customer_reviews_proof_mark($report, 'media_limits_defined', Petish_Passport_Video_Inspector::MAX_SECONDS === 20
            && Petish_Passport_Video_Inspector::MAX_BYTES === 20 * 1024 * 1024
            && Petish_Customer_Review_Media::MAX_PHOTOS === 4 && Petish_Customer_Review_Media::MAX_PHOTO_BYTES === 5 * 1024 * 1024
            && Petish_Customer_Review_Media::MAX_VIDEO_BYTES === 20 * 1024 * 1024);
        // Live HTTP upload/serve proof runs in the separate owned browser fixture.
        petish_customer_reviews_proof_media($state, $report, $id, $owner, $member);
        $privacy_uid = petish_customer_reviews_proof_user($state, 'privacy')->ID;
        wp_set_current_user($privacy_uid);
        foreach (array('spam' => 'rated', 'trash' => 'critical') as $status => $kind) {
            $privacy_data = $data; $privacy_data['product_id'] = $state['products'][$kind];
            $privacy_data['alias'] = 'Privacy proof reviewer'; $privacy_data['title'] = 'Synthetic privacy ' . $status . ' review';
            $privacy_id = Petish_Customer_Reviews::submit($privacy_uid, $privacy_data);
            if (is_wp_error($privacy_id) || !$privacy_id) { throw new RuntimeException('Privacy fixture review creation failed.'); }
            $state['review_ids']['privacy_' . $status] = (int)$privacy_id;
            petish_customer_reviews_proof_write($journal, $state);
            if ($status === 'spam') {
                wp_set_comment_status($privacy_id, 'approve');
                petish_customer_reviews_proof_media($state, $report, $privacy_id, $privacy_uid, $member);
                $privacy_manifest = get_comment_meta($privacy_id, Petish_Customer_Review_Media::META, true);
                $privacy_path = Petish_Customer_Review_Media::file_for_owner($privacy_manifest[0]['key'], $privacy_uid);
            }
            if (!wp_set_comment_status($privacy_id, $status)) { throw new RuntimeException('Privacy fixture status failed.'); }
        }
        $export = Petish_Customer_Reviews::export($state['users']['privacy']['email']);
        $export_ids = array_column($export['data'], 'item_id');
        petish_customer_reviews_proof_mark($report, 'privacy_export_includes_spam_and_trash', $export['done'] === true
            && in_array('review-' . $state['review_ids']['privacy_spam'], $export_ids, true)
            && in_array('review-' . $state['review_ids']['privacy_trash'], $export_ids, true));
        $erased = Petish_Customer_Reviews::erase($state['users']['privacy']['email']);
        petish_customer_reviews_proof_mark($report, 'privacy_erasure_removes_spam_and_trash', $erased['done'] === true
            && $erased['items_retained'] === false && $erased['items_removed'] === true
            && get_comment($state['review_ids']['privacy_spam']) === null && get_comment($state['review_ids']['privacy_trash']) === null);
        petish_customer_reviews_proof_mark($report, 'privacy_erasure_removes_private_media', $privacy_path && !file_exists($privacy_path)
            && is_wp_error(Petish_Customer_Review_Media::resolve($state['review_ids']['privacy_spam'], 0, $privacy_uid)));
        wp_set_current_user($owner);
        $attempt = $data; $attempt['product_id'] = $state['products']['pending']; $attempt['title'] = 'A pending browser fixture';
        $pending = Petish_Customer_Reviews::submit($owner, $attempt);
        if (is_wp_error($pending) || !$pending) { throw new RuntimeException('Pending fixture submission failed.'); }
        $state['review_ids']['pending'] = (int)$pending; petish_customer_reviews_proof_write($journal, $state);
        wp_set_current_user($owner);
        $before_get = $_GET; $before_product = $GLOBALS['product'] ?? null; $before_post = $GLOBALS['post'] ?? null;
        try {
            $_GET['pcr_product'] = (string)$state['products']['submission'];
            $dashboard = petish_customer_reviews_proof_capture(static function() { do_action('woocommerce_account_my-reviews_endpoint'); });
            $GLOBALS['product'] = wc_get_product($state['products']['submission']);
            $GLOBALS['post'] = get_post($state['products']['submission']);
            $product_html = petish_customer_reviews_proof_capture(array('Petish_Customer_Reviews_UI', 'product'));
        } finally {
            $_GET = $before_get; $GLOBALS['product'] = $before_product; $GLOBALS['post'] = $before_post;
        }
        petish_customer_reviews_proof_mark($report, 'dashboard_rendered', strpos($dashboard, 'Published reviews') !== false
            && strpos($dashboard, 'Pending') !== false && strpos($dashboard, 'Helpful') !== false && strpos($dashboard, 'Not helpful') !== false);
        petish_customer_reviews_proof_mark($report, 'product_section_rendered', strpos($product_html, 'petish-customer-reviews') !== false);
        petish_customer_reviews_proof_mark($report, 'reviewer_form_nonce_present', strpos($dashboard, 'name="pcr_nonce"') !== false);
        petish_customer_reviews_proof_mark($report, 'staff_review_metadata_unchanged', $state['staff_digest'] === petish_customer_reviews_proof_staff_digest(array_values($state['products'])));
        $input = array('format' => 'petish-customer-reviews-browser-v1', 'url' => 'https://staging.petish.biz',
            'owner' => $state['users']['owner'], 'member' => $state['users']['member'],
            'product_id' => $state['products']['rated'], 'pending_product_id' => $state['products']['pending'],
            'submission_product_id' => $state['products']['submission'], 'approved_review_id' => (int)$id,
            'pending_review_id' => (int)$pending, 'product_url' => get_permalink($state['products']['rated']),
            'pending_product_url' => get_permalink($state['products']['pending']),
            'submission_product_url' => get_permalink($state['products']['submission']),
            'account_url' => add_query_arg('my-reviews', '1', wc_get_page_permalink('myaccount')));
        petish_customer_reviews_proof_write($work . '/browser-input.json', $input);
        petish_customer_reviews_proof_mark($report, 'browser_fixture_ready', true);
        $report['status'] = 'success';
    } catch (Throwable $error) {
        $report['status'] = 'failure';
    } finally {
        wp_set_current_user($previous_user);
        if ($state !== null && (!$keep_for_browser || $report['status'] !== 'success')) {
            $report['cleanup'] = petish_customer_reviews_cleanup($work);
        }
        remove_filter('pre_wp_mail', $mail_block, PHP_INT_MAX);
        remove_filter('pre_http_request', $http_block, PHP_INT_MAX);
    }
    return $report;
}

/** Media checks use only files linked to the fixture's approved comment. */
function petish_customer_reviews_proof_media($state, &$report, $id, $owner, $member) {
    $base = dirname(rtrim(ABSPATH, '/')) . '/private';
    $paths = array($base, $base . '/petish-passport', $base . '/petish-passport/customer-reviews', $base . '/petish-passport/customer-reviews/' . $owner);
    foreach ($paths as $path) {
        if (is_link($path) || (!is_dir($path) && (!mkdir($path, 0700) || !chmod($path, 0700)))
            || realpath($path) !== $path) { throw new RuntimeException('Private fixture media path is not safe.'); }
    }
    $name = bin2hex(random_bytes(24)) . '.png';
    $key = $owner . '/' . $name; $path = end($paths) . '/' . $name;
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAEklEQVR4nGPoPz/rPzJmIF0AAESmL3GjpJ8GAAAAAElFTkSuQmCC', true);
    if (!add_user_meta($owner, Petish_Customer_Review_Media::JOURNAL, array('key' => $key, 'created' => time()), false)) {
        throw new RuntimeException('Private fixture media journal failed.');
    }
    $stream = fopen($path, 'x+b');
    if (!$stream) { throw new RuntimeException('Private fixture media creation failed.'); }
    try {
        if (!chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) {
            throw new RuntimeException('Private fixture media creation failed.');
        }
    } finally { fclose($stream); }
    $entry = array('key' => $key, 'kind' => 'photo', 'mime' => 'image/png', 'bytes' => strlen($bytes), 'width' => 4, 'height' => 4);
    $manifest = array($entry);
    update_comment_meta($id, Petish_Customer_Review_Media::META, $manifest);
    if (get_comment_meta($id, Petish_Customer_Review_Media::META, true) !== $manifest
        || Petish_Customer_Review_Media::commit_manifest($manifest, $owner) !== true) {
        throw new RuntimeException('Private fixture media attachment failed.');
    }
    $too_many = array();
    for ($i = 0; $i < 5; $i++) { $extra = $entry; $extra['key'] = $owner . '/' . str_repeat(dechex($i + 1), 48) . '.png'; $too_many[] = $extra; }
    $too_large = $entry; $too_large['bytes'] = 5 * 1024 * 1024 + 1;
    $too_long = array('key' => $owner . '/' . str_repeat('a', 48) . '.mp4', 'kind' => 'video', 'mime' => 'video/mp4',
        'bytes' => 128, 'width' => 16, 'height' => 16, 'duration' => 20.01);
    petish_customer_reviews_proof_mark($report, 'media_manifest_limits_enforced', Petish_Customer_Review_Media::valid_manifest($manifest, $owner)
        && !Petish_Customer_Review_Media::valid_manifest($too_many, $owner)
        && !Petish_Customer_Review_Media::valid_manifest(array($too_large), $owner)
        && !Petish_Customer_Review_Media::valid_manifest(array($too_long), $owner));
    wp_set_current_user(0);
    $published = Petish_Customer_Review_Media::resolve($id, 0, 0);
    wp_set_comment_status($id, 'hold');
    $guest = Petish_Customer_Review_Media::resolve($id, 0, 0);
    $foreign = Petish_Customer_Review_Media::resolve($id, 0, $member);
    $own = Petish_Customer_Review_Media::resolve($id, 0, $owner);
    petish_customer_reviews_proof_mark($report, 'private_media_authorization_verified', !is_wp_error($published)
        && $published['path'] === $path && is_wp_error($guest) && is_wp_error($foreign)
        && !is_wp_error($own) && $own['path'] === $path
        && is_wp_error(Petish_Customer_Review_Media::resolve($id, 6, $owner)));
    $public_projection = Petish_Customer_Review_Media::project(get_comment($id));
    wp_set_current_user($owner);
    $owner_projection = Petish_Customer_Review_Media::project(get_comment($id), true);
    $serialized = wp_json_encode($owner_projection);
    petish_customer_reviews_proof_mark($report, 'private_media_projection_verified', $public_projection === array()
        && count($owner_projection) === 1 && !isset($owner_projection[0]['key']) && !isset($owner_projection[0]['path'])
        && strpos($serialized, $name) === false && strpos($serialized, '/private/') === false
        && wp_parse_url($owner_projection[0]['url'], PHP_URL_HOST) === 'staging.petish.biz');
    wp_set_comment_status($id, 'approve');
}

function petish_customer_reviews_cleanup($work) {
    $result = array('status' => 'failure', 'checks' => array_fill_keys(PETISH_CUSTOMER_REVIEWS_CLEANUP_CHECKS, false));
    $previous_user = get_current_user_id();
    try {
        petish_customer_reviews_proof_scope($work);
        if (!file_exists($work . '/customer-reviews-fixtures.json') && !is_link($work . '/customer-reviews-fixtures.json')
            && !file_exists($work . '/browser-input.json') && !is_link($work . '/browser-input.json')) {
            // A successful earlier cleanup has already retired the private journal.
            return array('status' => 'success', 'checks' => array_fill_keys(PETISH_CUSTOMER_REVIEWS_CLEANUP_CHECKS, true));
        }
        $state = petish_customer_reviews_proof_read($work);
        $result['checks']['journal_ownership_verified'] = true;
        $uids = array();
        foreach ($state['users'] as $role => $entry) {
            $user = get_user_by('login', $entry['login']);
            if (!$user) { continue; }
            // A crash immediately after insertion is recoverable by the private planned identity.
            if ($user->user_email !== $entry['email'] || ($entry['id'] && (int)$user->ID !== $entry['id'])
                || !in_array('customer', (array)$user->roles, true)
                || !in_array(get_user_meta($user->ID, '_petish_reviews_fixture_owner', true), array('', $state['ownership']), true)) {
                throw new RuntimeException('Fixture account ownership failed.');
            }
            $uids[] = (int)$user->ID;
        }
        $products = array_values($state['products']);
        // Product save and journal persistence cannot be one transaction. The
        // opaque ownership marker recovers a save interrupted between those steps.
        $marked = get_posts(array('post_type' => array('product', 'product_variation'), 'post_status' => 'any',
            'posts_per_page' => 30, 'fields' => 'ids', 'meta_key' => '_petish_reviews_fixture_owner', 'meta_value' => $state['ownership']));
        if (count($marked) >= 30) { throw new RuntimeException('Unexpected fixture product count.'); }
        $products = array_values(array_unique(array_merge($products, array_map('intval', $marked))));
        foreach ($products as $id) {
            $post = get_post($id); if (!$post) { continue; }
            if (!in_array($post->post_type, array('product', 'product_variation'), true)
                || get_post_meta($id, '_petish_reviews_fixture_owner', true) !== $state['ownership']) {
                throw new RuntimeException('Fixture product ownership failed.');
            }
        }
        foreach ($uids as $uid) {
            $comments = get_comments(array('user_id' => $uid, 'status' => array('approve', 'hold', 'spam', 'trash'), 'number' => 100, 'orderby' => 'comment_ID', 'order' => 'ASC', 'petish_reviews_private' => true));
            if (count($comments) >= 100) { throw new RuntimeException('Unexpected fixture review count.'); }
            foreach ($comments as $comment) {
                if (!in_array((int)$comment->comment_post_ID, $products, true) || $comment->comment_type !== 'review') {
                    throw new RuntimeException('Unexpected fixture comment ownership.');
                }
                if (!wp_delete_comment($comment->comment_ID, true)) { throw new RuntimeException('Fixture review cleanup failed.'); }
            }
            if ((int)get_comments(array('user_id' => $uid, 'status' => array('approve', 'hold', 'spam', 'trash'), 'count' => true, 'petish_reviews_private' => true)) !== 0) {
                throw new RuntimeException('Fixture reviews remain.');
            }
        }
        $result['checks']['owned_reviews_removed'] = true;
        foreach (array_reverse($products) as $id) { if (get_post($id) && !wp_delete_post($id, true)) { throw new RuntimeException('Fixture product cleanup failed.'); } }
        foreach ($products as $id) { if (get_post($id)) { throw new RuntimeException('Fixture product remains.'); } }
        $result['checks']['owned_products_removed'] = true;
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($uids as $uid) {
            Petish_Customer_Review_Media::cleanup_user($uid);
            if (!wp_delete_user($uid)) { throw new RuntimeException('Fixture account cleanup failed.'); }
        }
        foreach ($state['users'] as $entry) { if (get_user_by('login', $entry['login'])) { throw new RuntimeException('Fixture account remains.'); } }
        $result['checks']['owned_users_removed'] = true;
        $media_root = dirname(rtrim(ABSPATH, '/')) . '/private/petish-passport/customer-reviews';
        foreach ($state['users'] as $entry) {
            $path = $media_root . '/' . (int)$entry['id'];
            if ($entry['id'] && (file_exists($path) || is_link($path))) { throw new RuntimeException('Fixture media remains.'); }
        }
        $result['checks']['owned_media_removed'] = true;
        if ($state['staff_digest'] !== petish_customer_reviews_proof_staff_digest()) { throw new RuntimeException('Existing staff review state changed.'); }
        $result['checks']['staff_review_metadata_preserved'] = true;
        $input = $work . '/browser-input.json';
        if (file_exists($input)) {
            if (is_link($input) || realpath($input) !== $input || !unlink($input)) { throw new RuntimeException('Private fixture input cleanup failed.'); }
        }
        $result['checks']['private_inputs_removed'] = true;
        if (!unlink($work . '/customer-reviews-fixtures.json')) { throw new RuntimeException('Fixture journal cleanup failed.'); }
        $result['checks']['fixture_cleanup_complete'] = true;
        $result['status'] = 'success';
    } catch (Throwable $error) {
        $result['status'] = 'failure';
    } finally { wp_set_current_user($previous_user); }
    return $result;
}
