<?php
/**
 * @author ProudCity
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

define( 'ATTACHMENT_MAX', 25 );

class ProudElasticSearch {

  public $search_cohort; // array of sites that elastic search is using
  public $index_name; // the index name of this site
  public $agent_type; // mode we're operating in
  public $attachments;
  public $forms = []; // The forms we should alter with aggregations, ect
  public static $aggregations; // Result counts
  
  /**
   * Constructor
   */
  public function __construct() {
    add_action( 'plugins_loaded', array( $this, 'check_modules' ) );

    // Modifying indexes to allow our idea of multisite
    // -----------------------------------

    // Set Search cohort
    $this->search_cohort = get_option( 'proud-elastic-search-cohort' );
    // Set index name
    $this->index_name = get_option( 'proud-elastic-index-name' );
    // Set agent type
    $this->agent_type = get_option( 'proud-elastic-agent-type', 'agent' );
    // Alter index names to match our cohort
    add_filter( 'ep_index_name', array( $this, 'ep_index_name' ), 10, 2 );
    // Are we processing attachments?
    $this->attachments_api = defined( 'EP_HELPER_HOST' ) ? EP_HELPER_HOST : false;

    // Deal with elastic mapping
    // -----------------------------------

    // DOCUMENTS: Alter mapping sent to ES (USE STOCK EP DOCUMENTS FUNCs)
    if ( $this->attachments_api ) {
      add_action( 'ep_cli_put_mapping', 'ep_documents_create_pipeline' );
      add_action( 'ep_dashboard_put_mapping', 'ep_documents_create_pipeline' );
      add_filter( 'ep_config_mapping', 'ep_documents_attachments_mapping' );
    }

    // Allow meta mappings
    add_filter( 'ep_prepare_meta_allowed_protected_keys', array( $this, 'ep_prepare_meta_allowed_protected_keys' ), 10 );
    
    // Search all in cohort
    if( $this->agent_type === 'full' ) {
      add_filter( 'ep_global_alias', array( $this, 'ep_global_alias_full' ) );
    }
    // Search only this site
    else {
      add_filter( 'ep_global_alias', array( $this, 'ep_global_alias_single' ) );
    }

    // Posting to elastic
    // -----------------------------------

    add_filter( 'ep_post_sync_args_post_prepare_meta', array($this, 'ep_post_sync_args_post_prepare_meta'), 999, 1 );

    // If we're only in agent mode, don't load proud
    if( $this->agent_type === 'agent' ) {
      return;
    }
    // Add an alter to search page
    else if(  $this->agent_type === 'subsite' ) {
      add_filter( 'proud_search_page_message', array( $this, 'search_page_message' ) );
    }

    // Searching
    // ------------------------------------

    // Modify search page template 
    add_filter( 'proud_search_page_template', array( $this, 'search_page_template' ) );

    // DOCUMENTS: Add attachment to search fields
    if ( $this->attachments_api ) {
      add_filter( 'ep_search_fields', array( $this, 'ep_search_fields' ) );
    }

    // Integrate with proud teaser plugin + elasticpress
    // -----------------------------------

    // Modify settings for widgets
    add_filter( 'proud_teaser_settings', array( $this, 'proud_teaser_settings' ), 10, 2 );
    add_filter( 'proud_teaser_extra_options', array( $this, 'proud_teaser_extra_options' ), 10, 2 );
    // Alter proud search queries
    add_filter( 'wpss_search_query_args', array( $this, 'query_alter' ), 10, 2 );
    add_filter( 'proud_teaser_query_args', array( $this, 'query_alter' ), 10, 2 );
    // Enable elastic search if 
    add_filter( 'ep_elasticpress_enabled', array( $this, 'ep_enabled' ), 10, 2 );
    // Modify proud teaser filters
    add_action( 'proud-teaser-filters', array( $this, 'proud_teaser_filters' ), 10, 2 );
    // Add weighting, ect
    add_filter( 'ep_formatted_args', array( $this, 'ep_weight_search' ), 10, 2 );
    // Alter request path
    add_filter( 'ep_search_request_path', array( $this, 'ep_search_request_path' ), 10, 4 );
    // Get aggregations
    add_action( 'ep_retrieve_aggregations', array( $this, 'ep_retrieve_aggregations' ), 10, 1 );
    // Modify form output
    add_filter( 'proud-form-filled-fields', array( $this, 'form_filled_fields' ), 10, 3 );

    // UI + proud-search integration
    // -----------------------------------

    // Respond to search retrieval
    add_filter( 'ep_retrieve_the_post', array( $this, 'ep_retrieve_the_post' ), 10, 2 );
    // Make sure our fields are added
    add_filter( 'ep_search_post_return_args', array( $this, 'ep_search_post_return_args' ) );
    // Helpers to display post as we want
    add_filter( 'the_title', array( $this, 'the_title' ), 10, 2 );
    add_filter( 'post_link', array( $this, 'post_link' ), 10, 2 );
    add_filter( 'post_type_link', array( $this, 'post_link' ), 10, 2 );
    add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
    // Add matching to search results
    add_action( 'teaser_search_matching', array( $this, 'teaser_search_matching' ) );
    // Alter search results
    add_filter( 'proud_search_post_url', array( $this, 'search_post_url' ), 10, 2 );
    add_filter( 'proud_search_post_args', array( $this, 'search_post_args' ), 10, 2 );
    // Alter ajax searchr results
    add_filter( 'proud_search_ajax_post', array( $this, 'search_ajax_post' ), 10, 2 );
  }


  /**
   * Sets alert message on admin
   */
  public function modules_error() {
    $class = 'notice notice-error';
    $message = __( 'Proud ElasticSearch functions best when all ElasticPress modules are disabled, please head over and make sure: ', 'proud-elasticsearch' );
    printf( 
      '<div class="%1$s"><p>%2$s%3$s</p></div>', 
      $class, 
      $message, 
      '<a href="/wp-admin/admin.php?page=elasticpress">disable modules</a>.' 
    ); 
  }

  /** 
   * Makes sure ElasticPress modules aren't enabled
   */
  public function check_modules() { 
    $active_ep = get_option( 'ep_feature_settings', array() );
    if( !empty($active_ep) ) {
      foreach ( $active_ep as $feature ) {
        if( $feature['active'] ) {
          add_action( 'admin_notices', array( $this, 'modules_error' ) );
          break;
        }
      }
    }
  }

  /**
   * Gets which mode elastic should be operating in
   */
  public static function get_agent_type() {
    return $this->agent_type;
  }

  /**
   * Alters index name to our set value
   */
  public function ep_index_name( $index_name, $blog_id ) {
    return $this->index_name;
  }

  /**
   * Alters es mapping
   */
  public function ep_prepare_meta_allowed_protected_keys( $allowed_protected_keys ) {
    // Adding event end timestamp
    $allowed_protected_keys[] = '_end_ts';
    // Adding agency "exlude lists" meta
    $allowed_protected_keys[] = 'list_exclude';
    return $allowed_protected_keys;
  }

  /** 
   * Alters the network alias to use specific values
   */
  public function ep_global_alias_single( $alias ) {
    return $this->index_name;
  }

  /** 
   * Alters the network alias to use specific values
   */
  public function ep_global_alias_full( $alias ) {
    return implode( ',', array_keys( $this->search_cohort ) );
  }

  /**
   * See elasticpress/features/documents/documents/
   * func ep_documents_index_post_request_path
   */
  public function ep_document_request_path($id) {
    static $index = null; 
    if (!$index) {
      $index = ep_get_index_name();
    }
    return trailingslashit( $index ) . 'post/' . $id . '?pipeline=' . apply_filters( 'ep_documents_pipeline_id', $index . '-attachment' );
  }

  /**
   * Posts to helper api
   */
  public function post_to_helper_api( $post_args ) {
    // For some reason the live version is trying to send this request
    // multiple times in a row...
    // @TODO figure out why
    static $currently_calling = null;
    if ( $currently_calling === $post_args['ID'] ) {
      return;
    } 
    // Set our static
    $currently_calling = $post_args['ID'];

    //Deal with posting 
    $args = [
      'method' => 'POST',
      'headers' => array(),
      'body' => new stdClass,
    ];

    $args['body']->path = $this->ep_document_request_path( $post_args['ID'] );
    $args['body']->post = $post_args;

    $request = wp_remote_request( $this->attachments_api, $args );
  }

  /**
   * Should we process attachments
   * @returns the document if so
   */
  public function process_attachments( $post_args ) {
    // Trying to stop autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    // Trying to stop autosave
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return;
    }
    if( $post_args['post_type'] === 'document' ) {
      if( !empty( $post_args['meta']['document'] ) ) {
        foreach ( $post_args['meta']['document'] as $key => $document ) {
          if( !empty( $document['value'] ) && !empty( $post_args['meta']['document_meta'][$key]['value'] ) ) {
            try {
              $meta = json_decode( $post_args['meta']['document_meta'][$key]['value'] );
              $has_meta = !empty( $meta ) 
                       && !empty( $meta->size )
                       && !empty( $meta->mime )
                       && in_array( $meta->mime, ep_documents_get_allowed_ingest_mime_types() );

              if ( !$has_meta ) {
                return false;
              }

              // Check size for transmission limit
              $is_small_mb = strripos( $meta->size, 'mb' )
                          && (int) preg_replace( '/[^0-9]/', '', $meta->size ) < ATTACHMENT_MAX;
              // Send request to processing
              if ( $is_small_mb || strripos($meta->size, 'kb' ) ) {
                $post_args['attachments'][] = $document['value'];
              }
            } catch (Exception $e) {
              print_r($e);
            }
          }
        }
        if( !empty( $post_args['attachments'] ) ) {
          $this->post_to_helper_api($post_args);
        }
      }
    }
    // return false;
  }

  /** 
   * Alters outgoing post sync
   */
  public function ep_post_sync_args_post_prepare_meta( $post_args ) {
    // Events are returning un-desireable results due to html
    // we get weird full html markup in results
    if( $post_args['post_type'] === 'event' ) {
      $post = get_post($post_args['ID']);
      if( $post && isset( $post->post_content ) ) {
        $post_args['post_content'] = $post->post_content;
      }
    }
    
    // IF we're processing attachments
    if ( $this->attachments_api ) {
      // Add attachments to everything 
      $post_args['attachments'] = [];
      $document = $this->process_attachments($post_args);
      //Resets the value after posting to helper
      $post_args['attachments'] = [];
    }
    return $post_args;
  }


  /**
   * Add attachment field for search
   *
   * @param $search_fields
   * @since  2.3
   * @return array
   */
  public function ep_search_fields( $search_fields ) {
    if ( ! is_array( $search_fields ) ) {
      return $search_fields;
    }
    
    $search_fields[] = 'attachments.attachment.content';
    return $search_fields;
  }

  /**
   * Attaches global elastic integation query items
   */
  public function query_args( &$query_args, $config = [] ) {
    $query_args['proud_ep_integrate'] = true;
    // Set to all be be processed by ep_global_alias
    $query_args['sites'] = 'all';
    if( 'full' !== $this->agent_type ) {
      return;
    }
    // Filter for certain site index from form
    $filter_index = !empty( $config['form_instance']['filter_index'] ) 
                 && 'all' !== $config['form_instance']['filter_index'];
    if( $filter_index ) {
      $query_args['filter_index'] = $config['form_instance']['filter_index'];
    }
    // Filter for site index by teaser settings
    if( !empty( $config['options']['elastic_index'] ) ) {
      $query_args['filter_index'] = $config['options']['elastic_index'] === 'all'
                                  ? $this->ep_global_alias_full(true)
                                  : $config['options']['elastic_index']; 
    }
  }

  /**
   * Alters query to add our flag
   * Needed because default ep_integrate flag will scrub out our 's' query
   * sites values:
   * 'current' = this site
   * 'all' = network
   * (int) id = specific
   * (array) [id, id] = multiple
   */
  public function query_alter( $query_args, $config = [] ) {
    // Proud search query ?
    $run_elastic = !empty( $query_args['proud_search_ajax'] ) // ajax search
                || !empty( $query_args['proud_teaser_search'] ) // site search
                || !empty( $query_args['proud_teaser_query'] ) // teaser listings 
                || !empty( $config['options']['elastic_index'] ); // teaser listing with index

    if( $run_elastic ) {
      // Ajax search
      if( !empty( $query_args['proud_search_ajax'] ) ) {
        $this->query_args( $query_args );
      }
      // Add aggregations ?
      else if( !empty( $config['type'] ) ) {
        $this->query_args( $query_args, $config );
        // Is search
        if( !empty( $query_args['proud_teaser_search'] ) ) {
          if( !empty( $config['form_id_base'] ) ) {
            $this->forms[] = $config['form_id_base'];
            $query_args['aggs'] = [
              'name'       => 'search_aggregation', // (can be whatever you'd like)
              'use-filter' => true, // (*bool*) used if you'd like to apply the other filters (i.e. post type, tax_query)
              'aggs' => [
                'post_type' => [
                  'terms' => [
                    'field' => "post_type.raw",
                  ],
                ],
              ],
            ];
          }
        }
        // Teaser listing
        else {
          // Alter category listings?
          $alter_cats = !empty( $config['taxonomy'] )
                     && !empty( $config['form_id_base'] );
          if( $alter_cats ) {
            // Add to our form alters
            $this->forms[] = $config['form_id_base'];
            // Should we modify taxonomy query?
            if( !empty( $config['form_instance']['filter_categories'] ) ) {
              $query_args['tax_query'] = [
                [
                  'taxonomy' => $config['taxonomy'],
                  'field'    => 'name',
                  'terms'    => $config['form_instance']['filter_categories'],
                  'operator' => 'IN',
                ]
              ];
            }
            // Add query aggregation
            $query_args['aggs'] = [
              'name'       => 'terms_aggregation', // (can be whatever you'd like)
              'use-filter' => true, // (*bool*) used if you'd like to apply the other filters (i.e. post type, tax_query)
              'aggs' => [
                'categories' => [
                  'terms' => [
                    'size' => 100,
                    'field' => 'terms.' . $config['taxonomy'] . '.name.raw',
                  ],
                ],
              ],
            ];
          }
        }
      }
    }
    return $query_args;
  }

  /**
   * Returns enabled: true when our flag is set to activate ElasticPress
   */
  public function ep_enabled( $enabled, $query ) {
    if ( isset( $query->query_vars['proud_ep_integrate'] ) && true === $query->query_vars['proud_ep_integrate'] ) {
      $enabled = true;
    }
    return $enabled;
  }

  /**
   * Search weight
   * 
   * @param  array $formatted_args
   * @param  array $args
   * @since  2.1
   * @return array
   */
  public function ep_weight_search( $formatted_args, $args ) {

    if ( ! empty( $args['s'] ) ) {

      // Boost title ?
      $boost_title = !empty( $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0] )
                  && 'post_title' === $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0];

      if( $boost_title ) {
        $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0] = 'post_title^2';
      }

      // We're searching attachments + this is a content listing, 
      if( $this->attachments_api && empty( $args['proud_teaser_search'] ) && empty( $args['proud_search_ajax'] ) ) {

        // Get rid of other sorting on content listings if there is a search
        if( empty($formatted_args['sort'][0]['_score']) ) {
          $formatted_args['sort'][] = $formatted_args['sort'][0];
          $formatted_args['sort'][0] = [
            '_score' => [ 'order' => 'desc' ],
          ];
        }

        // Drop fuzzy searching
        $drop_fuzzy = !empty( $formatted_args['query']['bool']['should'][2]['multi_match']['fuzziness'] )
                   && $formatted_args['query']['bool']['should'][2]['multi_match']['fuzziness'] > 0;
        if( $drop_fuzzy ) {
          $formatted_args['query']['bool']['should'][2]['multi_match']['fuzziness'] = 0;
        }
      }

      $weight_search = [
        'function_score' => [
          'query' => $formatted_args['query'],
          'functions' => []
        ]
      ];

      // Add some weighting for menu_order
      $weight_search['function_score']['functions'][] = [
        'linear' => [
          'menu_order' => [
            'origin' => 0,
            'scale' => 100,
          ]
        ]
      ];

      // Boost content types (normal search)
      if( !empty( $args['proud_teaser_search'] ) || !empty( $args['proud_search_ajax'] ) ) {
        // Boost values for post type
        $post_type_boost = [
          'agency' => 2,
          'question' => 1.9,
          'payment' => 1.9,
          'issue' => 1.9,
          'page' => 1.3,
          'event' => 1.2,
          'proud_location' => 1.1
        ];

        foreach ( $post_type_boost as $name => $boost ) {
          $weight_search['function_score']['functions'][] = [
            'filter' => [
              'term' => [
                'post_type.raw' => $name
              ]
            ],
            'weight' => $boost
          ];
        }

        // Add weighting for events
        $now = new DateTime();
        // Add some weighting for menu_order
        $weight_search['function_score']['functions'][] = [
          'exp' => [
            'meta._end_ts.long' => [
              'origin' => $now->getTimestamp(),
              'scale' => 5 * 60 * 60 * 24,
              'offset' => 1 * 60 * 60 * 24,
              'decay' => 0.5
            ]
          ]
        ];
      }

      $formatted_args['query'] = $weight_search;
    }

    // We processing attachments?
    if ($this->attachments_api) {

      // Add highlighting for attachments
      $formatted_args['highlight'] = [
        'fields' => [
          'attachments.attachment.content' => new stdClass,
          'post_content' => new stdClass,
        ]
      ];

      // But also don't return the source since we don't want to be transmitting
      // 3mb of document
      $formatted_args['_source'] = [
        'excludes'=> [ 'attachments*data', 'attachments*content' ]
      ];
    }

    // A make sure sort doesn't break on "field doesn't exist"
    if ( !empty( $formatted_args['sort'] ) ) {
      foreach ( $formatted_args['sort'] as $outer_key => &$sort_outer ) {
        foreach ( $sort_outer as $inner_key => &$sort ) {
          // See if we're using a _meta.{field}.{type}
          $sections = explode( '.', $inner_key );
          if( !empty( $sections[2] ) ) {
            $sort['unmapped_type'] = $sections[2];
          }
        }
      }
    }

    // Boost values for local results
    $formatted_args['indices_boost'] = [
      $this->index_name => 1.1
    ];

    return $formatted_args;
  }

  /**
   * Search Request path
   * 
   * @param  strong $formatted_args
   * @param  array  $args
   * @param  string $scope
   * @param  array  $query_args
   * @since  2.1
   * @return string
   */
  public function ep_search_request_path( $path, $args, $scope, $query_args ) {
    if( !empty( $query_args['filter_index'] ) ) {
      $path = $query_args['filter_index'] . '/post/_search';
    }
    return $path;
  }

  // do_action( 'ep_retrieve_raw_response', $request, $args, $scope, $query_args );

  /**
   * Save the aggregation results from the last executed query.
   *
   * @param $aggregations
   */
  public static function ep_retrieve_aggregations( $aggregations ) {
    self::$aggregations = $aggregations;
  }

  /** 
   * Modify teaser settings to allow certain index to be searched
   */
  public function proud_teaser_settings( $settings, $post_type = false ) {
    if( !$post_type ) {
      return $settings;
    }
    if( $post_type === 'document' || $post_type === 'post' || $post_type === 'event' ) {
      $options = array_map( create_function( '$o', 'return $o["name"];' ), $this->search_cohort );
      // @TODO make the integration automatic for single site installs
      // Mod index name
      $options[$this->index_name] = __( 'This site only', 'wp-proud-search-elastic' );
      $options['all'] = __( 'All Sites', 'wp-proud-search-elastic' );
      $settings['elastic_index'] = [
        '#title' => __( 'Content source', 'proud-teaser' ),
        '#type' => 'radios',
        '#options' => $options,
        '#default_value' => $this->index_name,
        '#description' => 'Where should this content be served from?'
      ]; 
    }
    return $settings;
  }

  /** 
   * Modify teaser settings to allow certain index to be searched
   */
  public function proud_teaser_extra_options( $options, $instance ) {
    if( !empty( $instance['elastic_index'] ) ) {
      $options['elastic_index'] = $instance['elastic_index'];
    }
    return $options;
  }

  /**
   * Alters filter markup from proud teaser
   * 
   * @param array $filters
   * @param array $config[ 'type' => post_type, 'options' => extra_options ] 
   */
  public function proud_teaser_filters( $filters, $config ) {
    if( 'full' === $this->agent_type ) {
      // Add index filter?
      $site_filter = 'search' === $config['type'];
      if( $site_filter ) {
        $options = [
          'all' => __( 'All Sites', 'wp-proud-search-elastic' )
        ];
        // Add in our cohort
        $options = $options + array_map( create_function( '$o', 'return $o["name"];' ), $this->search_cohort );
        $index = [
          'filter_index' => [
            '#title' => __( 'Search Site', 'proud-teaser' ),
            '#type' => 'radios',
            '#options' => $options,
            '#default_value' => 'all',
            '#description' => ''
          ]
        ];
        $filters = $filters + $index;
      }
    }

    return $filters;
  }

  /**
   * Alter filters output, add aggregation
   * 
   * @param  array $fields
   * @param  array $instance
   * @param  string $form_id_base
   * @since  2.1
   * @return array
   */
  public function form_filled_fields( $fields, $instance, $form_id_base ) {
    // Alter the form
    if( in_array( $form_id_base, $this->forms ) ) {
      // Taxonomy filters?
      if( !empty( $fields['filter_categories'] ) ) { 
        // We have aggregations
        if( !empty( self::$aggregations['terms_aggregation']['categories']['buckets'] ) ) {
          $options = [];
          foreach ( self::$aggregations['terms_aggregation']['categories']['buckets'] as $key => $term ) {
            $options[$term['key']] = $term['key'] . ' (' . $term['doc_count'] . ')';
          }
          $fields['filter_categories']['#options'] = $options;
        }
        // Alter tax to use Name
        else {
          foreach ( $fields['filter_categories']['#options'] as $key => $term ) {
            $fields['filter_categories']['#options'][$term] = $term;
            unset( $fields['filter_categories']['#options'][$key] );
          }
        }
      }
      // Post types
      if( !empty( $fields['filter_post_type'] ) ) { 
        // We have aggregations
        if( !empty( self::$aggregations['search_aggregation']['post_type']['buckets'] ) ) {
          // Add all tag no matter what
          $options = ['all' => $fields['filter_post_type']['#options']['all']];
          foreach ( self::$aggregations['search_aggregation']['post_type']['buckets'] as $key => $term ) {
            $options[$term['key']] = $fields['filter_post_type']['#options'][$term['key']] 
                                   . ' (' . $term['doc_count'] . ')';
          }
          $fields['filter_post_type']['#options'] = $options;
        }
      }
    } 
    return $fields;
  }

  /**
   * Search page filters
   */
  public function search_page_template( $path ) {
    return plugin_dir_path(__FILE__) . '../templates/search-page.php';
  }

  /**
   * Alters posts returned from elastic server
   */
  public function ep_retrieve_the_post( $post, $hit ) {
    // Deal with highlights
    if( !empty( $hit['highlight'] ) ) {
      $post['search_highlight'] = [];
      foreach ( $hit['highlight'] as $key => $value ) {
        $text = implode(' <span class="search-seperator">...</span> ', array_slice( $value, 0, 10 ) ); 
        if( $key === 'attachments.attachment.content' ) {
          $post['search_highlight']['attachments'] = $text;
        }
        else {
          $post['search_highlight'][$key] = $text;
        }
      }
    }
    $post['site_id'] = $hit['_index'];
    return $post;
  }

  /**
   * Makes sure our values set above are on the post
   */
  public function ep_search_post_return_args($args) {
    $args[] = 'search_highlight';
    return $args;
  }

  /**
   * Alters posts returned from elastic server
   */
  public function the_title( $title, $id ) {
    global $post;
    if( !$this->is_local( $post ) ) {
      $title = '<span class="title-span">' . $title . '</span>' . $this->append_badge( $post->site_id );
    }
    return $title;
  }

  /**
   * Alters posts returned from elastic server
   */
  public function post_class( $classes, $class, $ID ) {
    global $post;
    if( !$this->is_local( $post ) ) {
      $classes[] = 'external-post';
    }
    return $classes;
  }

  /**
   * Alters posts returned from elastic server
   */
  public function post_link( $permalink, $post ) {
    if( !$this->is_local( $post ) ) {
      $permalink = $post->permalink;
    }
    return $permalink;
  }

  /**
   * Alters posts returned from elastic server
   */
  public function search_page_message( $message ) {
    $alert = __( 'You are currently searching the ' . $this->cohort_name($this->index_name) . ' site, please visit the main site to search all content.' );
    return $message . '<div class="alert alert-success">' . $alert . '</div>';
  }

  /**
   * Helper tests if content is from this site
   */
  public function is_local( $post ) {
    return empty( $post->site_id ) || $post->site_id === $this->index_name;
  }

  /**
   * Returns cohort name
   */
  public function cohort_name( $id ) {
    return $this->search_cohort[$id]['name'];
  }

  /**
   * Returns cohort url
   */
  public function cohort_url( $id ) {
    return $this->search_cohort[$id]['url'];
  }

  /**
   * Returns cohort color
   */
  public function cohort_color( $id ) {
    return $this->search_cohort[$id]['color'];
  }

  /**
   * Alters search post url to match elastic source
   */
  public function search_post_url( $url, $post ) {
    if( !$this->is_local( $post ) ) {
      return $post->permalink;
    }
    return $url;
  }

  public function append_badge( $id ) {
    if( empty( $id ) ) {
      return '';
    }
    return '<span class="label" style="background-color:' 
         . $this->cohort_color( $id ) . '">'
         . $this->cohort_name( $id ) . '</span>';
  }

  public function teaser_search_matching($post) {
    if ( empty( $post->search_highlight ) ) {
      return;
    }
    include( plugin_dir_path(__FILE__) . '../templates/teaser-search-matching.php' );
  }

  /**
   * Alters search post args for results
   *
   * $arg[0] = (string) url
   * $arg[1] = (string) data attributes
   * $arg[2] = (string) title
   * $arg[3] = (string) appended string
   */
  public function search_post_args( $args, $post ) {
    // @TODO better way to filter
    if( $args[2] !== 'See more' ) {
      if( !$this->is_local( $post ) ) {
        $args[1] = '';
        $args[3] = $this->append_badge( $post->site_id );
      }
    }
    return $args;
  }

  /**
   * Alters search post args for results
   *
   * $post_arr = (array) $post_arr
   * $post = (object) wp post
   */
  public function search_ajax_post( $post_arr, $post ) {
    if( !$this->is_local( $post ) ) {
      $post_arr['action_attr'] = '';
      $post_arr['action_hash'] = '';
      $post_arr['action_url'] = '';
      $post_arr['type'] = 'external';
      $post_arr['suffix'] = $this->append_badge( $post->site_id );
    }
    return $post_arr;
  }


  /**
   * Get a singleton instance of the class
   *
   * @return ProudElasticSearch
   */
  public static function factory() {
    static $instance = false;

    if ( ! $instance ) {
      $instance = new self();
    }

    return $instance;
  }
}

ProudElasticSearch::factory();