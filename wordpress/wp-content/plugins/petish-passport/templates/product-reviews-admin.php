<?php defined( 'ABSPATH' ) || exit; ?>
<div id="petish_review_data" class="panel woocommerce_options_panel hidden">
    <div class="petish-admin-intro">
        <h2>Petish species reviews</h2>
        <p>Only a complete, current review includes a product in a species collection. This is an editorial assessment with stated limitations, not a safety guarantee or veterinary advice.</p>
        <p>Reviews apply to the complete product and every available variation. Product specification or variation changes can invalidate a review. Simple and variable products are supported; grouped, external and individual variation records remain unassessed.</p>
    </div>
    <input type="hidden" name="petish_review_marker" value="1">
    <?php wp_nonce_field( 'petish_review_' . $product->get_id(), 'petish_review_nonce' ); ?>
    <?php foreach ( Petish_Product_Review::species() as $species => $label ) :
        $record = Petish_Product_Review::raw( $product, $species );
        $effective = Petish_Product_Review::record( $product, $species );
    ?>
    <details class="petish-admin-review">
        <summary><?php echo esc_html( $label ); ?> <span>— <?php echo esc_html( 'eligible' === $effective['status'] ? 'Reviewed for collection' : ( 'excluded' === $effective['status'] ? 'Excluded after review' : 'Not yet assessed' ) ); ?></span></summary>
        <p><label for="petish-<?php echo esc_attr( $species ); ?>-status">Review state</label>
            <select id="petish-<?php echo esc_attr( $species ); ?>-status" name="petish_reviews[<?php echo esc_attr( $species ); ?>][status]">
                <?php foreach ( array( 'unreviewed' => 'Not yet assessed', 'eligible' => 'Include after review', 'excluded' => 'Exclude after review' ) as $value => $text ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $record['status'], $value ); ?>><?php echo esc_html( $text ); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p><label for="petish-<?php echo esc_attr( $species ); ?>-source">Public evidence / source URL</label>
            <input type="url" id="petish-<?php echo esc_attr( $species ); ?>-source" name="petish_reviews[<?php echo esc_attr( $species ); ?>][source]" value="<?php echo esc_attr( $record['source'] ); ?>" maxlength="2048" placeholder="https://">
        </p>
        <div class="petish-admin-dates">
            <p><label for="petish-<?php echo esc_attr( $species ); ?>-date">Reviewed on</label><input type="date" id="petish-<?php echo esc_attr( $species ); ?>-date" name="petish_reviews[<?php echo esc_attr( $species ); ?>][date]" value="<?php echo esc_attr( $record['date'] ); ?>" max="<?php echo esc_attr( Petish_Product_Review::today() ); ?>"></p>
            <p><label for="petish-<?php echo esc_attr( $species ); ?>-due">Review due</label><input type="date" id="petish-<?php echo esc_attr( $species ); ?>-due" name="petish_reviews[<?php echo esc_attr( $species ); ?>][due]" value="<?php echo esc_attr( $record['due'] ); ?>" min="<?php echo esc_attr( Petish_Product_Review::today() ); ?>"></p>
        </div>
        <p><label for="petish-<?php echo esc_attr( $species ); ?>-limits">Reason and limitations</label><textarea id="petish-<?php echo esc_attr( $species ); ?>-limits" name="petish_reviews[<?php echo esc_attr( $species ); ?>][limits]" rows="4" maxlength="1500"><?php echo esc_textarea( $record['limits'] ); ?></textarea><span class="description">Record the evidence, size/age/supervision constraints and any exclusions. Never use a species tag as the review itself.</span></p>
    </details>
    <?php endforeach; ?>
    <p class="petish-admin-confirm"><label><input type="checkbox" name="petish_review_confirm" value="1"> Apply these review fields: I have checked the evidence against the current product specifications and all its variations.</label><span class="description">Tick this when saving review changes. Leave unticked for ordinary price or stock updates. Expired, incomplete or invalidated reviews do not qualify for collections.</span></p>
</div>
