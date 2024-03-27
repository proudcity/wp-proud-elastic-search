<?php the_widget('SearchBox'); ?>
<?php echo apply_filters( 'proud_search_page_message', '' ); ?>
<div class="row">
  <div class="col-md-3">
    <?php $search_results->print_filters(); ?>
  </div>
  <div class="col-md-9">
	<h2 class="h3 margin-top-none"><?php echo absint( $search_results->get_total_found_posts() ); ?> Results Found</h2>
    <?php $search_results->print_list(); ?>
  </div>
</div>
