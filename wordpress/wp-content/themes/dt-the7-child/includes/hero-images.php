<?php
/** Media Library choices for the four existing homepage photographs. */
defined( 'ABSPATH' ) || exit;

final class Petish_Hero_Images {
    const OPTION = 'petish_home_hero_images_v1';
    const PAGE = 'petish-hero-images';
    const GROUP = 'petish_hero_images';
    const SLUGS = array( 'rabbit', 'guinea-pig', 'hamster', 'chinchilla' );
    const DEFAULT_FILES = array( 'rabbit'=>'rabbit-v6.webp', 'guinea-pig'=>'guinea-pig-v6.webp', 'hamster'=>'hamster-v6.webp', 'chinchilla'=>'chinchilla-v6.webp' );

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_filter( 'option_page_capability_' . self::GROUP, static function () { return 'edit_theme_options'; } );
    }

    public static function menu() {
        add_menu_page( 'Homepage', 'Homepage', 'edit_theme_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-format-image', 59 );
        add_submenu_page( self::PAGE, 'Hero images', 'Hero images', 'edit_theme_options', self::PAGE, array( __CLASS__, 'render' ) );
    }

    public static function register() {
        register_setting( self::GROUP, self::OPTION, array(
            'type'=>'array', 'sanitize_callback'=>array( __CLASS__, 'sanitize' ),
            'default'=>array_fill_keys( self::SLUGS, 0 ), 'show_in_rest'=>false,
        ) );
    }

    private static function id( $value ) {
        if ( ! is_int( $value ) && ! is_string( $value ) ) return false;
        $value = (string) $value;
        if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value )
            || strlen( $value ) > strlen( (string) PHP_INT_MAX )
            || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) return false;
        return (int) $value;
    }

    /** Resolve only public, native image attachments; never read private Passport files. */
    public static function image( $id ) {
        $id = self::id( $id );
        if ( ! $id ) return false;
        $post = get_post( $id );
        if ( ! $post instanceof WP_Post || $post->post_type !== 'attachment'
            || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true )
            || ! empty( $post->post_password )
            || ! in_array( get_post_mime_type( $post ), array( 'image/jpeg','image/png','image/webp','image/avif','image/gif' ), true ) ) return false;
        if ( $post->post_parent ) {
            $parent = get_post( $post->post_parent );
            if ( ! $parent instanceof WP_Post || $parent->post_status !== 'publish' || ! empty( $parent->post_password ) ) return false;
        }
        $source = wp_get_attachment_image_src( $id, 'full' );
        if ( ! is_array( $source ) || ! is_string( $source[0] ?? null ) || strlen( $source[0] ) > 4096
            || preg_match( '/[\x00-\x20\x7f]/', $source[0] )
            || ! is_int( $source[1] ?? null ) || $source[1] < 1 || $source[1] > 100000
            || ! is_int( $source[2] ?? null ) || $source[2] < 1 || $source[2] > 100000 ) return false;
        $url = wp_parse_url( $source[0] );
        if ( ! is_array( $url ) || ! in_array( $url['scheme'] ?? '', array( 'https','http' ), true ) || empty( $url['host'] )
            || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['fragment'] ) ) return false;
        $decoded = $source[0];
        for ( $i=0; $i<4; $i++ ) {
            $decoded = rawurldecode( $decoded );
            if ( preg_match( '/[\x00-\x1f\x7f]/', $decoded ) ) return false;
        }
        $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
        if ( ! is_string( $alt ) || strlen( $alt ) > 1000 || preg_match( '//u', $alt ) !== 1 ) $alt = '';
        return array( 'url'=>$source[0], 'width'=>$source[1], 'height'=>$source[2], 'alt'=>trim( wp_strip_all_tags( $alt ) ) );
    }

    public static function settings() {
        $stored = get_option( self::OPTION, array() );
        $result = array_fill_keys( self::SLUGS, 0 );
        if ( ! is_array( $stored ) ) return $result;
        foreach ( self::SLUGS as $slug ) {
            $id = self::id( $stored[$slug] ?? 0 );
            if ( $id && self::image( $id ) ) $result[$slug] = $id;
        }
        return $result;
    }

    /** Native options.php checks its nonce; this callback independently checks authority. */
    public static function sanitize( $input ) {
        $result = self::settings();
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            add_settings_error( self::OPTION, 'hero_permission', 'You cannot change homepage images.', 'error' );
            return $result;
        }
        if ( ! is_array( $input ) ) {
            add_settings_error( self::OPTION, 'hero_shape', 'Choose homepage images using the image controls.', 'error' );
            return $result;
        }
        foreach ( self::SLUGS as $slug ) {
            if ( ! array_key_exists( $slug, $input ) ) continue;
            $id = self::id( $input[$slug] );
            if ( $id === 0 || ( $id !== false && self::image( $id ) ) ) $result[$slug] = $id;
            else add_settings_error( self::OPTION, 'hero_' . $slug, 'One image could not be used. Choose a public Media Library image; its previous choice has been kept.', 'error' );
        }
        return $result;
    }

    public static function resolve( $defaults ) {
        if ( ! is_array( $defaults ) ) return array();
        $selected = self::settings();
        foreach ( $defaults as &$photo ) {
            $slug = $photo['slug'] ?? '';
            if ( ! in_array( $slug, self::SLUGS, true ) || empty( $selected[$slug] ) ) continue;
            $image = self::image( $selected[$slug] );
            if ( ! $image ) continue;
            $photo['custom'] = true;
            $photo['url'] = $image['url'];
            $photo['width'] = $image['width']; $photo['height'] = $image['height'];
            if ( $image['alt'] !== '' ) $photo['alt'] = $image['alt'];
            // An uploaded replacement is not the original licensed stock photograph.
            unset( $photo['author'], $photo['source'], $photo['license_name'], $photo['license_url'], $photo['changes'] );
        }
        unset( $photo );
        return $defaults;
    }

    public static function admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_' . self::PAGE || ! current_user_can( 'edit_theme_options' ) ) return;
        wp_enqueue_media();
        $file = get_stylesheet_directory() . '/assets/js/hero-images-admin.js';
        wp_enqueue_script( 'petish-hero-images-admin', get_stylesheet_directory_uri() . '/assets/js/hero-images-admin.js', array( 'media-editor' ), substr( hash_file( 'sha256', $file ), 0, 16 ), true );
        wp_add_inline_script( 'petish-hero-images-admin', 'window.petishHeroImages = ' . wp_json_encode( array(
            'title'=>__( 'Choose a homepage image', 'petish' ), 'button'=>__( 'Use this image', 'petish' ),
        ) ) . ';', 'before' );
    }

    public static function render() {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'You cannot change homepage images.', 'petish' ), '', array( 'response'=>403 ) );
            return;
        }
        $selected = self::settings();
        $labels = array( 'rabbit'=>'Rabbits', 'guinea-pig'=>'Guinea pigs', 'hamster'=>'Hamsters', 'chinchilla'=>'Chinchillas' );
        echo '<div class="wrap"><h1>' . esc_html__( 'Hero images', 'petish' ) . '</h1><p>' . esc_html__( 'Choose the four photographs used at the top of the homepage. Use a wide, high-resolution image with room for the heading. Update image alternative text in the Media Library.', 'petish' ) . '</p>';
        settings_errors( self::OPTION );
        echo '<form method="post" action="options.php">'; settings_fields( self::GROUP );
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:24px;max-width:1000px">';
        foreach ( $labels as $slug=>$label ) {
            $image = $selected[$slug] ? self::image( $selected[$slug] ) : false;
            $default = get_stylesheet_directory_uri() . '/assets/images/hero/' . self::DEFAULT_FILES[$slug];
            $url = $image ? $image['url'] : $default;
            echo '<section data-petish-hero-setting style="padding:20px;background:#fff;border:1px solid #c3c4c7;border-radius:8px"><h2 style="margin-top:0">' . esc_html( $label ) . '</h2>';
            echo '<img data-petish-hero-preview src="' . esc_url( $url ) . '" data-default-src="' . esc_url( $default ) . '" alt="' . esc_attr( $label ) . '" style="display:block;width:100%;height:180px;object-fit:cover;margin-bottom:16px;border-radius:4px">';
            echo '<input data-petish-hero-id type="hidden" name="' . esc_attr( self::OPTION . '[' . $slug . ']' ) . '" value="' . (int) $selected[$slug] . '">';
            echo '<button type="button" class="button" data-petish-hero-choose>' . esc_html__( 'Choose image', 'petish' ) . '</button> <button type="button" class="button" data-petish-hero-reset>' . esc_html__( 'Reset to default', 'petish' ) . '</button>';
            echo '</section>';
        }
        echo '</div><p>' . esc_html__( 'Save changes to publish your choices. Resetting restores the current Petish photograph for that animal.', 'petish' ) . '</p>';
        submit_button( __( 'Save changes', 'petish' ) );
        echo '</form></div>';
    }
}
