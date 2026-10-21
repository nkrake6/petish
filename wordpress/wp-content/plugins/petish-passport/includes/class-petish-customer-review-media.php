<?php
/** Moderated customer-review attachments. Originals never enter the public uploads tree. */
defined( 'ABSPATH' ) || exit;

final class Petish_Customer_Review_Media {
    const META = '_petish_review_media';
    const MANAGED = '_petish_review_managed';
    const JOURNAL = '_petish_review_media_unattached';
    const MAX_PHOTOS = 4;
    const MAX_PHOTO_BYTES = 5242880;
    const MAX_VIDEO_BYTES = 20971520;
    const MAX_PIXELS = 16000000;

    public static function boot() {
        add_action( 'admin_post_petish_customer_review_media', array( __CLASS__, 'serve' ) );
        add_action( 'admin_post_nopriv_petish_customer_review_media', array( __CLASS__, 'serve' ) );
        add_action( 'delete_comment', array( __CLASS__, 'delete_comment_media' ), 5, 2 );
        add_action( 'delete_user', array( __CLASS__, 'delete_user_media' ), 5 );
        add_action( 'wpmu_delete_user', array( __CLASS__, 'delete_user_media' ), 5 );
    }

    private static function error( $code = 'petish_review_media', $message = '' ) {
        return new WP_Error( $code, $message ?: 'Your review attachment could not be processed. Please try again.' );
    }

    /** Paths are resolved and checked component by component, never through symbolic links. */
    private static function directory( $uid, $create = false ) {
        $uid = absint( $uid );
        $public = realpath( ABSPATH );
        $app = realpath( dirname( untrailingslashit( ABSPATH ) ) );
        if ( ! $uid || ! $public || ! $app || dirname( $public ) !== $app ) {
            return self::error();
        }
        $paths = array( $app . '/private', $app . '/private/petish-passport', $app . '/private/petish-passport/customer-reviews', $app . '/private/petish-passport/customer-reviews/' . $uid );
        foreach ( $paths as $path ) {
            if ( is_link( $path ) || ( ! is_dir( $path ) && ( ! $create || ! wp_mkdir_p( $path ) ) ) ) {
                return self::error();
            }
            $real = realpath( $path );
            if ( $real !== $path || $real === $public || str_starts_with( $real . '/', $public . '/' ) ) {
                return self::error();
            }
            if ( $create && ! @chmod( $path, 0700 ) ) {
                return self::error();
            }
        }
        return end( $paths );
    }

    private static function key_parts( $key ) {
        return is_string( $key ) && preg_match( '#^([1-9][0-9]*)/([a-f0-9]{48}[.](?:jpg|png|webp|mp4))$#D', $key, $parts ) ? $parts : false;
    }

    /** Internal callers must separately authorize the owner/comment before using this path. */
    public static function file_for_owner( $key, $uid ) {
        $parts = self::key_parts( $key );
        if ( ! $parts || ! absint( $uid ) || (int) $parts[1] !== absint( $uid ) ) {
            return false;
        }
        $dir = self::directory( $uid );
        if ( is_wp_error( $dir ) ) {
            return false;
        }
        $path = $dir . '/' . $parts[2];
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }
        $stat = @lstat( $path );
        $real = realpath( $path );
        return $real && dirname( $real ) === $dir && $stat && 1 === $stat['nlink'] ? $real : false;
    }

    /** Normalize only the three explicitly supported upload fields. */
    private static function uploads( $files ) {
        if ( ! is_array( $files ) ) {
            return self::error();
        }
        $out = array();
        foreach ( $files as $field => $upload ) {
            if ( ! in_array( $field, array( 'photos', 'video', 'avatar' ), true ) ) {
                return self::error( 'petish_review_media_fields', 'An unexpected attachment was supplied.' );
            }
            if ( ! is_array( $upload ) || ! isset( $upload['error'], $upload['tmp_name'], $upload['name'] ) ) {
                return self::error();
            }
            if ( 'photos' === $field && is_array( $upload['error'] ) ) {
                if ( count( $upload['error'] ) > self::MAX_PHOTOS || ! is_array( $upload['name'] ) || ! is_array( $upload['tmp_name'] )
                    || array_keys( $upload['error'] ) !== array_keys( $upload['name'] ) || array_keys( $upload['error'] ) !== array_keys( $upload['tmp_name'] ) ) {
                    return self::error( 'petish_review_media_count', 'Choose up to four photos and one video per review.' );
                }
                foreach ( $upload['error'] as $index => $error ) {
                    if ( UPLOAD_ERR_NO_FILE === $error ) {
                        continue;
                    }
                    $out[] = array( 'kind' => 'photo', 'file' => array( 'error' => $error, 'tmp_name' => $upload['tmp_name'][ $index ], 'name' => $upload['name'][ $index ] ) );
                }
            } else {
                if ( UPLOAD_ERR_NO_FILE === $upload['error'] ) {
                    continue;
                }
                $out[] = array( 'kind' => 'photos' === $field ? 'photo' : $field, 'file' => $upload );
            }
        }
        foreach ( $out as $item ) {
            $file = $item['file'];
            if ( ! is_int( $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) ) {
                return self::error();
            }
            if ( UPLOAD_ERR_OK !== $file['error'] ) {
                return self::error( 'petish_review_media_upload', 'An attachment did not finish uploading. Photos must be up to 5 MB and video up to 20 MB.' );
            }
            if ( is_link( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
                return self::error( 'petish_review_media_upload', 'Please select the attachment again.' );
            }
        }
        return $out;
    }

    /** A private journal makes unattached files recoverable after a crashed submission. */
    private static function reserve( $uid, $extension ) {
        try {
            $key = absint( $uid ) . '/' . bin2hex( random_bytes( 24 ) ) . '.' . $extension;
        } catch ( Throwable $error ) {
            return self::error();
        }
        $entry = array( 'key' => $key, 'created' => time() );
        if ( ! add_user_meta( $uid, self::JOURNAL, $entry, false ) ) {
            return self::error( 'petish_review_media_journal', 'The attachment could not be safely prepared. Please try again.' );
        }
        return $key;
    }

    private static function clear_journal_key( $key, $uid ) {
        $ok = true;
        foreach ( get_user_meta( $uid, self::JOURNAL, false ) as $entry ) {
            if ( is_array( $entry ) && ( $entry['key'] ?? '' ) === $key && ! delete_user_meta( $uid, self::JOURNAL, $entry ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Core calls this only after the complete manifest is durably attached to a comment. */
    public static function commit_manifest( $manifest, $uid ) {
        if ( ! self::valid_manifest( $manifest, $uid ) ) {
            return self::error();
        }
        $ok = true;
        foreach ( $manifest as $item ) {
            if ( ! self::clear_journal_key( $item['key'], $uid ) ) {
                $ok = false;
            }
        }
        return $ok ? true : self::error( 'petish_review_media_journal', 'Attachment housekeeping is pending.' );
    }

    /** Old failed uploads are removed only after checking every comment-meta reference for this owner. */
    public static function cleanup_unattached( $uid ) {
        global $wpdb;
        $uid = absint( $uid );
        if ( ! $uid ) {
            return false;
        }
        $ok = true;
        foreach ( get_user_meta( $uid, self::JOURNAL, false ) as $entry ) {
            if ( ! is_array( $entry ) || ! is_int( $entry['created'] ?? null ) || ! self::key_parts( $entry['key'] ?? null ) ) {
                $ok = false;
                continue;
            }
            $parts = self::key_parts( $entry['key'] );
            if ( (int) $parts[1] !== $uid ) {
                $ok = false;
                continue;
            }
            if ( $entry['created'] > time() - DAY_IN_SECONDS ) {
                continue;
            }
            $reference = $wpdb->get_var( $wpdb->prepare(
                "SELECT cm.meta_id FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID=cm.comment_id WHERE c.user_id=%d AND cm.meta_value LIKE %s LIMIT 1",
                $uid, '%' . $wpdb->esc_like( $entry['key'] ) . '%'
            ) );
            if ( $wpdb->last_error ) {
                $ok = false;
                continue;
            }
            // A referenced file survived a metadata commit: retire its journal, not the file.
            if ( null !== $reference ) {
                $ok = self::clear_journal_key( $entry['key'], $uid ) && $ok;
            } else {
                $ok = self::remove( $entry['key'], $uid ) && $ok;
            }
        }
        return $ok;
    }

    /** @return array|WP_Error Private manifest: never print it or send it to a browser. */
    public static function save_uploads( $files, $uid ) {
        $uid = absint( $uid );
        if ( ! $uid || ! get_user_by( 'id', $uid ) || ! class_exists( 'Petish_Customer_Reviews' ) || ! Petish_Customer_Reviews::enabled() ) {
            return self::error();
        }
        $uploads = self::uploads( $files );
        if ( is_wp_error( $uploads ) || ! $uploads ) {
            return $uploads;
        }
        self::cleanup_unattached( $uid );
        if ( count( get_user_meta( $uid, self::JOURNAL, false ) ) > 48 ) {
            return self::error( 'petish_review_media_cleanup', 'Some earlier attachments need housekeeping. Please contact the store before uploading more.' );
        }
        $dir = self::directory( $uid, true );
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }
        $saved = array();
        foreach ( $uploads as $item ) {
            try {
                $result = 'video' === $item['kind'] ? self::save_video( $item['file'], $uid, $dir ) : self::save_photo( $item['file'], $uid, $dir, $item['kind'] );
            } catch ( Throwable $error ) {
                $result = self::error();
            }
            if ( is_wp_error( $result ) ) {
                self::delete_manifest( $saved, $uid );
                return $result;
            }
            $saved[] = $result;
        }
        return $saved;
    }

    private static function save_photo( $file, $uid, $dir, $kind ) {
        $tmp = $file['tmp_name'];
        $bytes = @filesize( $tmp );
        if ( ! $bytes || $bytes > self::MAX_PHOTO_BYTES ) {
            return self::error( 'petish_review_photo_size', 'Choose a photo no larger than 5 MB.' );
        }
        $allowed = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
        $types = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $checked = wp_check_filetype_and_ext( $tmp, $file['name'], $allowed );
        $mime = wp_get_image_mime( $tmp );
        if ( ! isset( $types[ $extension ] ) || $mime !== $types[ $extension ] || empty( $checked['ext'] ) || ( $checked['type'] ?? '' ) !== $mime ) {
            return self::error( 'petish_review_photo_type', 'Choose a JPEG, PNG or WebP photo.' );
        }
        $size = wp_getimagesize( $tmp );
        if ( ! $size || $size[0] < 1 || $size[1] < 1 || $size[0] > 8192 || $size[1] > 8192 || $size[0] * $size[1] > self::MAX_PIXELS ) {
            return self::error( 'petish_review_photo_dimensions', 'Choose a photo up to 16 megapixels and 8192 pixels on either side.' );
        }
        $gd = static function () { return array( 'WP_Image_Editor_GD' ); };
        add_filter( 'wp_image_editors', $gd, PHP_INT_MAX );
        try {
            $editor = wp_get_image_editor( $tmp );
        } finally {
            remove_filter( 'wp_image_editors', $gd, PHP_INT_MAX );
        }
        if ( is_wp_error( $editor ) || is_wp_error( $editor->maybe_exif_rotate() ) ) {
            return self::error( 'petish_review_photo_editor', 'This photo could not be processed. Please try another image.' );
        }
        $maximum = 'avatar' === $kind ? 480 : 1200;
        if ( ( $size[0] > $maximum || $size[1] > $maximum ) && is_wp_error( $editor->resize( $maximum, $maximum, false ) ) ) {
            return self::error();
        }
        if ( is_wp_error( $editor->set_quality( 85 ) ) ) {
            return self::error();
        }
        $extension = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' )[ $mime ];
        $key = self::reserve( $uid, $extension );
        if ( is_wp_error( $key ) ) {
            return $key;
        }
        $path = $dir . '/' . basename( $key );
        if ( file_exists( $path ) || is_link( $path ) ) {
            self::clear_journal_key( $key, $uid );
            return self::error();
        }
        $old_mask = umask( 0077 );
        try {
            // GD emits fresh raster data, discarding original EXIF/GPS even without resizing.
            $written = $editor->save( $path, $mime );
        } finally {
            umask( $old_mask );
        }
        $out_size = ! is_wp_error( $written ) && is_file( $path ) ? @wp_getimagesize( $path ) : false;
        $out_bytes = @filesize( $path );
        if ( is_wp_error( $written ) || ! $out_size || ! $out_bytes || $out_bytes > self::MAX_PHOTO_BYTES || ! @chmod( $path, 0600 ) || ! self::file_for_owner( $key, $uid ) ) {
            self::remove( $key, $uid );
            return self::error();
        }
        return array( 'key' => $key, 'kind' => $kind, 'mime' => $mime, 'bytes' => $out_bytes, 'width' => (int) $out_size[0], 'height' => (int) $out_size[1] );
    }

    private static function save_video( $file, $uid, $dir ) {
        if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'mp4' || ! class_exists( 'Petish_Passport_Video' ) ) {
            return self::error( 'petish_review_video_type', 'Choose an MP4 video with H.264 video and optional AAC audio, up to 20 seconds and 20 MB.' );
        }
        $info = Petish_Passport_Video::inspect( $file['tmp_name'] );
        if ( is_wp_error( $info ) ) {
            return $info;
        }
        $key = self::reserve( $uid, 'mp4' );
        if ( is_wp_error( $key ) ) {
            return $key;
        }
        $path = $dir . '/' . basename( $key );
        if ( file_exists( $path ) || is_link( $path ) ) {
            self::clear_journal_key( $key, $uid );
            return self::error();
        }
        $old_mask = umask( 0077 );
        try {
            $moved = move_uploaded_file( $file['tmp_name'], $path );
        } finally {
            umask( $old_mask );
        }
        if ( ! $moved || ! @chmod( $path, 0600 ) || filesize( $path ) !== $info['bytes'] || ! self::file_for_owner( $key, $uid ) ) {
            self::remove( $key, $uid );
            return self::error();
        }
        return array( 'key' => $key, 'kind' => 'video', 'mime' => 'video/mp4', 'bytes' => (int) $info['bytes'], 'width' => (int) $info['width'], 'height' => (int) $info['height'], 'duration' => (float) $info['duration'] );
    }

    public static function valid_manifest( $manifest, $uid ) {
        if ( ! is_array( $manifest ) || count( $manifest ) > self::MAX_PHOTOS + 2 || array_values( $manifest ) !== $manifest ) {
            return false;
        }
        $counts = array( 'photo' => 0, 'avatar' => 0, 'video' => 0 );
        $keys = array();
        $mimes = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4' );
        foreach ( $manifest as $item ) {
            if ( ! is_array( $item ) || ! is_string( $item['kind'] ?? null ) || ! isset( $counts[ $item['kind'] ] ) ) {
                return false;
            }
            $parts = self::key_parts( $item['key'] ?? null );
            if ( ! $parts || (int) $parts[1] !== absint( $uid ) || isset( $keys[ $item['key'] ] ) ) {
                return false;
            }
            $extension = pathinfo( $parts[2], PATHINFO_EXTENSION );
            if ( ( $item['mime'] ?? '' ) !== $mimes[ $extension ] || ( 'video' === $item['kind'] ) !== ( 'mp4' === $extension )
                || ! is_int( $item['bytes'] ?? null ) || $item['bytes'] < 1 || $item['bytes'] > ( 'video' === $item['kind'] ? self::MAX_VIDEO_BYTES : self::MAX_PHOTO_BYTES )
                || ! is_int( $item['width'] ?? null ) || ! is_int( $item['height'] ?? null ) || $item['width'] < 1 || $item['height'] < 1
                || $item['width'] > 8192 || $item['height'] > 8192 ) {
                return false;
            }
            if ( 'video' === $item['kind'] && ( ! is_numeric( $item['duration'] ?? null ) || ! is_finite( (float) $item['duration'] ) || $item['duration'] <= 0 || $item['duration'] > 20 ) ) {
                return false;
            }
            ++$counts[ $item['kind'] ];
            $keys[ $item['key'] ] = true;
        }
        return $counts['photo'] <= self::MAX_PHOTOS && $counts['avatar'] <= 1 && $counts['video'] <= 1;
    }

    public static function remove( $key, $uid ) {
        $parts = self::key_parts( $key );
        if ( ! $parts || (int) $parts[1] !== absint( $uid ) ) {
            return false;
        }
        $candidate = dirname( untrailingslashit( ABSPATH ) ) . '/private/petish-passport/customer-reviews/' . $uid;
        if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
            return self::clear_journal_key( $key, $uid );
        }
        $dir = self::directory( $uid );
        if ( is_wp_error( $dir ) ) {
            return false;
        }
        $path = $dir . '/' . $parts[2];
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            return self::clear_journal_key( $key, $uid );
        }
        $path = self::file_for_owner( $key, $uid );
        if ( ! $path ) {
            return false;
        }
        wp_delete_file( $path );
        return ! file_exists( $path ) && ! is_link( $path ) && self::clear_journal_key( $key, $uid );
    }

    public static function delete_manifest( $manifest, $uid ) {
        if ( ! self::valid_manifest( $manifest, $uid ) ) {
            return false;
        }
        $ok = true;
        foreach ( $manifest as $item ) {
            // Native comment deletion cannot be cancelled here. Keep a durable key
            // if unlink fails after WordPress removes the comment's metadata.
            $journaled = false;
            foreach ( get_user_meta( $uid, self::JOURNAL, false ) as $entry ) {
                if ( is_array( $entry ) && ( $entry['key'] ?? '' ) === $item['key'] ) {
                    $journaled = true;
                    break;
                }
            }
            if ( ! $journaled ) {
                $journaled = (bool) add_user_meta( $uid, self::JOURNAL, array( 'key' => $item['key'], 'created' => time() ), false );
            }
            if ( ! self::remove( $item['key'], $uid ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function cleanup_user( $uid ) {
        $uid = absint( $uid );
        if ( ! $uid ) {
            return false;
        }
        $candidate = dirname( untrailingslashit( ABSPATH ) ) . '/private/petish-passport/customer-reviews/' . $uid;
        if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
            delete_user_meta( $uid, self::JOURNAL );
            return true;
        }
        $dir = self::directory( $uid );
        if ( is_wp_error( $dir ) ) {
            return false;
        }
        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        foreach ( array_diff( $entries, array( '.', '..' ) ) as $name ) {
            if ( ! self::key_parts( $uid . '/' . $name ) || ! self::remove( $uid . '/' . $name, $uid ) ) {
                return false;
            }
        }
        if ( ! @rmdir( $dir ) ) {
            return false;
        }
        delete_user_meta( $uid, self::JOURNAL );
        return true;
    }

    public static function delete_user_media( $uid ) {
        if ( ! self::cleanup_user( $uid ) ) {
            wp_die( esc_html__( 'Some private review attachments could not be removed. Please contact the store before retrying account deletion.', 'petish-passport' ), '', array( 'response' => 500 ) );
        }
    }

    public static function delete_comment_media( $comment_id, $comment = null ) {
        $comment = $comment ?: get_comment( $comment_id );
        if ( $comment && '1' === (string) get_comment_meta( $comment->comment_ID, self::MANAGED, true ) ) {
            self::delete_manifest( get_comment_meta( $comment->comment_ID, self::META, true ) ?: array(), (int) $comment->user_id );
        }
    }

    private static function authorized( $comment, $viewer_id, $owner = true ) {
        if ( ! class_exists( 'Petish_Customer_Reviews' ) || ! Petish_Customer_Reviews::enabled() || ! $comment || 'review' !== $comment->comment_type || ! $comment->user_id || ! get_user_by( 'id', $comment->user_id )
            || '1' !== (string) get_comment_meta( $comment->comment_ID, self::MANAGED, true ) ) {
            return false;
        }
        if ( get_user_meta( (int) $comment->user_id, Petish_Customer_Reviews::DELETING, true ) ) {
            return false;
        }
        if ( Petish_Customer_Reviews::is_public( $comment ) ) {
            return true;
        }
        if ( ! $owner || ! $viewer_id ) {
            return false;
        }
        // Trashed/spam reviews cannot be recovered publicly by a former media URL.
        return (int) $comment->user_id === (int) $viewer_id || user_can( $viewer_id, 'moderate_comments' );
    }

    /** Only opaque endpoint URLs and approved technical values leave this service. */
    public static function project( $comment, $owner = false ) {
        $comment = is_numeric( $comment ) ? get_comment( (int) $comment ) : $comment;
        if ( ! self::authorized( $comment, get_current_user_id(), $owner ) ) {
            return array();
        }
        $manifest = get_comment_meta( $comment->comment_ID, self::META, true );
        if ( ! self::valid_manifest( $manifest, (int) $comment->user_id ) ) {
            return array();
        }
        $out = array();
        foreach ( $manifest as $index => $item ) {
            if ( ! self::file_for_owner( $item['key'], (int) $comment->user_id ) ) {
                continue;
            }
            $safe = array( 'kind' => $item['kind'], 'url' => self::url( $comment->comment_ID, $index ), 'mime' => $item['mime'], 'bytes' => $item['bytes'], 'width' => $item['width'], 'height' => $item['height'] );
            if ( 'video' === $item['kind'] ) {
                $safe['duration'] = (float) $item['duration'];
            }
            $out[] = $safe;
        }
        return $out;
    }

    public static function url( $comment_id, $index ) {
        return add_query_arg( array( 'action' => 'petish_customer_review_media', 'review_id' => absint( $comment_id ), 'item' => absint( $index ) ), admin_url( 'admin-post.php' ) );
    }

    /** @return array|WP_Error Internal authorization result, including a private path. */
    public static function resolve( $comment_id, $index, $viewer_id = 0 ) {
        $comment = get_comment( absint( $comment_id ) );
        if ( ! is_int( $index ) || $index < 0 || $index > self::MAX_PHOTOS + 1 || ! self::authorized( $comment, absint( $viewer_id ) ) ) {
            return self::error( 'petish_review_media_missing', 'Attachment not available.' );
        }
        $manifest = get_comment_meta( $comment->comment_ID, self::META, true );
        if ( ! self::valid_manifest( $manifest, (int) $comment->user_id ) || ! isset( $manifest[ $index ] ) ) {
            return self::error( 'petish_review_media_missing', 'Attachment not available.' );
        }
        $entry = $manifest[ $index ];
        $path = self::file_for_owner( $entry['key'], (int) $comment->user_id );
        if ( ! $path || @filesize( $path ) !== $entry['bytes'] ) {
            return self::error( 'petish_review_media_missing', 'Attachment not available.' );
        }
        return array( 'path' => $path, 'entry' => $entry );
    }

    public static function serve() {
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cross-Origin-Resource-Policy: same-origin' );
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            status_header( 405 );
            header( 'Allow: GET, HEAD' );
            exit;
        }
        $raw_id = $_GET['review_id'] ?? '';
        $raw_index = $_GET['item'] ?? '';
        $id = is_string( $raw_id ) && preg_match( '/^[1-9][0-9]{0,17}$/D', $raw_id ) ? (int) $raw_id : 0;
        $index = is_string( $raw_index ) && preg_match( '/^[0-5]$/D', $raw_index ) ? (int) $raw_index : -1;
        $resolved = self::resolve( $id, $index, get_current_user_id() );
        if ( is_wp_error( $resolved ) ) {
            status_header( 404 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'Not found.';
            exit;
        }
        if ( 'video' === $resolved['entry']['kind'] ) {
            Petish_Passport_Video::stream( $resolved['path'] );
            exit;
        }
        $before = @lstat( $resolved['path'] );
        $input = @fopen( $resolved['path'], 'rb' );
        $stat = $input ? fstat( $input ) : false;
        if ( ! $before || ! $stat || 1 !== $stat['nlink'] || $before['ino'] !== $stat['ino'] || $before['dev'] !== $stat['dev'] || $stat['size'] !== $resolved['entry']['bytes'] ) {
            if ( is_resource( $input ) ) {
                fclose( $input );
            }
            status_header( 404 );
            exit;
        }
        status_header( 200 );
        header( 'Content-Type: ' . $resolved['entry']['mime'] );
        header( 'Content-Length: ' . $stat['size'] );
        header( 'Content-Disposition: inline; filename="review-photo.' . pathinfo( $resolved['path'], PATHINFO_EXTENSION ) . '"' );
        if ( 'HEAD' !== $method ) {
            fpassthru( $input );
        }
        fclose( $input );
        exit;
    }
}
