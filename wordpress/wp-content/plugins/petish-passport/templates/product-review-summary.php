<?php defined( 'ABSPATH' ) || exit; ?>
<section class="petish-product-review" id="petish-review" aria-labelledby="petish-review-title">
    <h2 id="petish-review-title">Species review</h2>
    <p class="pc-small">Read the evidence and limitations. Species alone does not establish suitability, and this is not veterinary advice.</p>
    <?php foreach ( Petish_Product_Review::species() as $species => $label ) : $record = Petish_Product_Review::record( $product, $species ); ?>
    <details <?php if ( $context['species'] === $species ) { echo 'open'; } ?>>
        <summary><?php echo esc_html( $label . ' — ' . ( 'eligible' === $record['status'] ? 'Reviewed for collection' : ( 'excluded' === $record['status'] ? 'Excluded after review' : 'Not yet assessed' ) ) ); ?></summary>
        <?php if ( 'unreviewed' === $record['status'] ) : ?><p>No current assessment is available for this species. Do not infer suitability from a category or species label.</p>
        <?php else : ?><p><?php echo nl2br( esc_html( $record['limits'] ) ); ?></p><p class="pc-small">Reviewed <?php echo esc_html( $record['date'] ); ?> · Review due <?php echo esc_html( $record['due'] ); ?></p><p><a href="<?php echo esc_url( $record['source'] ); ?>" target="_blank" rel="noopener noreferrer">Read the review source <span class="screen-reader-text">(opens in a new tab)</span></a></p><?php endif; ?>
    </details>
    <?php endforeach; ?>
    <?php if ( $product->is_type( 'variable' ) ) : ?><p class="pc-small">A current review covers all available variations. Changes to variations require another review.</p><?php endif; ?>
</section>
