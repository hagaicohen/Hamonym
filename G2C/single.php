<?php
/**
 * The Template for displaying all single posts.
 *
 * @package Hamonym
 */

get_header();

global $post;
$is_en_post = nexc_is_en_page();

$disable_sidebar = get_post_meta($post->ID, 'disable_sidebar', true);

if ( $disable_sidebar == 'yes' ):
	$bootstrap_sidebar_dep = 'col-sm-12';
else:
	$bootstrap_sidebar_dep = 'col-lg-8';
endif;
?>

	<div class="container">
		<div class="row">
			<?php
			if ( ('post' == get_post_type()  || "business" == get_post_type()) && ! $disable_sidebar ) {
				get_sidebar('social');
			}
			?>
			<div id="primary" class="content-area <?php echo apply_filters('primary_bootstrap_class', $bootstrap_sidebar_dep); ?>">

				<?php if( is_active_sidebar('content-top') ): ?>

					<div id="content-top-wa" class="widget-area">
						<?php dynamic_sidebar('content-top') ?>
					</div><!-- #content-top-wa -->

				<?php endif;?>

				<main id="main" class="site-main" role="main">

					<?php while ( have_posts() ) : the_post(); ?>

						<?php get_template_part( 'content', 'single' ); ?>

						<?php faster_post_nav(); ?>

						<?php
						// If comments are open or we have at least one comment, load up the comment template
						if ( comments_open() || '0' != get_comments_number() ) :
							comments_template();
						endif;
						?>

					<?php endwhile; // end of the loop. ?>

				</main><!-- #main -->

				<?php if( is_active_sidebar('content-bottom') ): ?>

					<div id="content-bottom-wa" class="widget-area">
						<?php dynamic_sidebar('content-bottom') ?>
					</div><!-- #content-bottom-wa -->

				<?php endif; ?>

			</div><!-- #primary -->

			<?php
			if ( 'post' != get_post_type() && "business" != get_post_type() && ! $disable_sidebar ) {
				get_sidebar();
			}
			?>

		</div><!-- .row -->
	</div><!-- .container -->

<?php get_footer(); ?>