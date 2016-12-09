<?php
/**
 * @author ProudCity
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

class ProudElasticSearch {

  public $search_cohort; // array of sites that elastic search is using
  public $index_name; // the index name of this site
  
  /**
   * Constructor
   */
  public function __construct() {
    // Set Search cohort
    $this->search_cohort = get_option( 'proud-elastic-search-cohort' );
    // Set index name
    $this->index_name = get_option( 'proud-elastic-index-name' );
    // Alter index names to match our cohort
    add_filter( 'ep_index_name', array( $this, 'ep_index_name' ), 10, 2 );
    // What type of mode are we in?
    $agent_type = self::get_agent_type();
    // Search all in cohort
    if( $agent_type === 'full' ) {
      add_filter( 'ep_global_alias', array( $this, 'ep_global_alias_full' ) );
    }
    // Search only this site
    else {
      add_filter( 'ep_global_alias', array( $this, 'ep_global_alias_single' ) );
    }
    // If we're only in agent mode, don't load proud
    if( $agent_type === 'agent' ) {
      return;
    }
    // Add an alter to search page
    else if(  $agent_type === 'subsite' ) {
      add_filter( 'proud_search_page_message', array( $this, 'search_page_message' ) );
    }
    // Alter proud search queries
    add_filter( 'wpss_search_query_args', array( $this, 'query_alter' ), 10, 3 );
    add_filter( 'proud_teaser_query_args', array( $this, 'query_alter' ), 10, 3 );
    // Add weighting, ect
    add_filter( 'ep_formatted_args', array( $this, 'ep_weight_search' ), 10, 2 );
    // Enable elastic search if 
    add_filter( 'ep_elasticpress_enabled', array( $this, 'ep_enabled' ), 10, 2 );
    // Respond to search retrieval
    add_filter( 'ep_retrieve_the_post', array( $this, 'ep_retrieve_the_post' ), 10, 2 );
    // Alter search results
    add_filter( 'proud_search_post_url', array( $this, 'post_url' ), 10, 2 );
    add_filter( 'proud_search_post_args', array( $this, 'post_args' ), 10, 2 );
    // Alter ajax searchr results
    add_filter( 'proud_search_ajax_post', array( $this, 'ajax_post' ), 10, 2 );
  }

  /**
   * Gets which mode elastic should be operating in
   */
  public static function get_agent_type() {
    return get_option( 'proud-elastic-agent-type', 'agent' );
  }

  /**
   * Alters index name to our set value
   */
  public function ep_index_name( $index_name, $blog_id ) {
    return $this->index_name;
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
   * Alters query to add our flag
   * Needed because default ep_integrate flag will scrub out our 's' query
   * sites values:
   * 'current' = this site
   * 'all' = network
   * (int) id = specific
   * (array) [id, id] = multiple
   */
  public function query_alter( $query_args, $s, $post_type = null ) {
    // Proud search query, if $query_args is null
    if( !empty( $query_args['proud_search'] ) || !empty( $query_args['proud_search_ajax'] ) ) {
      $query_args['proud_ep_integrate'] = true;
      // Set to all be be processed by ep_global_alias
      $query_args['sites'] = 'all';
    }
    return $query_args;
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
      $weight_search = [
        'function_score' => [
          'query' => $formatted_args['query'],
          'functions' => []
        ]
      ];

      // Boost values for post type
      $post_type_boost = [
        'agency' => 2,
        'question' => 1.9,
        'payment' => 1.9,
        'issue' => 1.9,
        'page' => 1.5,
        'event' => 1.2,
        'proud_location' => 1.2
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

      $formatted_args['query'] = $weight_search;

      // Boost values for local results
      $formatted_args['indices_boost'] = [
        $this->index_name => 1.5
      ];
    }


    return $formatted_args;
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
   * Alters posts returned from elastic server
   */
  public function ep_retrieve_the_post( $post, $hit ) {
    $post['site_id'] = $hit['_index'];
    return $post;
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
  public function post_url( $url, $post ) {
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

  /**
   * Alters search post args for results
   *
   * $arg[0] = (string) url
   * $arg[1] = (string) data attributes
   * $arg[2] = (string) title
   * $arg[3] = (string) appended string
   */
  public function post_args( $args, $post ) {
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
  public function ajax_post( $post_arr, $post ) {
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