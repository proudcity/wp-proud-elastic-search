<?php
/*
Plugin Name:        Proud Search Elastic
Plugin URI:         http://getproudcity.com
Description:        ProudCity distribution
Version:            1.0.0
Author:             ProudCity
Author URI:         http://getproudcity.com

License:            Affero GPL v3
*/


// Elastic Search?
if ( class_exists( 'EP_Config' ) ) {
  require_once( plugin_dir_path(__FILE__) . 'lib/elasticsearch.class.php' );
}