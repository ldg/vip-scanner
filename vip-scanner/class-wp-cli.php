<?php

/**
 * Perform automated scanning with VIP Scanner
 *
 * @package wp-cli
 * @since 0.5
 * @see https://github.com/wp-cli/wp-cli
 */
WP_CLI::add_command( 'vip-scanner', 'VIPScanner_Command' );

class VIPScanner_Command extends WP_CLI_Command {

	/**
	 * Perform checks on a theme
	 *
	 * [--theme=<theme>]
	 * : Theme to scan. Defaults to current.
	 *
	 * [--scan_type=<scan_type>]
	 * : Type of scan to perform. Defaults to "VIP Theme Review"
	 *
	 * [--summary]
	 * : Summarize the results.
	 *
	 * [--format=<format>]
	 * : Output results to a given format: table, JSON, CSV. Defaults to table.
	 *
	 * @subcommand scan-theme
	 */
	public function scan_theme( $args, $assoc_args ) {
		$defaults = array(
			'theme'         => get_option( 'stylesheet' ),
			'scan_type'     => 'VIP Theme Review',
			'format'        => 'table'
		);

		$args = wp_parse_args( $assoc_args, $defaults );

		$scanner = VIP_Scanner::get_instance()->run_theme_review( $args['theme'], $args['scan_type'], array( 'checks' ) );

		if ( ! $scanner )
			WP_CLI::error( sprintf( 'Scanning of %s failed', $args['theme'] ) );

		self::scan_dir( $scanner, $args );
	}

	/**
	 * Perform checks on a directory
	 *
	 * [<dir>]
	 * : Directory to scan. Defaults to current.
	 *
	 * [--scan_type=<scan_type>]
	 * : Type of scan to perform. Defaults to "WP.org Theme Review"
	 *
	 * [--summary]
	 * : Summarize the results.
	 *
	 * [--format=<format>]
	 * : Output results to a given format: table, JSON, CSV. Defaults to table.
	 *
	 * @subcommand scan
	 */
	public function scan( $args, $assoc_args ) {
		if ( empty( $args[0] ) )
			$dir = getcwd();
		else
			$dir = realpath( $args[0] );

		$defaults = array(
			'scan_type' => 'VIP Theme Review',
			'format'    => 'table'
		);

		$args = wp_parse_args( $assoc_args, $defaults );

		$review = VIP_Scanner::get_instance()->get_review( $args['scan_type'] );

		if ( ! $review )
			WP_CLI::error( sprintf( 'Scanning of %s failed', $dir ) );

		$scanner = new DirectoryScanner( $dir, $review );
		$scanner->scan( array( 'checks', 'analyzers' ) );

		if ( ! $scanner )
			WP_CLI::error( sprintf( 'Scanning of %s failed', $dir ) );

		self::scan_dir( $scanner, $args );
	}

	private static function scan_dir( &$scanner, $args ) {

		if ( isset( $args['summary'] ) ) {
			$this->display_summary( $scanner, $args['format'] );
		} else {
			$this->display_errors( $scanner, $args['format'] );
		}
	}

	/**
	 * Runs the analyzers for the given review on the theme.
	 *
	 * [--theme=<theme>]
	 * : Theme to scan. Defaults to current.
	 *
	 * [--scan_type=<scan_type>]
	 * : Type of scan to perform. Defaults to "VIP Theme Review"
	 *
	 * [--depth=<depth>]
	 * : Number of levels of hierarchy to output. 0 outputs everything.
	 * Defaults to 1.
	 * 
	 * @subcommand analyze-theme
	 */
	public function analyze_theme( $args, $assoc_args ) {
		$defaults = array(
			'theme'	    => get_option( 'stylesheet' ),
			'scan_type' => 'VIP Theme Review',
			'depth'	    => 1,
		);

		$args = wp_parse_args( $assoc_args, $defaults );

		$scanner = VIP_Scanner::get_instance()->run_theme_review( $args['theme'], $args['scan_type'], array( 'analyzers' ) );

		if ( ! $scanner ) {
			WP_CLI::error( sprintf( 'Scanning of %s failed', $args['theme'] ) );
		}
	
		$errors = $scanner->get_errors();
		if ( ! empty( $errors ) ) {
			$this->display_errors( $scanner, 'table' );
		}

		$empty = array();
		$display_args = array(
			'bare'  => true,
			'depth' => intval( $args['depth'] ),
		);

		foreach ( $scanner->renderers as $renderer ) {
			// Display empty renderers after the others
			if ( $renderer->is_empty() ) {
				$empty[] = $renderer;
				continue;
			}

			if ( $renderer->name() !== 'Files' ) {
				$renderer->analyze_prefixes();
			}

			WP_CLI::line( $renderer->display( false, $display_args ) );
		}

		foreach ( $empty as $renderer ) {
			$renderer->display( true, $display_args );
		}
	}

	/**
	 * Display a summary of the errors found by the given scanner
	 * @param BaseScanner $scanner the scanner whose errors to display
	 * @param string $format 'table', 'JSON', or 'CSV'
	 */
	protected function display_summary( $scanner, $format ) {
		$results = $scanner->get_results();

		$data = array();

		$data[] = array(
				'key' 	=> __( 'Result' ),
				'value' => $results['result']
		);

		$data[] = array(
				'key' 	=> __( 'Total Files' ),
				'value' => $results['total_files']
		);

		$data[] = array(
				'key' 	=> __( 'Total Checks' ),
				'value' => $results['total_checks']
		);

		$data[] = array(
				'key' 	=> __( 'Total Errors' ),
				'value' => count( $results['errors'] )
		);

		$plurals = array(
			BaseScanner::LEVEL_BLOCKER => __( 'Blockers' ),
			BaseScanner::LEVEL_WARNING => __( 'Warnings' ),
			BaseScanner::LEVEL_NOTE    => __( 'Notes' ),
		);

		foreach ( $scanner->get_error_levels() as $level ) {
			$label       = $plurals[ $level ];
			$error_count = count( $scanner->get_errors( array( $level ) ) );

			$data[] = array(
					'key' 	=> $label,
					'value' => $error_count
			);
		}

		WP_CLI\Utils\format_items( $format, $data, array( 'key', 'value' ) );
	}

	/**
	 * Display errors found by the given scanner
	 * @param BaseScanner $scanner the scanner whose errors to display
	 * @param string $format 'table', 'JSON', or 'CSV'
	 */
	protected function display_errors( $scanner, $format ) {
		$data = array();

		foreach ( $scanner->get_error_levels() as $level ) {
			$errors 	= $scanner->get_errors( array( $level ) );

			foreach ( $errors as $error ) {
				$lines = array();

				// Not all errors have lines -- assign a null line if we lack lines entirely
				$lines =  ( isset( $error['lines'] ) ) ? $error['lines'] : array( '' );

				// In JSON output, group the lines together
				if ( 'json' == $format ) {
					$data[] = array(
							'level' 		=> $error['level'],
							'description' 	=> $error['description'],
							'lines' 		=> $lines,
							'file'			=> $error['file']
					);
				} else { // In other output, each line gets its own entry
					foreach ( $lines as $line ) {
						$data[] = array(
								'level' 		=> $error['level'],
								'description' 	=> $error['description'],
								'lines' 		=> $line,
								'file'			=> $error['file']
						);
					}
				}
			}
		}

		WP_CLI\Utils\format_items( $format, $data, array( 'level', 'description', 'lines', 'file' ) );
	}
}
