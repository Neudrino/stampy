<?php

/**
 * PHPStan stub for WP_CLI.
 *
 * WP-CLI is loaded conditionally and is not available to PHPStan's
 * autoloader. This stub provides the class signature so static analysis
 * can resolve WP_CLI::log(), WP_CLI::success(), etc.
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/**
		 * Log a message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( string $message ): void {}

		/**
		 * Log a success message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( string $message ): void {}

		/**
		 * Log a warning message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( string $message ): void {}

		/**
		 * Log an error message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function error( string $message ): void {}

		/**
		 * Add a command.
		 *
		 * @param string          $name    Command name.
		 * @param callable|string $command Command handler.
		 * @return void
		 */
		public static function add_command( string $name, $command ): void {}
	}
}
