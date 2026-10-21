<?php
/** Native staging directory proof. Only the two journal-owned fixture accounts may be mutated. */
if (PHP_SAPI !== 'cli') exit(1);

function petish_club_verify_directory($state) {
    if (wp_get_environment_type() !== 'staging' || !is_array($state) || ($state['kind'] ?? '') !== 'native') petish_club_verify_fail();
    petish_club_verify_fixture_owned($state, $state['run'] ?? '', 'native');
    foreach (array('owner', 'member') as $role) {
        $identity = $state['users'][$role]; $user = get_user_by('id', $identity['id']);
        if (!$user || (int) $user->ID !== $identity['id'] || $user->user_login !== $identity['login']
            || $user->user_email !== $identity['email']
            || get_user_meta($user->ID, '_petish_club_rehearsal_ownership', true) !== $state['ownership']) petish_club_verify_fail();
    }
    $owner = $state['users']['owner']['id']; $member = $state['users']['member']['id'];
    if ($owner === $member || count(Petish_Passport_Store::all_for_user($owner)) + 12 > Petish_Passport_Store::MAX_PETS
        || count(Petish_Passport_Store::all_for_user($member)) + 1 > Petish_Passport_Store::MAX_PETS
        || Petish_Passport_Community::pending_index_count() !== 0) petish_club_verify_fail();
    $previous_user = get_current_user_id(); $previous_phase = $GLOBALS['petish_club_fixture_phase'] ?? 'fixture';
    $prefix = 'ClubDir-' . substr(hash('sha256', $state['ownership'] . '|' . $state['run']), 0, 12) . '-';
    $pets = array();
    $directory = static function($filters, $guest = false) {
        $result = Petish_Passport_Community::directory($filters);
        if (!is_array($result) || ($result['error'] ?? '') !== '' || ($result['indexing'] ?? true) !== false
            || !is_array($result['profiles'] ?? null) || !is_array($result['pagination'] ?? null)
            || (! $guest && ($result['login_required'] ?? true) !== false)) petish_club_verify_fail();
        return $result;
    };
    $tokens = static function($profiles) { $tokens = array_column($profiles, 'token'); sort($tokens); return $tokens; };
    try {
        wp_set_current_user($owner);
        $empty = $directory(array('name' => $prefix));
        if ($empty['profiles'] !== array() || $empty['pagination']['total'] !== 0) petish_club_verify_fail();
        $GLOBALS['petish_club_fixture_phase'] = 'pet_creation';
        for ($i = 0; $i < 13; $i++) {
            $uid = $i === 12 ? $member : $owner; wp_set_current_user($uid);
            $name = $prefix . ($i === 0 ? 'Literal_%' : ($i === 3 ? 'LiteralAfoo' : sprintf('Pet %02d', $i)));
            $species = $i === 12 ? 'hamster' : 'rabbit'; $variety = $i === 12 ? 'syrian' : 'mini-lop';
            $pet = Petish_Passport_Store::create($uid, array('name' => $name, 'species' => $species,
                'variety' => $variety, 'age' => '1-3', 'interest' => 'browsing', 'bio' => 'Synthetic directory proof.', 'traits' => array()));
            if (is_wp_error($pet) || ($pet['photo'] ?? null) !== '') petish_club_verify_fail();
            $post = get_post($pet['id']);
            if (!$post || (int) $post->post_author !== $uid || $post->post_type !== Petish_Passport_Store::TYPE || $post->post_status !== 'private') petish_club_verify_fail();
            $GLOBALS['petish_club_fixture_phase'] = 'sharing';
            $shared = Petish_Passport_Community::publish($pet['id'], $uid, false, array());
            if (is_wp_error($shared) || !$shared['shared'] || $shared['profile']['moments'] !== array()) petish_club_verify_fail();
            $pets[] = array('id' => $pet['id'], 'uid' => $uid, 'token' => $shared['token'], 'name' => $name);
            $GLOBALS['petish_club_fixture_phase'] = 'pet_creation';
        }
        petish_club_verify_mark('directory_fixture_scope_verified', count($pets) === 13 && count(array_unique(array_column($pets, 'id'))) === 13);

        wp_set_current_user($owner); $GLOBALS['petish_club_fixture_phase'] = 'sharing';
        $changed = Petish_Passport_Store::update($pets[1]['id'], $owner, array('name' => $prefix . 'Private updated',
            'species' => 'guinea-pig', 'variety' => 'abyssinian', 'age' => '1-3', 'interest' => 'browsing'));
        if (is_wp_error($changed)) petish_club_verify_fail();
        $frozen = $directory(array('name' => $pets[1]['name'], 'species' => 'rabbit', 'variety' => 'MINI LOP'));
        $private_name = $directory(array('name' => $prefix . 'Private updated'));
        $private_species = $directory(array('name' => $prefix, 'species' => 'guinea-pig'));
        $private_variety = $directory(array('name' => $prefix, 'variety' => 'Abyssinian'));
        petish_club_verify_mark('directory_public_snapshot_filters_verified', count($frozen['profiles']) === 1
            && $frozen['profiles'][0]['token'] === $pets[1]['token'] && $frozen['profiles'][0]['variety'] === 'Mini Lop'
            && $private_name['profiles'] === array() && $private_species['profiles'] === array() && $private_variety['profiles'] === array());
        $reshared = Petish_Passport_Community::publish($pets[1]['id'], $owner, false, array());
        if (is_wp_error($reshared)) petish_club_verify_fail(); $pets[1]['token'] = $reshared['token'];
        $updated = $directory(array('name' => $prefix . 'Private updated', 'species' => 'guinea-pig', 'variety' => 'ABYSSINIAN'));
        $old = $directory(array('name' => $pets[1]['name']));
        petish_club_verify_mark('directory_explicit_reshare_filters_verified', count($updated['profiles']) === 1
            && $updated['profiles'][0]['token'] === $pets[1]['token'] && $old['profiles'] === array());

        // Simulate only this owned fixture's legacy missing search fields. Never
        // remove or rebuild an existing member's metadata inside the proof.
        $changed = Petish_Passport_Store::update($pets[2]['id'], $owner, array('name' => $prefix . 'Unshared legacy edit',
            'species' => 'hamster', 'variety' => 'syrian', 'age' => '1-3', 'interest' => 'browsing'));
        if (is_wp_error($changed) || Petish_Passport_Community::pending_index_count() !== 0) petish_club_verify_fail();
        foreach (array(Petish_Passport_Community::INDEX_NAME, Petish_Passport_Community::INDEX_SPECIES, Petish_Passport_Community::INDEX_VARIETY) as $key) delete_post_meta($pets[2]['id'], $key);
        if (Petish_Passport_Community::pending_index_count() !== 1) petish_club_verify_fail();
        $backfill = Petish_Passport_Community::backfill_index(1);
        $legacy = $directory(array('name' => $pets[2]['name'], 'species' => 'rabbit', 'variety' => 'Mini Lop'));
        $legacy_private = $directory(array('name' => $prefix . 'Unshared legacy edit'));
        petish_club_verify_mark('directory_legacy_snapshot_index_verified', !is_wp_error($backfill)
            && $backfill === array('processed' => 1, 'remaining' => false) && count($legacy['profiles']) === 1
            && $legacy['profiles'][0]['token'] === $pets[2]['token'] && $legacy_private['profiles'] === array()
            && get_post_meta($pets[2]['id'], Petish_Passport_Community::INDEX_SPECIES, true) === 'rabbit'
            && get_post_meta($pets[2]['id'], Petish_Passport_Community::INDEX_VARIETY, true) === 'mini lop');
        $literal = $directory(array('name' => $prefix . 'Literal_%'));
        petish_club_verify_mark('directory_literal_search_verified', count($literal['profiles']) === 1 && $literal['profiles'][0]['token'] === $pets[0]['token']);

        $first = $directory(array('name' => $prefix, 'page' => 1)); $second = $directory(array('name' => $prefix, 'page' => 2));
        $expected = array_column($pets, 'token'); sort($expected);
        $seen = $tokens(array_merge($first['profiles'], $second['profiles']));
        petish_club_verify_mark('directory_pagination_verified', count($first['profiles']) === 12 && count($second['profiles']) === 1
            && $first['pagination']['total'] === 13 && $second['pagination']['total'] === 13
            && $first['pagination']['per_page'] === 12 && $first['pagination']['pages'] === 2
            && $first['pagination']['has_prev'] === false && $first['pagination']['has_next'] === true
            && $second['pagination']['has_prev'] === true && $second['pagination']['has_next'] === false
            && count(array_unique($seen)) === 13 && $seen === $expected);

        $GLOBALS['petish_club_fixture_phase'] = 'hearts'; wp_set_current_user($member);
        foreach (array(0, 1) as $i) if (is_wp_error(Petish_Passport_Community::set_heart($pets[$i]['token'], $member, true))) petish_club_verify_fail();
        $repeat = Petish_Passport_Community::set_heart($pets[0]['token'], $member, true);
        if (is_wp_error($repeat) || $repeat['hearts'] !== 1) petish_club_verify_fail();
        $member_view = $directory(array('view' => 'favourites', 'name' => $prefix, 'user_id' => $owner, 'uid' => $owner));
        wp_set_current_user($owner);
        if (is_wp_error(Petish_Passport_Community::set_heart($pets[12]['token'], $owner, true))) petish_club_verify_fail();
        $owner_view = $directory(array('view' => 'favourites', 'name' => $prefix, 'user_id' => $member));
        wp_set_current_user(0); $guest = $directory(array('view' => 'favourites', 'name' => $prefix, 'user_id' => $member), true);
        $member_expected = array($pets[0]['token'], $pets[1]['token']); sort($member_expected);
        petish_club_verify_mark('favourites_session_isolation_verified', $tokens($member_view['profiles']) === $member_expected
            && $member_view['pagination']['total'] === 2 && count(array_filter($member_view['profiles'], static fn($pet) => $pet['hearted'] === true && $pet['hearts'] === 1)) === 2
            && count($owner_view['profiles']) === 1 && $owner_view['profiles'][0]['token'] === $pets[12]['token']
            && $guest['login_required'] === true && $guest['profiles'] === array() && $guest['pagination']['total'] === 0);

        $request = Petish_Passport_Community::directory_request(array('club_view' => 'favourites', 'club_species' => 'rabbit',
            'club_variety' => 'Mini Lop', 'club_name' => $prefix, 'club_page' => '2', 'user_id' => (string) $owner, 'return_url' => 'https://example.invalid/'));
        $return = Petish_Passport_Community::directory_url($request, $request['page']);
        $parts = wp_parse_url($return); $site = wp_parse_url(home_url('/')); $query = array(); parse_str($parts['query'] ?? '', $query);
        petish_club_verify_mark('directory_return_url_verified', ($parts['scheme'] ?? null) === ($site['scheme'] ?? null)
            && ($parts['host'] ?? null) === ($site['host'] ?? null) && ($parts['path'] ?? '/') === ($site['path'] ?? '/')
            && $query === array('petish_club' => '1', 'club_view' => 'favourites', 'club_species' => 'rabbit',
                'club_variety' => 'Mini Lop', 'club_name' => $prefix, 'club_page' => '2'));

        $GLOBALS['petish_club_fixture_phase'] = 'moderation'; wp_set_current_user($owner);
        if (Petish_Passport_Community::unpublish($pets[0]['id'], $owner) !== true) petish_club_verify_fail();
        $manager = wp_get_current_user(); $caps = $manager->allcaps; $manager->allcaps['manage_woocommerce'] = true;
        try { $hidden = Petish_Passport_Community::moderate($pets[1]['id'], 'hide'); }
        finally { $manager->allcaps = $caps; }
        if ($hidden !== true) petish_club_verify_fail();
        wp_set_current_user($member); $remaining = $directory(array('name' => $prefix));
        $favourites = $directory(array('view' => 'favourites', 'name' => $prefix));
        petish_club_verify_mark('directory_visibility_revocation_verified', $remaining['pagination']['total'] === 11
            && !in_array($pets[0]['token'], $tokens($remaining['profiles']), true) && !in_array($pets[1]['token'], $tokens($remaining['profiles']), true)
            && $favourites['profiles'] === array() && $favourites['pagination']['total'] === 0
            && is_wp_error(Petish_Passport_Community::public_profile($pets[0]['token'])) && is_wp_error(Petish_Passport_Community::public_profile($pets[1]['token']))
            && !metadata_exists('post', $pets[0]['id'], Petish_Passport_Community::INDEX_NAME)
            && !metadata_exists('post', $pets[1]['id'], Petish_Passport_Community::INDEX_NAME));
    } finally {
        wp_set_current_user($previous_user); $GLOBALS['petish_club_fixture_phase'] = $previous_phase;
    }
}
