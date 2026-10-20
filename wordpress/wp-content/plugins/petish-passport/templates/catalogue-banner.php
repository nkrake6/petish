<?php defined( 'ABSPATH' ) || exit; ?>
<aside class="petish-catalogue pc-shop-sidebar" aria-label="Filter products">
    <details class="pc-sidebar-panel" open>
        <summary id="petish-catalogue-title">Filter products</summary>
        <div class="pc-sidebar-content">
            <nav class="pc-category-nav" aria-label="Product categories">
                <h2>Categories</h2>
                <ul>
                    <li><a href="<?php echo esc_url( add_query_arg( array_merge( $category_args, array( 'petish_species' => 'all' ) ), wc_get_page_permalink( 'shop' ) ) ); ?>" <?php if ( is_shop() && ! is_search() && ! $context['species'] ) { echo 'aria-current="page"'; } ?>>All products</a></li>
                    <?php foreach ( $categories as $category ) : $category_url = get_term_link( $category ); if ( is_wp_error( $category_url ) ) { continue; } ?>
                    <li><a href="<?php echo esc_url( add_query_arg( $category_args, $category_url ) ); ?>" <?php if ( $current_category === (int) $category->term_id ) { echo 'aria-current="page"'; } ?>><?php echo esc_html( $category->name ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <form method="get" action="<?php echo esc_url( self::archive_base() ); ?>" class="pc-price-filter">
                <h2><?php echo esc_html( 'Price (' . get_option( 'woocommerce_currency', 'AUD' ) . ' base)' ); ?></h2>
                <?php foreach ( $browse_args as $key => $value ) : if ( in_array( $key, array( 'min_price', 'max_price' ), true ) ) { continue; } ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endforeach; ?>
                <div class="pc-price-fields">
                    <div><label for="petish-min-price">Minimum</label><input id="petish-min-price" name="min_price" type="number" inputmode="decimal" min="0" step="any" placeholder="0" value="<?php echo esc_attr( isset( $browse_args['min_price'] ) ? wc_format_decimal( $browse_args['min_price'] ) : '' ); ?>"></div>
                    <div><label for="petish-max-price">Maximum</label><input id="petish-max-price" name="max_price" type="number" inputmode="decimal" min="0" step="any" placeholder="Any" value="<?php echo esc_attr( isset( $browse_args['max_price'] ) ? wc_format_decimal( $browse_args['max_price'] ) : '' ); ?>"></div>
                </div>
                <button type="submit" class="pc-button pc-secondary">Apply price</button>
                <?php if ( isset( $browse_args['min_price'] ) || isset( $browse_args['max_price'] ) ) : ?><a class="pc-reset" href="<?php echo esc_url( add_query_arg( array_diff_key( $browse_args, array_flip( array( 'min_price', 'max_price' ) ) ), self::archive_base() ) ); ?>">Clear price filter</a><?php endif; ?>
            </form>

            <form method="get" action="<?php echo esc_url( self::archive_base() ); ?>" class="pc-filter pc-species-filter">
                <?php foreach ( self::public_args() as $key => $value ) : ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endforeach; ?>
                <div><label for="petish-species-filter">Browse by pet</label><select id="petish-species-filter" name="petish_species">
                    <option value="all" <?php selected( $context['species'], '' ); ?>>All pets</option>
                    <?php foreach ( Petish_Product_Review::species() as $key => $name ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $context['species'], $key ); ?>><?php echo esc_html( $name . ( self::reviews_visible() ? ' — reviewed collection' : '' ) ); ?></option><?php endforeach; ?>
                </select></div><button type="submit" class="pc-button pc-secondary">Apply pet</button>
            </form>
            <?php if ( $context['species'] ) : ?><a class="pc-reset" href="<?php echo esc_url( self::all_url() ); ?>">Clear species filter</a><?php endif; ?>

            <div class="pc-passport-controls">
                <?php if ( $pets ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-filter pc-pet-switch">
                    <input type="hidden" name="action" value="petish_catalogue_active"><input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>">
                    <?php wp_nonce_field( 'petish_catalogue_active', 'petish_catalogue_nonce' ); ?>
                    <div><label for="petish-shopping-pet">Your active pet</label><select id="petish-shopping-pet" name="pet_id"><?php foreach ( $pets as $item ) : ?><option value="<?php echo esc_attr( $item['id'] ); ?>" <?php selected( $context['pet']['id'] ?? 0, $item['id'] ); ?>><?php echo esc_html( $item['name'] . ' — ' . Petish_Passport_Store::species()[ $item['species'] ] ); ?></option><?php endforeach; ?></select></div>
                    <button type="submit" class="pc-button pc-secondary">Switch pet</button>
                </form>
                <a class="pc-reset" href="<?php echo esc_url( wc_get_account_endpoint_url( 'my-pets' ) ); ?>">Manage My Pets</a>
                <?php else : ?><a class="pc-reset" href="<?php echo esc_url( wc_get_account_endpoint_url( 'my-pets' ) ); ?>">Create a Petish Passport</a><p class="pc-small">Save your pet’s details, or shop without one.</p><?php endif; ?>
            </div>
        </div>
    </details>
</aside>
