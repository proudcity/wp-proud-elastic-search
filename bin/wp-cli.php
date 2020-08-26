<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

WP_CLI::add_command( 'proud-elastic', 'ProudElasticSearch_CLI' );

/**
 * CLI Commands for Proud ElasticPress integration
 *
 * ## EXAMPLES
 *
 *     # Index all posts for a site.
 *     $ wp proud-elastic index-safe
 *     Success.
 *
 *     # Index all non-attachment posts for a site.
 *     $ wp proud-elastic index-safe-no-attachments
 *     Success.
 *
 *     # Index all attachment posts for a site.
 *     $ wp proud-elastic index-safe-only-attachments
 *     Success.
 * 
 *     # Delete index then index all posts for a site.
 *     $ wp proud-elastic mapping-and-index
 *     Success.
 */
class ProudElasticSearch_CLI extends WP_CLI_Command {
    /**
     * Document Safe index method for all posts for a site
     *
     * @param boolean $normal Index normal (non attachment) content
     * @param boolean $attachments Index attachment content
     * @return void
     */
    private function _index( $normal = true, $attachments = true ) {
        global $proudsearch;
        // Get class instance
        $instance = ProudElasticSearch::factory();

        $options = array(
            'launch'     => false,  // Reuse the current process.
        );

        // Just run normal
        if ( ! $instance->attachments_api ) {
            WP_CLI::line( __( "\nRunning normal index action, no attachments active...\n", 'wp-proud-search-elastic' ) );

            sleep ( 1 );

            WP_CLI::runcommand( 'elasticpress index' );

            return;
        }

        // Grab docs that index

        $whitelist = $proudsearch->search_whitelist( true );
        $runSafe = [];

        foreach ( $instance->attachments as $type => $attachment ) {
            if ( ! empty( $whitelist[$type] ) ) {
                unset( $whitelist[$type] );
                $runSafe[] = $type;
            }
        }

        // Run non- attachment
        if ( $normal ) {
            $standardCmd = 'elasticpress index --post-type=' . implode( ',', array_keys( $whitelist ) );

            WP_CLI::line( sprintf(  __( "\nRunning non-attachment index: '%s'\n", 'wp-proud-search-elastic' ), $standardCmd ) );

            sleep ( 1 );

            WP_CLI::runcommand( $standardCmd, $options );
        }

        

        // Run attachments
        // Use --nobulk to prevent index as opposed to just put /docs/ID
        if ( $attachments ) {
            $attachmentsCmd = 'elasticpress index --nobulk --post-type=' . implode( ',', $runSafe );

            WP_CLI::line( sprintf(  __( "\nRunning attachments index: '%s'\n", 'wp-proud-search-elastic' ), $attachmentsCmd ) );

            sleep ( 1 );

            WP_CLI::runcommand( $attachmentsCmd, $options );
        }
    }

    /**
     * Document Safe index method for all posts for a site
     * 
     * @subcommand index-safe
     */
    public function index_safe( ) {
        $this->_index();
    }

    /**
     * Document Safe index method for all non-attachment posts for a site
     * 
     * @subcommand index-safe-no-attachments
     */
    public function index_safe_no_attachments( ) {
        $this->_index( true, false );
    }

    /**
     * Document Safe index method for all attachment posts for a site
     * 
     * @subcommand index-safe-only-attachments
     */
    public function index_safe_only_attachments( ) {
        $this->_index( false, true );
    }

    /**
     * Puts mapping then calls index-safe
     * 
     * @subcommand mapping-and-index
     */
    public function mapping_and_index( ) {
        // Get class instance
        $instance = ProudElasticSearch::factory();

        // Set flag to force empty attachments post
        $instance->force_attachments = true;

        $options = array(
            'launch'     => false,  // Reuse the current process.
        );

        $cmd = 'elasticpress put-mapping';
        
        WP_CLI::line( sprintf(  __( "\nRunning put_mapping: '%s'\n", 'wp-proud-search-elastic' ), $cmd ) );

        sleep ( 1 );

        WP_CLI::runcommand( $cmd, $options );

        $this->index_safe();
    }
}