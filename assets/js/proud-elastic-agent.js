(function($) {
  $("#submit").click(function (e) {
    var result = window.confirm('Are you sure? Re-mapping and indexing will delete all current data on the ElasticPress server.');
    if (result == false) {
        e.preventDefault();
        console.log('stop');
    };
    var updateMessageBox = function (alert, text) {
      $('#message-box').attr({'class': ''}).addClass('alert alert-' + alert).html(text);
    }
    updateMessageBox('info', 'Running...');
    // Run ajax
    $.ajax({
      url: proudElasticAgent.url,
      data: proudElasticAgent.params,
      success: function(data) {
        // Nothing, nonce, ect
        if(!data) {
          updateMessageBox('danger', 'Sorry there was an issue running the request.  Please contact an administrator.');
          return;
        }
        // Error
        else if(!data.success && data.data) {
          updateMessageBox('danger', data.data.message);
          return
        }
        updateMessageBox('success', data.data.message);
      },
      error: function(error) {
        updateMessageBox('danger', 'Sorry there was an issue running the request.  Please contact an administrator.');
        return;
      }
    });
  });
})(jQuery);