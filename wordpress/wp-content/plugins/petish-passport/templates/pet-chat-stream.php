<?php
/** Pet Chat channel view. $state is an explicit, permission-filtered service projection. */
defined( 'ABSPATH' ) || exit;
$channel = $state['channel'] ?? array();
$viewer = $state['viewer'] ?? array();
$channels = $state['channels'] ?? array();
$categories = $state['categories'] ?? array();
$selected_category = (string) ( $channel['category_id'] ?? '' );
$signed_in = ! empty( $viewer['signed_in'] );
$can_post = $signed_in && ! empty( $viewer['can_post'] ) && empty( $channel['read_only'] ) && empty( $channel['archived'] );
$config = self::config( $state );
?>
<main class="petish-chat-stream" data-pet-chat-stream data-chat-session="<?php echo esc_attr( $state['session'] ); ?>">
    <a class="pcs-skip" href="#pet-chat-messages"><?php esc_html_e( 'Skip to messages', 'petish-passport' ); ?></a>
    <header class="pcs-top">
        <div class="pcs-brand"><span class="pcs-brand-icon" aria-hidden="true">✦</span><div><p class="pcs-kicker"><?php esc_html_e( 'Your small-pet people', 'petish-passport' ); ?></p><h1><?php esc_html_e( 'Pet Chat', 'petish-passport' ); ?></h1></div></div>
        <div class="pcs-top-actions"><a href="<?php echo esc_url( class_exists( 'Petish_Passport_Community' ) ? Petish_Passport_Community::url() : home_url( '/' ) ); ?>" class="pcs-icon-button"><?php esc_html_e( 'Pet Club', 'petish-passport' ); ?> ↗</a><a href="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ); ?>" class="pcs-icon-button"><?php echo esc_html( $signed_in ? __( 'My account', 'petish-passport' ) : __( 'Sign in', 'petish-passport' ) ); ?></a></div>
    </header>
    <noscript><p class="pcs-noscript"><?php esc_html_e( 'You can read these messages here. Enable JavaScript to send messages, react and receive updates.', 'petish-passport' ); ?></p></noscript>
    <div class="pcs-shell" data-chat-shell>
        <aside class="pcs-sidebar" aria-label="<?php esc_attr_e( 'Pet Chat channels', 'petish-passport' ); ?>">
            <?php foreach ( $categories as $category ) : ?>
                <section class="pcs-channel-category" data-category-id="<?php echo esc_attr( $category['id'] ); ?>"><h2 class="pcs-sidebar-title"><?php echo esc_html( $category['name'] ); ?></h2><nav class="pcs-channel-list" aria-label="<?php echo esc_attr( $category['name'] ); ?>">
                    <?php foreach ( $channels as $item ) : if ( (string) ( $item['category_id'] ?? '' ) !== (string) $category['id'] ) continue; ?>
                        <a class="pcs-channel-link" href="<?php echo esc_url( self::url( $item['id'] ) ); ?>" data-chat-channel="<?php echo esc_attr( $item['id'] ); ?>" <?php if ( (string) $item['id'] === (string) ( $channel['id'] ?? '' ) ) echo 'aria-current="page"'; ?>><span class="pcs-channel-hash" aria-hidden="true">#</span><span class="pcs-channel-name"><?php echo esc_html( $item['name'] ); ?></span><span class="pcs-unread" data-chat-unread="<?php echo esc_attr( $item['id'] ); ?>" <?php if ( empty( $state['unread'][ $item['id'] ] ) ) echo 'hidden'; ?>><?php echo (int) ( $state['unread'][ $item['id'] ] ?? 0 ); ?></span></a>
                    <?php endforeach; ?>
                </nav></section>
            <?php endforeach; ?>
            <div class="pcs-sidebar-note"><p><?php esc_html_e( 'A friendly place to ask, share and learn together.', 'petish-passport' ); ?></p><p><?php esc_html_e( 'Messages are public. Keep your address, order details and other personal information private.', 'petish-passport' ); ?></p><p><?php esc_html_e( 'For urgent health concerns, contact a veterinarian.', 'petish-passport' ); ?></p></div>
        </aside>
        <div class="pcs-divider" data-chat-divider role="separator" tabindex="0" aria-label="<?php esc_attr_e( 'Resize channel navigation', 'petish-passport' ); ?>" aria-orientation="vertical" aria-valuemin="180" aria-valuemax="420" aria-valuenow="240"></div>
        <nav class="pcs-mobile-topics" role="tablist" aria-label="<?php esc_attr_e( 'Chat categories', 'petish-passport' ); ?>" data-chat-categories>
            <?php foreach ( $categories as $category ) : ?><button type="button" role="tab" class="pcs-icon-button" id="pet-chat-category-<?php echo esc_attr( $category['id'] ); ?>" data-chat-category="<?php echo esc_attr( $category['id'] ); ?>" aria-controls="pet-chat-mobile-channels" aria-selected="<?php echo $selected_category === (string) $category['id'] ? 'true' : 'false'; ?>" tabindex="<?php echo $selected_category === (string) $category['id'] ? '0' : '-1'; ?>"><?php echo esc_html( $category['name'] ); ?></button><?php endforeach; ?>
        </nav>
        <nav class="pcs-mobile-channels" id="pet-chat-mobile-channels" role="tabpanel" aria-label="<?php esc_attr_e( 'Channels in selected category', 'petish-passport' ); ?>" data-chat-mobile-channels>
            <?php foreach ( $channels as $item ) : ?><a href="<?php echo esc_url( self::url( $item['id'] ) ); ?>" data-chat-channel="<?php echo esc_attr( $item['id'] ); ?>" data-chat-category-channel="<?php echo esc_attr( $item['category_id'] ?? '' ); ?>" <?php if ( (string) $item['id'] === (string) ( $channel['id'] ?? '' ) ) echo 'aria-current="page"'; ?> <?php if ( (string) ( $item['category_id'] ?? '' ) !== $selected_category ) echo 'hidden'; ?>><span class="pcs-channel-hash" aria-hidden="true">#</span><?php echo esc_html( $item['name'] ); ?></a><?php endforeach; ?>
        </nav>
        <section class="pcs-main" aria-label="<?php echo esc_attr( $channel['name'] ?? __( 'Pet Chat messages', 'petish-passport' ) ); ?>">
            <header class="pcs-channel-heading"><div><h2><span aria-hidden="true"># </span><span data-chat-channel-name><?php echo esc_html( $channel['name'] ?? '' ); ?></span></h2><p class="pcs-channel-description" data-chat-channel-description><?php echo esc_html( $channel['description'] ?? '' ); ?></p></div><div class="pcs-channel-controls"><span class="pcs-presence" data-chat-presence aria-label="<?php esc_attr_e( 'Members active in Pet Chat', 'petish-passport' ); ?>"></span><button type="button" class="pcs-icon-button" data-chat-muted-button hidden><?php esc_html_e( 'Muted', 'petish-passport' ); ?></button><button type="button" class="pcs-icon-button" data-chat-poll-toggle aria-pressed="false" hidden><?php esc_html_e( 'Pause', 'petish-passport' ); ?></button></div></header>
            <div class="pcs-status-row"><p class="pcs-status" data-chat-status role="status" aria-live="polite" aria-atomic="true"></p><button type="button" class="pcs-button pcs-secondary pcs-new" data-chat-new hidden><?php esc_html_e( 'New messages ↓', 'petish-passport' ); ?></button></div>
            <div class="pcs-feed" id="pet-chat-messages" data-chat-feed role="log" aria-label="<?php esc_attr_e( 'Channel messages', 'petish-passport' ); ?>" aria-live="off" tabindex="0">
                <button type="button" class="pcs-button pcs-secondary pcs-load-older" data-chat-older <?php if ( empty( $state['cursor']['has_more'] ) ) echo 'hidden'; ?>><?php esc_html_e( 'Earlier messages', 'petish-passport' ); ?></button>
                <div data-chat-messages><?php $previous = null; foreach ( $state['messages'] ?? array() as $message ) { self::message_html( $message, $previous ); $previous = $message; } ?></div>
                <?php if ( empty( $state['messages'] ) ) : ?><div class="pcs-empty" data-chat-empty><h3><?php esc_html_e( 'Make yourself at home.', 'petish-passport' ); ?></h3><p><?php esc_html_e( 'Share a little moment, ask a question or say hello.', 'petish-passport' ); ?></p></div><?php endif; ?>
            </div>
            <div class="pcs-composer" data-chat-composer-panel>
                <div class="pcs-muted-panel pcs-popover" data-chat-muted-panel hidden></div>
                <?php if ( $can_post ) : ?>
                    <form data-chat-composer method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" enctype="multipart/form-data">
                        <p class="pcs-form-error" data-chat-error role="alert" hidden></p>
                        <div class="pcs-identity" data-chat-identity><label for="pet-chat-alias"><?php esc_html_e( 'Your public name', 'petish-passport' ); ?><input type="text" name="alias" id="pet-chat-alias" autocomplete="off" maxlength="40" required value="<?php echo esc_attr( $viewer['alias'] ?? '' ); ?>"></label><label class="pcs-consent"><input type="checkbox" name="consent" value="1" required><span><?php esc_html_e( 'Share my message and uploads publicly under this name. My first contribution will be reviewed.', 'petish-passport' ); ?></span></label></div>
                        <div class="pcs-compose-reply" data-chat-reply-banner hidden><span data-chat-reply-label></span><button class="pcs-icon-button" data-chat-reply-cancel type="button" aria-label="<?php esc_attr_e( 'Cancel reply', 'petish-passport' ); ?>">×</button></div>
                        <div class="pcs-upload-list" data-chat-upload-list></div>
                        <div class="pcs-compose-row"><button type="button" class="pcs-icon-button" data-chat-upload-button aria-label="<?php esc_attr_e( 'Add a photo or video', 'petish-passport' ); ?>" <?php if ( empty( $config['upload']['enabled'] ) ) echo 'hidden'; ?>>＋</button><label class="screen-reader-text" for="pet-chat-draft"><?php esc_html_e( 'Your message', 'petish-passport' ); ?></label><textarea class="pcs-draft" id="pet-chat-draft" name="body" data-chat-draft rows="2" maxlength="1000" placeholder="<?php echo esc_attr( sprintf( __( 'Message #%s', 'petish-passport' ), $channel['name'] ?? '' ) ); ?>" aria-describedby="pet-chat-format-help"></textarea><button type="button" class="pcs-icon-button" data-chat-emoji-button aria-label="<?php esc_attr_e( 'Insert emoji', 'petish-passport' ); ?>" aria-expanded="false">☺</button><button type="submit" class="pcs-button" data-chat-send><?php esc_html_e( 'Send', 'petish-passport' ); ?></button></div>
                        <input class="pcs-file-input" type="file" name="upload" data-chat-file accept="<?php echo esc_attr( $config['upload']['accept'] ); ?>" tabindex="-1">
                        <div class="pcs-popover pcs-emoji-picker" data-chat-emoji-picker hidden></div>
                        <div class="pcs-composer-toolbar"><p id="pet-chat-format-help" class="pcs-help"><?php esc_html_e( '**bold** · *italic* · __underline__ · Shift+Enter for a new line', 'petish-passport' ); ?></p><span class="pcs-help pcs-counter" data-chat-counter>0 / 1,000</span></div>
                        <p class="pcs-help" data-chat-upload-help <?php if ( empty( $config['upload']['enabled'] ) ) echo 'hidden'; ?>><?php esc_html_e( 'One photo or video per message, up to 250 MiB. No video duration limit. Supported formats depend on this server.', 'petish-passport' ); ?></p>
                    </form>
                <?php elseif ( ! $signed_in ) : ?><div class="pcs-guest"><p><?php esc_html_e( 'Everyone can read. Sign in with your Petish account to join in.', 'petish-passport' ); ?></p><a class="pcs-button" href="<?php echo esc_url( $config['loginUrl'] ); ?>"><?php esc_html_e( 'Sign in', 'petish-passport' ); ?></a></div>
                <?php else : ?><p class="pcs-help"><?php echo esc_html( ! empty( $channel['read_only'] ) || ! empty( $channel['archived'] ) ? __( 'This channel is read-only. You can keep reading its messages.', 'petish-passport' ) : __( 'Posting is currently paused for this account. You can still read public messages.', 'petish-passport' ) ); ?></p><?php endif; ?>
            </div>
        </section>
    </div>
    <?php if ( ! empty( $viewer['is_staff'] ) ) : ?><details class="pcs-moderation"><summary><?php esc_html_e( 'Staff tools', 'petish-passport' ); ?></summary><p><?php esc_html_e( 'Only staff can manage channels and moderate contributions.', 'petish-passport' ); ?></p><button type="button" class="pcs-button pcs-secondary" data-chat-queue><?php esc_html_e( 'Review moderation queue', 'petish-passport' ); ?></button><button type="button" class="pcs-button pcs-secondary" data-chat-manage><?php esc_html_e( 'Manage categories and channels', 'petish-passport' ); ?></button><div data-chat-staff-panel></div></details><?php endif; ?>
    <script type="application/json" data-chat-config><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HTML delimiter escaping. ?></script>
</main>
