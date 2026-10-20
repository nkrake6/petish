<?php
/**
 * Petish's public landing page. Pet profiles never enter this cacheable view.
 */
defined( 'ABSPATH' ) || exit;

if ( function_exists( 'presscore_config' ) ) {
    $petish_home_config = presscore_config();
    $petish_home_config->set( 'template', 'page' );
    $petish_home_config->set( 'sidebar_position', 'disabled' );
    $petish_home_config->set( 'page_title.enabled', false );
    $petish_home_config->set( 'page_title.breadcrumbs.enabled', false );
}

$petish_home_commerce = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
$petish_home_passport = $petish_home_commerce && class_exists( 'Petish_Passport_UI' );
$petish_home_shop     = $petish_home_commerce ? add_query_arg( 'petish_species', 'all', wc_get_page_permalink( 'shop' ) ) : '#petish-collection';
$petish_home_pets     = $petish_home_passport ? Petish_Passport_UI::url() : '';
$petish_home_images   = get_stylesheet_directory_uri() . '/assets/images/';
$petish_home_products = array();
$petish_home_hot      = array();
$petish_home_free     = array();
$petish_home_photos   = array(
    array( 'slug' => 'rabbit', 'image' => 'rabbit-v6.webp', 'width' => 2400, 'height' => 1597, 'name' => 'Rabbits', 'alt' => 'Fluffy white lop-eared rabbit resting on a green lawn', 'author' => 'Kamil Klyta', 'source' => 'https://unsplash.com/photos/a-small-white-rabbit-sitting-on-top-of-a-lush-green-field-DibXE-TND1o', 'license_name' => 'Unsplash License', 'license_url' => 'https://unsplash.com/license', 'changes' => 'Resized and converted to WebP; displayed horizontally mirrored with CSS.' ),
    array( 'slug' => 'guinea-pig', 'image' => 'guinea-pig-v6.webp', 'width' => 2400, 'height' => 1347, 'name' => 'Guinea pigs', 'alt' => 'Three guinea pigs nestled together on a soft cream blanket', 'author' => 'Kayl Photo', 'source' => 'https://unsplash.com/photos/three-cute-guinea-pigs-are-posing-for-the-camera-3HfmW2Bjqg8', 'license_name' => 'Unsplash License', 'license_url' => 'https://unsplash.com/license' ),
    array( 'slug' => 'hamster', 'image' => 'hamster-v6.webp', 'width' => 2400, 'height' => 1600, 'name' => 'Hamsters', 'alt' => 'Golden and white hamster holding a small snack on a rock', 'author' => 'Sharon Snider', 'source' => 'https://www.pexels.com/photo/close-up-photo-of-cute-hamster-4520484/', 'license_name' => 'Pexels License', 'license_url' => 'https://www.pexels.com/license/', 'changes' => 'Resized and converted to WebP; the desktop backdrop uses a blurred copy of the same photo.' ),
    array( 'slug' => 'chinchilla', 'image' => 'chinchilla-v6.webp', 'width' => 2400, 'height' => 1600, 'name' => 'Chinchillas', 'alt' => 'Grey chinchilla holding a small snack against a peach background', 'author' => 'Nikolett Emmert', 'source' => 'https://unsplash.com/photos/a-chinchilla-eating-food-on-a-pink-background-WP8KvAMjCxs', 'license_name' => 'Unsplash License', 'license_url' => 'https://unsplash.com/license' ),
);

if ( $petish_home_commerce ) {
    // Explicit public status and generic context keep draft imports and pet data out.
    $petish_home_product_args = array(
        'status'     => 'publish',
        'type'       => array( 'simple', 'variable' ),
        'visibility' => 'catalog',
        'limit'      => 4,
        'orderby'    => 'date',
        'order'      => 'DESC',
    );
    if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
        $petish_home_product_args['stock_status'] = array( 'instock', 'onbackorder' );
    }
    foreach ( wc_get_products( $petish_home_product_args ) as $petish_home_product ) {
        if ( $petish_home_product instanceof WC_Product && 'publish' === $petish_home_product->get_status() && $petish_home_product->is_visible() ) {
            $petish_home_products[] = $petish_home_product;
        }
    }
    if ( function_exists( 'petish_supplier_hot_badge' ) || function_exists( 'petish_supplier_free_delivery_eligible' ) ) {
        // Inspect public products once; each section keeps its own eligibility rule.
        $petish_home_hot_args = array_replace( $petish_home_product_args, array( 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
        foreach ( wc_get_products( $petish_home_hot_args ) as $petish_home_product ) {
            if ( ! $petish_home_product instanceof WC_Product || 'publish' !== $petish_home_product->get_status()
                || ! $petish_home_product->is_visible() ) {
                continue;
            }
            if ( function_exists( 'petish_supplier_hot_badge' ) && '' !== petish_supplier_hot_badge( $petish_home_product ) ) {
                $petish_home_hot[] = $petish_home_product;
            }
            if ( function_exists( 'petish_supplier_free_delivery_eligible' ) && petish_supplier_free_delivery_eligible( $petish_home_product ) ) {
                $petish_home_free[] = $petish_home_product;
            }
        }
        usort( $petish_home_hot, static function ( $left, $right ) {
            $order = (int) $right->get_meta( '_petish_supplier_order_count', true, 'edit' ) <=> (int) $left->get_meta( '_petish_supplier_order_count', true, 'edit' );
            if ( 0 === $order ) { $order = strnatcasecmp( $left->get_name(), $right->get_name() ); }
            return 0 !== $order ? $order : $left->get_id() <=> $right->get_id();
        } );
        $petish_home_hot = array_slice( $petish_home_hot, 0, 4 );
        if ( function_exists( 'petish_supplier_free_delivery_compare' ) ) {
            usort( $petish_home_free, 'petish_supplier_free_delivery_compare' );
            $petish_home_free = array_slice( $petish_home_free, 0, 4 );
        }
    }
}

$petish_home_categories = array(
    array( 'slug' => 'enrichment-play', 'name' => 'Enrichment & play', 'icon' => 'play', 'image' => 'enrichment-play-v1.webp', 'detail' => 'Foraging, puzzles and little discoveries for curious pets.' ),
    array( 'slug' => 'hides-tunnels', 'name' => 'Hides & tunnels', 'icon' => 'hide', 'image' => 'hides-tunnels-v1.webp', 'detail' => 'Little spaces to investigate, retreat and rest.' ),
    array( 'slug' => 'feeding-hay', 'name' => 'Feeding & hay', 'icon' => 'bowl', 'image' => 'feeding-hay-v2.webp', 'detail' => 'Bowls, feeders and accessories for daily routines.' ),
    array( 'slug' => 'bedding-liners', 'name' => 'Bedding & liners', 'icon' => 'bedding', 'image' => 'bedding-liners-v2.webp', 'detail' => 'Explore the little layers that make up their space.' ),
    array( 'slug' => 'cleaning-care', 'name' => 'Cleaning & care', 'icon' => 'clean', 'image' => 'cleaning-care-v2.webp', 'detail' => 'Practical tools for the everyday tidy-up.' ),
    array( 'slug' => 'travel-carriers', 'name' => 'Travel & carriers', 'icon' => 'bag', 'image' => '', 'detail' => 'Carriers and accessories for trips with your small pet.' ),
);
$petish_home_species = array( 'rabbit' => 'Rabbits', 'guinea-pig' => 'Guinea pigs', 'hamster' => 'Hamsters', 'chinchilla' => 'Chinchillas' );
$petish_home_icon = static function ( $name ) {
    $paths = array(
        'arrow'    => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'clean'    => '<path d="m15 3-4 9M6 12l8 3-2 6H3l3-9Zm0 8 2-5m2 5 1-4M17 5h4m-2-2v4"/>',
        'bedding'  => '<path d="m3 8 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 18l9 4 9-4M5 5V2m3 2V2"/>',
        'bowl'     => '<path d="M3 12h18a9 9 0 0 1-18 0Zm3 8h12M8 8 6 3m6 5V2m4 6 2-5"/>',
        'hide'     => '<path d="M3 20V11a9 9 0 0 1 18 0v9H3Zm5 0v-8a4 4 0 0 1 8 0v8M7 5l2 2m6-2-2 2"/>',
        'bag'      => '<path d="M5 8h14l1 13H4L5 8Zm3 0V6a4 4 0 0 1 8 0v2"/><path d="m9 14 2 2 4-4"/>',
        'passport' => '<rect x="4" y="2" width="16" height="20" rx="3"/><path d="M8 17h8M8 19h5"/><ellipse cx="12" cy="11" rx="3" ry="2.5"/><path d="M8.5 7v.1M12 6v.1m3.5.9v.1"/>',
        'check'    => '<path d="m5 12 4 4L19 6"/>',
    );
    return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? $paths['arrow'] ) . '</svg>';
};
$petish_home_render_product = static function ( $product ) use ( $petish_home_icon ) {
    $name = $product->get_name();
    $price = $product->get_price_html();
    ?>
    <article class="ph-product">
        <a class="ph-product-link" href="<?php echo esc_url( $product->get_permalink() ); ?>">
            <span class="ph-product-image"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'decoding' => 'async' ) ) ); ?><?php if ( function_exists( 'petish_supplier_free_delivery_badge' ) ) { echo wp_kses_post( petish_supplier_free_delivery_badge( $product ) ); } ?></span>
            <div class="ph-product-body">
                <?php if ( class_exists( 'Petish_Catalogue' ) && method_exists( 'Petish_Catalogue', 'reviews_visible' ) && Petish_Catalogue::reviews_visible() ) : ?>
                    <span class="ph-product-review"><?php echo esc_html( Petish_Catalogue::label( $product, '' ) ); ?></span>
                <?php endif; ?>
                <h3 title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></h3>
                <?php if ( $price ) : ?><span class="ph-product-price"><?php echo wp_kses_post( $price ); ?></span><?php endif; ?>
                <span class="ph-card-link"><?php esc_html_e( 'View product', 'petish' ); ?><?php echo $petish_home_icon( 'arrow' ); ?></span>
            </div>
        </a>
    </article>
    <?php
};

// Keep photo attribution in the actual footer, scoped to this homepage request.
add_action( 'dynamic_sidebar_after', static function ( $sidebar_id ) use ( $petish_home_photos ) {
    static $rendered = false;
    if ( $rendered || ! is_front_page() || ! function_exists( 'presscore_validate_footer_sidebar' ) || ! function_exists( 'presscore_config' ) ) {
        return;
    }
    $footer_sidebar = presscore_validate_footer_sidebar( presscore_config()->get( 'footer_widgetarea_id' ) );
    if ( $sidebar_id !== $footer_sidebar ) {
        return;
    }
    $rendered = true;
    ?>
    <details class="ph-photo-credits"><summary><?php esc_html_e( 'Photo credits', 'petish' ); ?></summary><ul>
        <?php foreach ( $petish_home_photos as $petish_home_photo ) : ?>
            <li><a href="<?php echo esc_url( $petish_home_photo['source'] ); ?>" rel="noopener noreferrer" target="_blank"><?php echo esc_html( $petish_home_photo['name'] . ' — ' . $petish_home_photo['author'] ); ?></a><?php if ( isset( $petish_home_photo['license_url'], $petish_home_photo['license_name'] ) ) : ?> · <a href="<?php echo esc_url( $petish_home_photo['license_url'] ); ?>" rel="license noopener noreferrer" target="_blank"><?php echo esc_html( $petish_home_photo['license_name'] ); ?></a><?php endif; ?><?php if ( isset( $petish_home_photo['changes'] ) ) : ?>. <?php echo esc_html( $petish_home_photo['changes'] ); ?><?php endif; ?></li>
        <?php endforeach; ?>
    </ul></details>
    <?php
}, 10, 1 );

get_header();
?>
<div id="content" class="content petish-home-content" role="main">
    <div class="petish-home">
        <section class="ph-hero" aria-labelledby="petish-home-title" data-petish-hero>
            <div class="ph-hero-slides">
                <?php foreach ( $petish_home_photos as $petish_home_photo_index => $petish_home_photo ) : ?>
                    <figure id="petish-hero-<?php echo esc_attr( $petish_home_photo['slug'] ); ?>" class="ph-hero-slide ph-hero-slide-<?php echo esc_attr( $petish_home_photo['slug'] ); ?>" role="group" aria-roledescription="slide" aria-label="<?php echo esc_attr( $petish_home_photo['name'] ); ?>" data-petish-hero-slide<?php echo 0 === $petish_home_photo_index ? '' : ' hidden'; ?>>
                        <img src="<?php echo esc_url( $petish_home_images . 'hero/' . $petish_home_photo['image'] ); ?>" alt="<?php echo esc_attr( $petish_home_photo['alt'] ); ?>" width="<?php echo esc_attr( $petish_home_photo['width'] ); ?>" height="<?php echo esc_attr( $petish_home_photo['height'] ); ?>" decoding="async" <?php echo 0 === $petish_home_photo_index ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
                    </figure>
                <?php endforeach; ?>
            </div>
            <div class="ph-hero-inner">
            <div class="ph-hero-copy">
                <p class="ph-eyebrow"><span aria-hidden="true"></span><?php esc_html_e( 'A little world for small pets', 'petish' ); ?></p>
                <h1 id="petish-home-title"><?php esc_html_e( 'Small pets,', 'petish' ); ?><br><span><?php esc_html_e( 'big adventures.', 'petish' ); ?></span></h1>
                <p class="ph-hero-intro"><?php esc_html_e( 'Everyday discoveries for rabbits, guinea pigs, hamsters and chinchillas. We’re building a collection with the little details in mind.', 'petish' ); ?></p>
                <div class="ph-actions">
                    <a class="petish-button" href="#petish-collection"><span class="ph-desktop-label"><?php esc_html_e( 'Browse collection', 'petish' ); ?></span><span class="ph-mobile-label"><?php esc_html_e( 'Browse', 'petish' ); ?></span><?php echo $petish_home_icon( 'arrow' ); // Static decorative SVG. ?></a>
                    <a class="petish-button petish-button-secondary" href="#petish-passport"><span class="ph-desktop-label"><?php esc_html_e( 'Meet Petish Passport', 'petish' ); ?></span><span class="ph-mobile-label"><?php esc_html_e( 'Petish Passport', 'petish' ); ?></span></a>
                </div>
                <p class="ph-hero-note"><?php esc_html_e( 'Little personalities. Plenty to explore.', 'petish' ); ?></p>
            </div>
            <div class="ph-hero-controls" role="group" aria-label="<?php esc_attr_e( 'Choose a pet photograph', 'petish' ); ?>" data-petish-hero-controls hidden>
                <?php foreach ( $petish_home_photos as $petish_home_photo_index => $petish_home_photo ) : ?>
                    <button type="button" aria-controls="petish-hero-<?php echo esc_attr( $petish_home_photo['slug'] ); ?>" aria-pressed="<?php echo 0 === $petish_home_photo_index ? 'true' : 'false'; ?>" data-petish-hero-button><?php echo esc_html( $petish_home_photo['name'] ); ?></button>
                <?php endforeach; ?>
            </div>
            <p class="ph-sr-only" aria-live="polite" aria-atomic="true" data-petish-hero-status></p>
            </div>
        </section>
        <section class="ph-species" aria-labelledby="petish-species-title">
            <div class="ph-species-intro"><p class="ph-overline"><?php esc_html_e( 'Start with your little companion', 'petish' ); ?></p><h2 id="petish-species-title"><?php esc_html_e( 'Explore by pet', 'petish' ); ?></h2></div>
            <div class="ph-species-links">
                <?php foreach ( $petish_home_species as $petish_home_slug => $petish_home_species_name ) : ?>
                    <?php $petish_home_species_url = $petish_home_commerce ? add_query_arg( 'petish_species', $petish_home_slug, wc_get_page_permalink( 'shop' ) ) : '#petish-collection'; ?>
                    <a href="<?php echo esc_url( $petish_home_species_url ); ?>"><span><?php echo esc_html( $petish_home_species_name ); ?></span><?php echo $petish_home_icon( 'arrow' ); ?></a>
                <?php endforeach; ?>
            </div>
            <p class="ph-species-note"><?php esc_html_e( 'Explore by pet, then check each product’s dimensions, materials and options before choosing.', 'petish' ); ?></p>
        </section>

        <section class="ph-section ph-categories" aria-labelledby="petish-category-title">
            <div class="ph-section-heading"><div><p class="ph-overline"><?php esc_html_e( 'The everyday little things', 'petish' ); ?></p><h2 id="petish-category-title"><?php esc_html_e( 'A place to start.', 'petish' ); ?></h2></div><p><?php esc_html_e( 'Find enrichment, cosy spaces and everyday essentials. Check the size, materials and selected options for your pet.', 'petish' ); ?></p></div>
            <div class="ph-category-grid">
                <?php foreach ( $petish_home_categories as $petish_home_category ) : ?>
                    <?php
                    $petish_home_category_url = $petish_home_shop;
                    $petish_home_term = null;
                    if ( $petish_home_commerce && taxonomy_exists( 'product_cat' ) ) {
                        $petish_home_term = get_term_by( 'slug', $petish_home_category['slug'], 'product_cat' );
                        $petish_home_term_url = $petish_home_term ? get_term_link( $petish_home_term ) : false;
                        if ( $petish_home_term_url && ! is_wp_error( $petish_home_term_url ) ) {
                            $petish_home_category_url = add_query_arg( 'petish_species', 'all', $petish_home_term_url );
                        }
                    }
                    $petish_home_category_image = '';
                    if ( $petish_home_category['image'] ) {
                        $petish_home_category_image = '<img src="' . esc_url( $petish_home_images . 'categories/' . $petish_home_category['image'] ) . '" alt="" width="960" height="720" loading="lazy" decoding="async">';
                    } elseif ( $petish_home_term ) {
                        $petish_home_category_image = wp_get_attachment_image( absint( get_term_meta( $petish_home_term->term_id, 'thumbnail_id', true ) ), 'medium_large', false, array( 'alt' => '', 'loading' => 'lazy' ) );
                    }
                    ?>
                    <a class="ph-category ph-category-<?php echo esc_attr( $petish_home_category['icon'] ); ?>" href="<?php echo esc_url( $petish_home_category_url ); ?>">
                        <span class="ph-category-image"><?php echo $petish_home_category_image; ?></span>
                        <div class="ph-category-body"><h3><?php echo esc_html( $petish_home_category['name'] ); ?></h3>
                            <p><?php echo esc_html( $petish_home_category['detail'] ); ?></p>
                            <span class="ph-card-link"><?php esc_html_e( 'Explore category', 'petish' ); ?><?php echo $petish_home_icon( 'arrow' ); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="ph-category-note"><?php esc_html_e( 'Category images are illustrative. See individual listings for product photos and details.', 'petish' ); ?></p>
        </section>

        <?php if ( $petish_home_hot ) : ?>
            <section id="petish-hot-items" class="ph-section ph-collection ph-hot-items" aria-labelledby="petish-hot-items-title">
                <div class="ph-section-heading"><div><h2 id="petish-hot-items-title"><?php esc_html_e( 'Hot items', 'petish' ); ?></h2></div><a class="ph-text-link" href="<?php echo esc_url( add_query_arg( 'orderby', 'petish_supplier_popularity', $petish_home_shop ) ); ?>"><?php esc_html_e( 'Browse popular products', 'petish' ); ?><?php echo $petish_home_icon( 'arrow' ); ?></a></div>
                <div class="ph-product-grid">
                    <?php foreach ( $petish_home_hot as $petish_home_product ) : ?>
                        <?php $petish_home_render_product( $petish_home_product ); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ( $petish_home_free ) : ?>
            <section id="petish-free-delivery" class="ph-section ph-collection ph-free-delivery" aria-labelledby="petish-free-delivery-title">
                <div class="ph-section-heading"><div><h2 id="petish-free-delivery-title"><?php esc_html_e( 'Free delivery favourites', 'petish' ); ?></h2><p><?php echo esc_html( petish_supplier_free_delivery_destination_label() ); ?></p></div></div>
                <div class="ph-product-grid">
                    <?php foreach ( $petish_home_free as $petish_home_product ) : ?>
                        <?php $petish_home_render_product( $petish_home_product ); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section id="petish-collection" class="ph-section ph-collection" aria-labelledby="petish-collection-title">
            <div class="ph-section-heading"><div><p class="ph-overline"><?php esc_html_e( 'The Petish collection', 'petish' ); ?></p><h2 id="petish-collection-title"><?php echo esc_html( $petish_home_products ? __( 'A few little discoveries.', 'petish' ) : __( 'Our next little adventure.', 'petish' ) ); ?></h2></div><?php if ( $petish_home_products ) : ?><a class="ph-text-link" href="<?php echo esc_url( $petish_home_shop ); ?>"><?php esc_html_e( 'Browse all products', 'petish' ); ?><?php echo $petish_home_icon( 'arrow' ); ?></a><?php endif; ?></div>
            <?php if ( $petish_home_products ) : ?>
                <div class="ph-product-grid">
                    <?php foreach ( $petish_home_products as $petish_home_product ) : ?>
                        <?php $petish_home_render_product( $petish_home_product ); ?>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="ph-collection-empty">
                    <div class="ph-empty-art" aria-hidden="true"><?php echo $petish_home_icon( 'bag' ); ?><span></span></div>
                    <div class="ph-empty-copy"><span class="ph-status"><?php esc_html_e( 'Collection in progress', 'petish' ); ?></span><h3><?php esc_html_e( 'Good things take a little care.', 'petish' ); ?></h3><p><?php esc_html_e( 'We’re choosing products and checking their details. Our first collection will appear here when it’s ready.', 'petish' ); ?></p><?php if ( $petish_home_commerce ) : ?><a class="ph-text-link" href="<?php echo esc_url( $petish_home_shop ); ?>"><?php esc_html_e( 'Explore the shop', 'petish' ); ?><?php echo $petish_home_icon( 'arrow' ); ?></a><?php endif; ?></div>
                </div>
            <?php endif; ?>
        </section>

        <section id="petish-passport" class="ph-passport" aria-labelledby="petish-passport-title">
            <div class="ph-passport-emblem" aria-hidden="true"><span><?php echo $petish_home_icon( 'passport' ); ?></span><p>Petish<br><strong>Passport</strong></p><span class="ph-passport-stamp"><?php echo $petish_home_icon( 'check' ); ?></span></div>
            <div class="ph-passport-copy"><p class="ph-overline"><?php esc_html_e( 'Their own little corner', 'petish' ); ?></p><h2 id="petish-passport-title"><?php esc_html_e( 'A Passport for every personality.', 'petish' ); ?></h2>
                <?php if ( $petish_home_passport ) : ?>
                    <p><?php esc_html_e( 'Create private profiles for your small pets, switch between them, and return to their reviewed collections as the range grows.', 'petish' ); ?></p>
                    <ul class="ph-passport-points"><li><?php echo $petish_home_icon( 'check' ); ?><?php esc_html_e( 'Keep every pet together in one account', 'petish' ); ?></li><li><?php echo $petish_home_icon( 'check' ); ?><?php esc_html_e( 'Add their details and an optional photo', 'petish' ); ?></li><li><?php echo $petish_home_icon( 'check' ); ?><?php esc_html_e( 'Your profiles stay private to you', 'petish' ); ?></li></ul>
                    <div class="ph-actions"><a class="petish-button" href="<?php echo esc_url( $petish_home_pets ); ?>"><?php esc_html_e( 'Go to My Pets', 'petish' ); ?><?php echo $petish_home_icon( 'arrow' ); ?></a><span class="ph-passport-note"><?php esc_html_e( 'Optional for browsing. Always your choice.', 'petish' ); ?></span></div>
                <?php else : ?>
                    <p><?php esc_html_e( 'A private place for your pet’s details and little personality. Petish Passport is being prepared — check back as the site takes shape.', 'petish' ); ?></p>
                <?php endif; ?>
            </div>
        </section>

        <section id="about-petish" class="ph-about" aria-labelledby="petish-about-title"><p class="ph-overline"><?php esc_html_e( 'Hello, we’re Petish', 'petish' ); ?></p><h2 id="petish-about-title"><?php esc_html_e( 'For the little ones who mean a lot.', 'petish' ); ?></h2><p><?php esc_html_e( 'We’re bringing practical small-pet accessories and a little more personality to everyday routines. Our collection is growing carefully, one product review at a time.', 'petish' ); ?></p></section>
    </div>
</div>
<?php get_footer(); ?>
