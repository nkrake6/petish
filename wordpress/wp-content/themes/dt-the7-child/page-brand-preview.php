<?php
/**
 * Local visual QA page; no customer data or commerce behaviour.
 */
defined( 'ABSPATH' ) || exit;
$config = presscore_config();
$config->set( 'template', 'page' );
$config->set( 'sidebar_position', 'disabled' );
$config->set( 'page_title.enabled', false );
$config->set( 'page_title.breadcrumbs.enabled', false );
get_header();
$brand_uri = get_stylesheet_directory_uri() . '/assets/brand/';
?>
<div id="content" class="content" role="main">
  <article class="petish-preview" aria-label="Petish brand component preview">
    <section class="petish-card petish-hero" aria-labelledby="petish-preview-title">
      <div>
        <span class="petish-eyebrow">Petish · Brand preview</span>
        <h1 id="petish-preview-title">Small pets,<br>big adventures.</h1>
        <p>A friendly visual system for Petish. Explore the typography, colours and everyday controls in light and dark modes.</p>
        <p class="petish-caption">Local design preview. The components below demonstrate appearance and interaction; they do not create a Passport or place an order.</p>
        <div class="petish-action-row">
          <a class="petish-button" href="#petish-controls">Explore components</a>
          <button type="button" class="petish-button-secondary" data-preview-dialog-open>Preview a dialog</button>
        </div>
      </div>
      <img class="petish-hero-art" src="<?php echo esc_url( $brand_uri . 'repaired/combined-light-v2.png' ); ?>" width="1536" height="1024" alt="Petish logo with rabbit, hamster, guinea pig and chinchilla; Small pets, big adventures." data-petish-logo data-light="<?php echo esc_url( $brand_uri . 'repaired/combined-light-v2.png' ); ?>" data-dark="<?php echo esc_url( $brand_uri . 'repaired/combined-dark-v2.png' ); ?>">
    </section>

    <section aria-labelledby="petish-palette-title">
      <h2 id="petish-palette-title">A little colour. A lot of character.</h2>
      <p class="petish-muted">The shared palette adapts to the selected theme. The supplied logo artwork keeps its original colours.</p>
      <div class="petish-swatches" aria-label="Decorative brand colour swatches">
        <?php foreach ( array( 'primary' => 'Primary UI', 'orange' => 'Orange', 'pink' => 'Pink', 'purple' => 'Purple', 'lavender' => 'Lavender' ) as $token => $label ) : ?>
          <div class="petish-swatch"><span class="petish-swatch-chip" style="background:var(--color-<?php echo esc_attr( $token ); ?>)" aria-hidden="true"></span><span><?php echo esc_html( $label ); ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="petish-preview-grid" id="petish-controls" aria-label="Reusable UI components">
      <div class="petish-card">
        <span class="petish-eyebrow">Typography</span>
        <h2>Made to feel welcoming.</h2>
        <p class="petish-type-specimen">Poppins brings the personality.</p>
        <p>Nunito Sans keeps longer descriptions, everyday guidance and form text comfortable to read.</p>
        <p class="petish-caption">DM Sans gives captions, navigation and metadata a clear, compact voice.</p>
        <a href="<?php echo esc_url( home_url( '/sample-page/' ) ); ?>">View the existing sample page</a>
        <div class="petish-action-row">
          <button type="button" class="petish-button" disabled>Disabled control</button>
        </div>
      </div>
      <div class="petish-card">
        <h2>Clear, comfortable forms.</h2>
        <p class="petish-caption">Demonstration only. Please use sample values.</p>
        <form data-preview-form>
          <noscript><p>This interactive demonstration requires JavaScript. No information will be submitted.</p></noscript>
          <fieldset data-preview-fields disabled>
          <div class="petish-field">
            <label for="petish-preview-name">Example pet name</label>
            <input id="petish-preview-name" placeholder="Try Milo" maxlength="80" required autocomplete="off" aria-describedby="petish-name-hint">
            <small id="petish-name-hint">This value stays in this page and is never saved.</small>
          </div>
          <div class="petish-field">
            <label for="petish-preview-species">Example species</label>
            <select id="petish-preview-species"><option>Rabbit</option><option>Hamster</option><option>Guinea pig</option><option>Chinchilla</option><option>Other small pet</option></select>
          </div>
          <button type="submit">Test form feedback</button>
          </fieldset>
          <p id="petish-preview-status" class="petish-success" role="status" tabindex="-1" hidden></p>
        </form>
      </div>
      <div class="petish-card">
        <h2>Helpful validation.</h2>
        <div class="petish-field">
          <label for="petish-preview-error">Example error state</label>
          <input id="petish-preview-error" value="" placeholder="Example required field" aria-invalid="true" aria-describedby="petish-error-message">
          <span id="petish-error-message" class="petish-error">Example: enter a name to continue. An error uses words as well as colour.</span>
        </div>
      </div>
      <div class="petish-card">
        <h2>Thoughtful by default.</h2>
        <p class="petish-notice">Visible keyboard focus, comfortable touch targets and reduced-motion support are part of the shared design.</p>
        <p class="petish-caption">Appearance follows your device until you choose Light or Dark. Your choice is remembered when browser storage is available.</p>
      </div>
    </section>
  </article>
  <dialog id="petish-preview-dialog" class="petish-dialog" aria-labelledby="petish-dialog-title" aria-describedby="petish-dialog-description">
    <h2 id="petish-dialog-title">A little moment of focus.</h2>
    <p id="petish-dialog-description">This is a preview of a Petish dialog. Press Escape or use the button below to return to the page.</p>
    <button type="button" class="petish-button" data-preview-dialog-close autofocus>Close preview</button>
  </dialog>
</div>
<?php get_footer(); ?>
