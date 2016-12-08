<?php
class ProudSearchElastic extends ProudSettingsPage
{
   /**
     * Start up
     */
    public function __construct()
    {
      parent::__construct(
        'elasticsearch', // Key
        [ // Submenu settings
          'parent_slug' => 'proudsettings',
          'page_title' => 'Elastic Search',
          'menu_title' => 'Elastic Search',
          'capability' => 'edit_proud_options',
        ],
        '', // Option
        [ // Options
          'proud-elastic-agent-only' => '1',
          'proud-elastic-index-name' => '',
          'proud-elastic-search-cohort' => []
        ] 
      );
    }

    /** 
     * Sets fields
     */
    public function set_fields( ) {
      $this->fields = [
       'proud-elastic-agent-only' => [
          '#type' => 'checkbox',
          '#title' =>  __( 'Index name', 'This is only an agent site' ),
          '#return_value' => '1',
          '#label_above' => false,
          '#replace_title' => __pcHelp( 'This is only an agent site' ),
        ],
        'proud-elastic-index-name' => [
          '#type' => 'text',
          '#title' => __( 'Index name', 'wp-proud-search-elastic' ),
          '#description' => __pcHelp( 'This is the index name for this site.  It needs to match what exists in the search cohort below.' )
        ],
        'proud-elastic-search-cohort' => [
          '#title' => __( 'Search Cohort', 'wp-proud-search-elastic' ),
          '#type' => 'group',
          '#group_title_field' => 'name',
          '#keyed' => 'key',
          '#sub_items_template' => [
            'name' => [
              '#title' => 'Name',
              '#type' => 'text',
              '#default_value' => '',
              '#to_js_settings' => false
            ],
            'key' => [
              '#title' => 'Site name (index)',
              '#type' => 'text',
              '#default_value' => '',
              '#to_js_settings' => false,
              '#description' => 'One of these must match the index name value above',
            ],
            'url' => [
              '#title' => 'Url',
              '#type' => 'text',
              '#default_value' => '',
              '#to_js_settings' => false
            ],
            'color' => [
              '#title' => 'Color',
              '#type' => 'text',
              '#default_value' => '',
              '#to_js_settings' => false
            ],
          ],
        ],
      ];
    }

    /**
     * Print page content
     */
    public function settings_content() {
      $this->print_form( );
    }
}

if( is_admin() )
    new ProudSearchElastic();

