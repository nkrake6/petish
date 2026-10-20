<?php defined( 'ABSPATH' ) || exit; ?>
<div class="petish-passport petish-collection">
    <a class="pp-back" href="<?php echo esc_url( wc_get_account_endpoint_url( 'my-pets' ) ); ?>">← Back to My Pets</a>
    <header class="pp-page-heading"><div><p class="pp-eyebrow">Petish Passport</p><h2><?php echo esc_html( $pet ? 'For ' . $pet['name'] : 'A collection for your pet' ); ?></h2>
        <p class="pp-intro"><?php echo esc_html( $species ? 'Products with a current ' . Petish_Product_Review::species()[ $species ] . ' review.' : 'Choose a supported pet to explore reviewed collections.' ); ?></p></div></header>
    <p class="pp-privacy"><?php echo Petish_Passport_UI::icon( 'lock' ); ?>Your active pet stays private to your account.</p>
    <?php if ( ! $pet ) : ?><div class="pp-panel"><h3>Your first adventure starts with a Passport</h3><p>Create a pet profile, or browse the shop without one.</p><a class="pp-button" href="<?php echo esc_url( wc_get_account_endpoint_url( 'my-pets' ) ); ?>">Go to My Pets</a></div>
    <?php elseif ( ! $species ) : ?><div class="pp-panel"><h3>More species are still to come</h3><p>We cannot infer which products suit “Other small pet”. Browse all products and check individual guidance.</p></div>
    <?php else : ?>
        <p class="pp-small">This collection uses the active pet’s species. Age and shopping interest are saved for later features. Check the source, dimensions and limitations before choosing a product.</p>
        <?php if ( ! $results->products ) : ?><section class="pp-panel pc-empty"><h3>No reviewed products here yet</h3><p>We are building the catalogue and reviewing products before including them. Nothing is automatically approved from its species tag.</p><?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( self::url() ); ?>">Return to the first page</a><?php endif; ?></section>
        <?php else : ?>
        <p class="pp-small"><?php echo esc_html( sprintf( _n( '%d reviewed product · page %d of %d', '%d reviewed products · page %d of %d', $results->total, 'petish-passport' ), $results->total, $page, $results->max_num_pages ) ); ?></p>
        <div class="pc-product-grid">
        <?php foreach ( $results->products as $item ) : ?>
            <article class="pc-product pp-panel" data-product-id="<?php echo esc_attr( $item->get_id() ); ?>">
                <a href="<?php echo esc_url( $item->get_permalink() ); ?>" class="pc-product-image"><?php echo wp_kses_post( $item->get_image( 'woocommerce_thumbnail' ) ); ?></a>
                <p class="petish-review-label"><?php echo esc_html( self::label( $item, $species ) ); ?></p>
                <h3><a href="<?php echo esc_url( $item->get_permalink() ); ?>"><?php echo esc_html( $item->get_name() ); ?></a></h3>
                <?php if ( $item->get_price_html() ) : ?><p class="pc-price"><?php echo wp_kses_post( $item->get_price_html() ); ?></p><?php endif; ?>
                <?php if ( ! $item->is_in_stock() ) : ?><p class="pp-small">Currently out of stock</p><?php endif; ?>
                <a class="pp-button pp-button-secondary" href="<?php echo esc_url( $item->get_permalink() . '#petish-review' ); ?>">View product and review</a>
            </article>
        <?php endforeach; ?>
        </div>
        <?php if ( $results->max_num_pages > 1 ) : ?><nav aria-label="Collection pages" class="pc-pagination"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'collection_page', '%#%', self::url() ), 'format' => '', 'current' => $page, 'total' => $results->max_num_pages ) ) ); ?></nav><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    <p class="pc-collection-reset"><a class="pp-button pp-button-secondary" href="<?php echo esc_url( add_query_arg( 'petish_species', 'all', wc_get_page_permalink( 'shop' ) ) ); ?>">Browse all products</a></p>
</div>
