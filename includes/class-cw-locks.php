<?php
/**
 * CryptoWoo Locks Class File
 *
 * @category   CryptoWoo
 * @package    OrderProcessing
 * @subpackage Checkout
 * @author     CryptoWoo AS
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
} // Exit if accessed directly

/**
 * CryptoWoo implementation of Locks to prevent race condition issues.
 *
 * @category   CryptoWoo
 * @package    OrderProcessing
 * @subpackage Checkout
 */
class CW_Locks {
	/**
	 * The key for the lock.
	 *
	 * @var string
	 */
	private string $lock_key;

	/**
	 * Maximum number of attempts to acquire the lock.
	 *
	 * @var int
	 */
	private int $max_attempts = 10;

	/**
	 * Timeout in seconds for attempting to acquire the lock.
	 *
	 * @var float
	 */
	private float $timeout = 5;

	/**
	 * Timeout in seconds for a stale lock.
	 * A stale lock is a lock that has failed to get released in a previous process.
	 * The constructor in this class will change the value to the PHP max execution time if it is higher.
	 *
	 * @var float
	 */
	private float $stale_timeout = 15;

	/**
	 * Minimum sleep duration in seconds.
	 *
	 * @var float
	 */
	private float $min_sleep = 0.1;

	/**
	 * Maximum sleep duration in seconds.
	 *
	 * @var float
	 */
	private float $max_sleep = 0.5;

	/**
	 * Constructor.
	 *
	 * @param string $lock_key The key for the lock.
	 */
	public function __construct( string $lock_key ) {
		$this->lock_key = $lock_key;

		// If the PHP max execution time is higher than the default stale timeout value, we will use that.
		// Using the PHP max execution time as the timeout value for stale locks ensure no concurrency.
		// Most servers will use a PHP max execution time 30 seconds, but 60 is also common.
		$php_max_execution_time = (float) ini_get( 'max_execution_time' );
		$this->stale_timeout    = max( $php_max_execution_time, $this->stale_timeout );
	}

	/**
	 * Attempt to acquire the lock.
	 *
	 * @return bool True if the lock was successfully acquired, false otherwise.
	 */
	public function acquire_lock(): bool {
		$attempt    = 0;
		$start_time = microtime( true ); // Record the start time.

		while ( $attempt < $this->max_attempts ) {
			// Check if the elapsed time exceeds the timeout.
			if ( $this->is_timeout_exceeded( $start_time ) ) {
				// Translators: %s is the lock name and %d is the number of seconds.
				$format = __( 'Timeout: unable to acquire lock %1$s within %2$d seconds.' );
				$msg    = sprintf( $format, $this->lock_key, $this->timeout );
				CW_AdminMain::cryptowoo_log_data( 0, __FUNCTION__, $msg, 'error' );

				return false;
			}

			// Attempt to acquire the lock.
			$lock_acquired = $this->attempt_lock();

			// If lock is not acquired, check for stale lock and retry.
			if ( ! $lock_acquired ) {
				$lock_value = get_option( $this->lock_key );

				if ( is_numeric( $lock_value ) && $this->is_lock_stale( $lock_value ) ) {
					$this->release_lock();
					$lock_acquired = $this->attempt_lock();
				}
			}

			if ( $lock_acquired ) {
				return true; // Lock acquired.
			}

			// Sleep before attempting again.
			$this->sleep_between_attempts();

			$attempt++;
		}

		// Failed to acquire the lock after multiple attempts.
		return false;
	}

	/**
	 * Check if the timeout has been exceeded.
	 *
	 * @param float $start_time The start time of the lock acquisition attempt.
	 *
	 * @return bool True if the timeout has been exceeded, false otherwise.
	 */
	private function is_timeout_exceeded( float $start_time ): bool {
		return microtime( true ) - $start_time >= $this->timeout;
	}

	/**
	 * Check if the lock is stale (acquired too long ago).
	 *
	 * @param int $lock_value The timestamp when the lock was acquired.
	 *
	 * @return bool True if the lock is stale, false otherwise.
	 */
	private function is_lock_stale( int $lock_value ): bool {
		return time() - $lock_value > $this->stale_timeout;
	}

	/**
	 * Attempt to acquire the lock.
	 *
	 * @return bool True if the lock was successfully acquired, false otherwise.
	 */
	private function attempt_lock() : bool {
		// Using $wpdb for direct database access due to potential issues with the WordPress add_option function.
		// In certain cases, add_option may update existing values instead of failing, leading to data integrity issues.
		// See the WordPress core ticket at https://core.trac.wordpress.org/ticket/51486 for more details.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `$wpdb->options` ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'no') /* LOCK */",
				$this->lock_key,
				time()
			)
		);

		return (bool) $result;
	}

	/**
	 * Release the lock.
	 */
	public function release_lock(): void {
		delete_option( $this->lock_key );
	}

	/**
	 * Sleep for a random duration between attempts.
	 */
	private function sleep_between_attempts(): void {
		// Sleep for a random duration between $min_sleep and $max_sleep before attempting again.
		$sleep_duration = $this->generate_sleep_duration();
		usleep( $sleep_duration * 1000000 );
	}

	/**
	 * Generate a random sleep duration between $min_sleep and $max_sleep.
	 *
	 * @return float The sleep duration in seconds.
	 */
	private function generate_sleep_duration(): float {
		return wp_rand( $this->min_sleep * 1000, $this->max_sleep * 1000 ) / 1000;
	}
}
