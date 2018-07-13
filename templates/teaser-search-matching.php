<div class="matching">
  <?php foreach ( $post->search_highlight as $field_key => $match ): ?>
    <?php if ( $field_key === 'attachments' ): ?>
      <p><small>
        <i aria-hidden="true" class="fa fa-info-circle"></i> 
        <strong class="match-label">Matched in attachment:</strong> 
        "... <?php echo strip_tags($match, $allowable_tags) ?> ..."
      </small></p>
    <?php else: ?>
      <p><small>"... <?php echo strip_tags($match, $allowable_tags) ?> ..."</small></p>
    <?php endif; ?>
  <?php endforeach; ?>
</div>