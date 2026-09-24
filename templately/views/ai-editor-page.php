<?php
/**
 * Templately AI Editor page shell (041 phase 1) — branded fullscreen frame
 * around the post's NATIVE editor. Variables (extracted by Helper::views):
 *
 * @var string $src         iframe URL (post.php?action=…&templately_host=1)
 * @var string $exit_url    the native editor without the host signal
 * @var string $exit_wp_url the wp-admin list to return to (or the dashboard)
 * @var string $post_title
 * @var string $platform    'elementor' | 'gutenberg'
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
	/* Fullscreen takeover — this screen only (body.templately-ai-editor-page). */
	body.templately-ai-editor-page #adminmenumain,
	body.templately-ai-editor-page #wpadminbar,
	body.templately-ai-editor-page #wpfooter,
	body.templately-ai-editor-page #screen-meta,
	body.templately-ai-editor-page #screen-meta-links {
		display: none;
	}
	body.templately-ai-editor-page,
	body.templately-ai-editor-page #wpcontent,
	body.templately-ai-editor-page #wpbody-content {
		margin: 0 !important;
		padding: 0 !important;
	}
	/* The shell is fixed inset-0 — anything taller behind it (admin notices
	   etc.) must never spawn an outer scrollbar. */
	html.templately-ai-editor-page-html,
	body.templately-ai-editor-page {
		overflow: hidden !important;
	}
	html.wp-toolbar {
		padding-top: 0 !important;
	}
	.templately-ai-editor-shell {
		position: fixed;
		inset: 0;
		display: flex;
		flex-direction: column;
		background: #fff;
		z-index: 99999;
	}
	.templately-ai-editor-shell__header {
		display: flex;
		align-items: center;
		gap: 12px;
		height: 48px;
		padding: 0 16px;
		border-bottom: 1px solid #EAECF0;
		background: #f8f8f8;
		flex-shrink: 0;
	}
	.templately-ai-editor-shell__header img {
		width: 24px;
		height: 24px;
	}
	.templately-ai-editor-shell__title {
		font-size: 13px;
		font-weight: 600;
		color: #101828;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.templately-ai-editor-shell__actions {
		margin-left: auto;
		display: flex;
		align-items: center;
		gap: 10px;
	}
	.templately-ai-editor-shell__exit {
		font-size: 12px;
		color: #475467;
		text-decoration: none;
	}
	.templately-ai-editor-shell__exit:hover {
		color: #101828;
	}
	/* Deliberately subtle — a gray chip, not a primary action. */
	.templately-ai-editor-shell__wp {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		font-weight: 500;
		color: #475467;
		text-decoration: none;
		background: #F2F4F7;
		border: 1px solid #EAECF0;
		border-radius: 6px;
		padding: 5px 10px;
		line-height: 1;
	}
	.templately-ai-editor-shell__wp:hover {
		background: #EAECF0;
		color: #101828;
	}
	.templately-ai-editor-shell__wp .dashicons {
		width: 16px;
		height: 16px;
		font-size: 16px;
		line-height: 1;
	}
	.templately-ai-editor-shell__body {
		position: relative;
		flex: 1;
		display: flex;
	}
	.templately-ai-editor-shell iframe {
		flex: 1;
		width: 100%;
		border: 0;
	}
	/* Branded boot state — covers the editor's own loaders/chrome shuffle
	   until the chat reports ready (templately-host-ready in the iframe). */
	.templately-ai-editor-shell__loading {
		position: absolute;
		inset: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 16px;
		background: #fff;
		z-index: 5;
		transition: opacity 0.3s ease;
	}
	.templately-ai-editor-shell__loading img {
		width: 72px;
		height: 72px;
	}
	.templately-ai-editor-shell__loading p {
		margin: 0;
		font-size: 13px;
		color: #667085;
	}
	.templately-ai-editor-shell__progress {
		width: 220px;
		height: 4px;
		border-radius: 2px;
		background: #EAECF0;
		overflow: hidden;
	}
	.templately-ai-editor-shell__progress span {
		display: block;
		height: 100%;
		width: 0;
		border-radius: 2px;
		background: #5E2EFF;
		transition: width 0.4s ease;
	}
	.templately-ai-editor-shell.is-ready .templately-ai-editor-shell__loading {
		opacity: 0;
		pointer-events: none;
	}
	.templately-ai-editor-shell__fallback {
		display: none;
		padding: 40px;
		font-size: 14px;
		color: #475467;
	}
	.templately-ai-editor-shell.is-blocked iframe {
		display: none;
	}
	.templately-ai-editor-shell.is-blocked .templately-ai-editor-shell__fallback {
		display: block;
	}
</style>

<div class="templately-ai-editor-shell" id="templately-ai-editor-shell">
	<div class="templately-ai-editor-shell__header">
		<img src="<?php echo esc_url( templately()->assets->icon( 'logos/logo.svg' ) ); ?>" alt="Templately" />
		<span class="templately-ai-editor-shell__title">
			<?php
			printf(
				/* translators: %s: post title */
				esc_html__( 'Editing: %s', 'templately' ),
				esc_html( $post_title )
			);
			?>
		</span>
		<div class="templately-ai-editor-shell__actions">
			<a class="templately-ai-editor-shell__exit" href="<?php echo esc_url( $exit_url ); ?>">
				<?php esc_html_e( 'Go to Editor', 'templately' ); ?>
			</a>
			<a class="templately-ai-editor-shell__wp" href="<?php echo esc_url( $exit_wp_url ); ?>">
				<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
				<?php esc_html_e( 'Exit to WordPress', 'templately' ); ?>
			</a>
		</div>
	</div>
	<div class="templately-ai-editor-shell__body">
		<iframe id="templately-ai-editor-frame" src="<?php echo esc_url( $src ); ?>" title="<?php esc_attr_e( 'Page editor', 'templately' ); ?>"></iframe>
		<div class="templately-ai-editor-shell__loading">
			<img src="<?php echo esc_url( templately()->assets->icon( 'logos/loading-logo.gif' ) ); ?>" alt="" />
			<div class="templately-ai-editor-shell__progress"><span id="templately-ai-editor-progress"></span></div>
			<p id="templately-ai-editor-progress-label"><?php esc_html_e( 'Loading the editor…', 'templately' ); ?></p>
		</div>
		<div class="templately-ai-editor-shell__fallback">
			<?php
			printf(
				/* translators: %s: edit-post URL */
				wp_kses( __( 'Your site blocks embedded editing, so the editor could not load here. <a href="%s">Open the editor directly</a>.', 'templately' ), [ 'a' => [ 'href' => [] ] ] ),
				esc_url( $exit_url )
			);
			?>
		</div>
	</div>
</div>

<script>
	( function () {
		document.documentElement.className += ' templately-ai-editor-page-html';
		var frame = document.getElementById( 'templately-ai-editor-frame' );
		var shell = document.getElementById( 'templately-ai-editor-shell' );
		var bar = document.getElementById( 'templately-ai-editor-progress' );
		var label = document.getElementById( 'templately-ai-editor-progress-label' );

		// Staged, REAL progress from same-origin boot signals, with a gentle
		// creep toward each stage's cap so slow steps still look alive. The
		// overlay drops when the chat inside reports ready
		// (body.templately-host-ready) — the user never sees the editor's own
		// loaders or its chrome being stripped.
		// Stage 1 must NOT count the iframe's initial about:blank document
		// (readyState 'complete' before navigation even starts) — it once
		// raced the bar to ~54% "Starting things up", then the REAL document
		// arrived in 'loading' state and everything snapped BACKWARD.
		var stages = [
			{ cap: 20, label: <?php echo wp_json_encode( __( 'Loading the editor…', 'templately' ) ); ?>, reached: function ( doc ) {
				return !! doc && doc.readyState !== 'loading' && doc.location && doc.location.href !== 'about:blank';
			} },
			{ cap: 55, label: <?php echo wp_json_encode( __( 'Starting things up…', 'templately' ) ); ?>, reached: function ( doc, win ) {
				return !! ( win && ( win.elementor || ( win.wp && win.wp.data ) ) );
			} },
			{ cap: 85, label: <?php echo wp_json_encode( __( 'Rendering your page…', 'templately' ) ); ?>, reached: function ( doc, win ) {
				if ( win && win.elementor ) {
					return !! ( win.elementor.getPreviewContainer && win.elementor.getPreviewContainer() );
				}
				return !! ( doc && doc.querySelector( '.interface-interface-skeleton__content' ) );
			} },
			{ cap: 100, label: <?php echo wp_json_encode( __( 'Almost there…', 'templately' ) ); ?>, reached: function ( doc ) {
				return !! ( doc && doc.body && doc.body.classList.contains( 'templately-host-ready' ) );
			} }
		];
		var progress = 0;
		var stageShown = 0;

		var readyPoll = setInterval( function () {
			var doc = null, win = null;
			try {
				doc = frame.contentDocument;
				win = frame.contentWindow;
			} catch ( e ) {
				return; // inaccessible — the blocked check below handles it
			}
			var stageIndex = 0;
			for ( var i = 0; i < stages.length; i++ ) {
				if ( stages[ i ].reached( doc, win ) ) {
					stageIndex = ( i + 1 < stages.length ) ? i + 1 : i;
				} else {
					break;
				}
			}
			// MONOTONIC: boot signals can flicker (document swaps reset
			// readyState) — the bar and the label only ever move FORWARD.
			stageShown = Math.max( stageShown, stageIndex );
			var stage = stages[ stageShown ];
			var done = stages[ stages.length - 1 ].reached( doc, win );
			progress = done ? 100 : Math.max( progress, Math.min( Math.max( progress + 1, stage.cap - 15 ), stage.cap - 1 ) );
			bar.style.width = progress + '%';
			label.textContent = stage.label;
			if ( done ) {
				clearInterval( readyPoll );
				setTimeout( function () {
					shell.className += ' is-ready';
				}, 350 );
			}
		}, 300 );

		// Frame-refused fallback: wp-admin is same-origin (SAMEORIGIN default
		// is fine), but security plugins forcing X-Frame-Options: DENY leave
		// the frame permanently inaccessible — reveal the direct link instead.
		setTimeout( function () {
			var blocked = false;
			try {
				var doc = frame.contentDocument;
				blocked = ! doc || ( doc.readyState === 'complete' && doc.location.href === 'about:blank' );
			} catch ( e ) {
				blocked = true;
			}
			if ( blocked ) {
				clearInterval( readyPoll );
				shell.className += ' is-blocked is-ready';
			}
		}, 8000 );
	} )();
</script>
