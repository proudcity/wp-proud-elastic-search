<?php the_widget('SearchBox'); ?>
<?php echo apply_filters( 'proud_search_page_message', '' ); ?>
<div class="row">
  <div class="col-md-3">
    <?php $search_results->print_filters(); ?>
  </div>
  <div class="col-md-9">
    <h3 class="margin-top-none">Results</h3>
    <?php $search_results->print_list(); ?>
  </div>
</div>