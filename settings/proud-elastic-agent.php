<?php  

/* call our code on admin pages only, not on front end requests or during
 * AJAX calls.
 * Always wait for the last possible hook to start your code.
 */
add_action( 'admin_menu', array ( 'Proud_ElasicPress_Agent', 'admin_menu' ) );
// Add ajax endpoint
add_action( 'wp_ajax_proud-elastic-agent', array ( 'Proud_ElasicPress_Agent', 'ajax_response' ) );

/**
 * Register three admin pages and add a stylesheet and a javascript to two of
 * them only.
 *
 * @author toscho
 *
 */
class Proud_ElasicPress_Agent
{
  /**
   * Register the pages and the style and script loader callbacks.
   *
   * @wp-hook admin_menu
   * @return  void
   */
  public static function admin_menu()
  {
    // built with get_plugin_page_hookname( $menu_slug, '' )
    $slug = add_submenu_page(
      null,
      'Proud Elastic Agent',             // page title
      'Proud Elastic Agent',             // menu title
      // Change the capability to make the pages visible for other users.
      // See http://codex.wordpress.org/Roles_and_Capabilities
      'manage_options',                  // capability
      'proud-elastic-agent',             // menu slug
      array ( __CLASS__, 'render_page' ) // callback function
    );

    // make sure the script callback is used on our page only
    add_action(
      "admin_print_scripts-$slug",
      array ( __CLASS__, 'enqueue_script' )
    );
  }

  /**
   * Load JavaScript on our admin page only.
   *
   * @return void
   */
  public static function enqueue_script()
  {
    wp_register_script(
      'proud-elastic-agent',
      plugins_url( '/../assets/js/proud-elastic-agent.js', __FILE__ ),
      array(),
      FALSE,
      TRUE
    );
    wp_localize_script( 'proud-elastic-agent', 'proudElasticAgent', array(
      'url' => admin_url( 'admin-ajax.php' ),
      'params' => array(
        'action' => 'proud-elastic-agent', 
        '_wpnonce' => wp_create_nonce( 'proud-elastic-agent' )
      )
    ) );
    wp_enqueue_script( 'proud-elastic-agent' );
  }

  /**
   * Print page output.
   *
   * @return  void
   */
  public static function render_page()
  {
    print '<div class="wrap">';
    print "<h1>Proud Elastic Agent</h1>";
    print '<div id="message-box"></div>';
    submit_button( 'Re-initialize mapping!' );
    print '</div>';
  }

  /**
   * Handles the AJAX endpoint to reset everything.
   *
   * @return json response
   */
  public function ajax_response( ) {
    check_ajax_referer( 'proud-elastic-agent' );

    $response = null;
    $index_name = get_option( 'proud-elastic-index-name' );
    if( !$index_name ) {
      wp_send_json_error( array( 'message' => 'This site does not have the option "proud-elastic-index-name" set.' ) );
      wp_die();
    }
    // Try to delete index
    if( ep_index_exists( $index_name ) ) {
      try {
        $response = ep_delete_index( $index_name );
      } catch ( Exception $e ) {
        wp_send_json_error( array( 'message' => 'An error occurred trying to delete the index.' ) );
        wp_die();
      }
      if( !$respose ) {
        wp_send_json_error( array( 'message' => 'An error occurred trying to delete the index.' ) );
        wp_die();
      }
    }
    // Try to post mapping
    try {
      $response = ep_put_mapping();
    } catch ( Exception $e ) {
      wp_send_json_error( array( 'message' => 'An error occurred while trying to post the mapping.' ) );
      wp_die();
    }
    if( !$respose ) {
      wp_send_json_error( array( 'message' => 'An error occurred while trying to post the mapping.' ) );
      wp_die();
    }
    // Send response
    $elastic_link = '<a href="/wp-admin/admin.php?page=elasticpress">ElasticPress page</a>';
    wp_send_json_success( array( 'message' => "Successfully deleted index and re-posted the mapping.  Head to the $elastic_link page to re-index." ) );
    wp_die();
  }
}