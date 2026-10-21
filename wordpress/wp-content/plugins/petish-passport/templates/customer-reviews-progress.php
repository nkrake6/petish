<?php defined( 'ABSPATH' ) || exit;
$qualifying = max( 0, (int) ( $metrics['qualifying'] ?? 0 ) );
$tier = is_array( $metrics['tier'] ?? null ) ? $metrics['tier'] : Petish_Customer_Reviews::tier( $qualifying );
$next = max( 1, (int) ( $tier['next_threshold'] ?? 500 ) );
$next = max( $next, min( 500, $qualifying ) );
?>
<section class="pcr-panel pcr-soft pcr-progress" aria-label="<?php esc_attr_e( 'Reviewer status and progress', 'petish-passport' ); ?>">
    <p class="pcr-eyebrow"><?php esc_html_e( 'Your little contributions', 'petish-passport' ); ?></p>
    <div class="pcr-progress-heading"><h3><?php echo esc_html( $tier['title'] ?? __( 'Community member', 'petish-passport' ) ); ?></h3><strong><?php echo esc_html( number_format_i18n( $qualifying ) ); ?> <span class="pcr-small"><?php esc_html_e( 'qualifying reviews', 'petish-passport' ); ?></span></strong></div>
    <progress value="<?php echo esc_attr( min( $qualifying, $next ) ); ?>" max="<?php echo esc_attr( $next ); ?>" aria-label="<?php esc_attr_e( 'Qualifying approved review progress', 'petish-passport' ); ?>"><?php echo esc_html( $qualifying . ' / ' . $next ); ?></progress>
    <p class="pcr-small"><?php echo esc_html( $qualifying < 500 ? sprintf( __( '%1$d more qualifying reviews to %2$s.', 'petish-passport' ), max( 0, $next - $qualifying ), $tier['next_title'] ?? __( 'your next status', 'petish-passport' ) ) : __( 'You have reached Petish Legend. Thank you for sharing your experience.', 'petish-passport' ) ); ?></p>
    <ol class="pcr-milestones"><?php foreach ( array( 50, 100, 250, 500 ) as $threshold ) : $level = Petish_Customer_Reviews::tier( $threshold ); ?><li class="pcr-tier-<?php echo esc_attr( $level['key'] ); ?>"><span class="pcr-ring" aria-hidden="true"></span><span><?php echo esc_html( $level['title'] ); ?></span><span><?php echo esc_html( $threshold ); ?></span></li><?php endforeach; ?></ol>
    <p class="pcr-small pcr-benefit-note"><?php esc_html_e( 'Status recognises approved reviews. Discount and delivery rewards are not active yet.', 'petish-passport' ); ?></p>
</section>
