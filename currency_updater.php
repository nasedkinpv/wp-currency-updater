<?php
define('BASE_PATH', plugin_dir_path(__FILE__));
define('BASE_URL', plugin_dir_url(__FILE__));
require BASE_PATH . './vendor/autoload.php';

use \BenMajor\ExchangeRatesAPI\ExchangeRatesAPI;
use \BenMajor\ExchangeRatesAPI\Response;
use \BenMajor\ExchangeRatesAPI\Exception;
/*
Plugin Name: PYM Prices updater
Plugin URI: https://github.com/nasedkinpv/wp_currency_updater
Description: This plugin downloads the latest bid and ask rates from API https://api.exchangeratesapi.io/.
Exchange rates API is a free service for current and historical foreign exchange rates
published by the European Central Bank
Version: 1.0
Author: Ben Nasedkin
Author URI: http://nasedk.in/
License: GNU General Public License v3.0
License URI: https://github.com/nasedkinpv/wp_currency_updater/blob/master/LICENSE
Text Domain: currency-updater
Domain Path: /lang
*/
// deny direct access
if (!function_exists('add_action')) {
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit();
}

if (!defined('CURRENCY_UPDATER_VERSION')) {
	define('CURRENCY_UPDATER_VERSION', '0.1');
}

if (!defined('CURRENCY_UPDATER_MIN_WP_VERSION')) {
	define('CURRENCY_UPDATER_MIN_WP_VERSION', '3.9');
}

if (!defined('CURRENCY_UPDATER_PLUGIN_NAME')) {
	define('CURRENCY_UPDATER_PLUGIN_NAME', 'PYM Prices');
}

if (!defined('CURRENCY_UPDATER_PLUGIN_SLUG')) {
	define('CURRENCY_UPDATER_PLUGIN_SLUG', dirname(plugin_basename(__FILE__))); // currency-updater
}

if (!defined('CURRENCY_UPDATER_DIR_PATH')) {
	define('CURRENCY_UPDATER_DIR_PATH', plugin_dir_path(__FILE__));
}

if (!defined('CURRENCY_UPDATER_DIR_URL')) {
	define('CURRENCY_UPDATER_DIR_URL', trailingslashit(plugins_url(NULL, __FILE__)));
}

if (!defined('CURRENCY_UPDATER_OPTIONS')) {
	define('CURRENCY_UPDATER_OPTIONS', 'currency_updater_options');
}

if (!defined('CURRENCY_UPDATER_TEMPLATE_PATH')) {
	define('CURRENCY_UPDATER_TEMPLATE_PATH', trailingslashit(get_template_directory()) . trailingslashit(CURRENCY_UPDATER_PLUGIN_SLUG));
	// e.g. /wp-content/themes/__ACTIVE_THEME__/plugin-slug
}

// check WordPress version
global $wp_version;
if (version_compare($wp_version, CURRENCY_UPDATER_MIN_WP_VERSION, "<")) {
	exit(CURRENCY_UPDATER_PLUGIN_NAME . ' requires WordPress ' . CURRENCY_UPDATER_MIN_WP_VERSION . ' or newer.');
}

if (!class_exists('CURRENCY_UPDATER')) :
	/**
	 * Class CURRENCY_UPDATER
	 */
	class CURRENCY_UPDATER
	{

		/**
		 * @var currency_updater_settings
		 */
		private $settings_framework;

		/**
		 * Initialize the plugin. Set up actions / filters.
		 *
		 */
		public function __construct()
		{

			// i8n, uncomment for translation support
			//add_action('plugins_loaded', array($this, 'load_textdomain'));

			// Admin scripts
			// add_action('admin_enqueue_scripts', array($this, 'register_admin_scripts'));
			// add_action('admin_enqueue_scripts', array($this, 'register_admin_styles'));

			// Plugin action links
			add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);

			// // Frontend scripts
			// add_action('wp_enqueue_scripts', array($this, 'register_scripts'));
			// add_action('wp_enqueue_scripts', array($this, 'register_styles'));

			// actions
			add_action('admin_post_nopriv_update_rates', array($this, 'update_rates'));
			add_action('admin_post_update_rates', array($this, 'update_rates'));
			add_action('admin_post_nopriv_update_prices', array($this, 'update_prices'), 100, 1);
			add_action('admin_post_update_prices', array($this, 'update_prices'), 100, 1);
			// on save
			add_action('save_post', array($this, 'update_prices'), 100, 1);



			// Activation hooks
			register_activation_hook(__FILE__, array($this, 'activate'));
			// Cron event launcher
			add_action('currency_updater_rates_cron', array($this, 'update_rates'));
			add_action('currency_updater_prices_cron', array($this, 'update_prices'));
			register_deactivation_hook(__FILE__, array($this, 'deactivate'));

			// Uninstall hook
			register_uninstall_hook(CURRENCY_UPDATER_DIR_PATH . 'uninstall.php', NULL);

			// Settings Framework
			add_action('admin_menu', array($this, 'admin_menu'), 99);
			require_once(CURRENCY_UPDATER_DIR_PATH . 'lib/settings-framework/settings-framework.php');
			$this->settings_framework = new currency_updater_settings(CURRENCY_UPDATER_DIR_PATH . 'views/settings.php', CURRENCY_UPDATER_OPTIONS);
			// Add an optional settings validation filter (recommended)
			add_filter($this->settings_framework->get_option_group() . '_validate', array($this, 'validate_settings'));

			// uncomment to disable the Uninstall and Reset Default buttons
			$this->settings_framework->show_reset_button = TRUE;
			$this->settings_framework->show_uninstall_button = FALSE;

			add_action('init', array($this, 'init'), 0, 0); // filterable init action

			// $this->run(); // non-filterable init action
		}
		public function update_rates()
		{
			$load_data = function () {
				$rates = ['GBP', 'USD'];
				$lookup = new ExchangeRatesAPI();
				// if ($lookup) : // add check if onlinex
				$this->EUR  = $lookup->addRates($rates)->setBaseCurrency('EUR')->fetch()->getRates();
				$this->GBP  = $lookup->addRate('EUR')->addRate('USD')->setBaseCurrency('GBP')->fetch()->getRates();
				$this->USD  = $lookup->addRate('GBP')->addRate('EUR')->setBaseCurrency('USD')->fetch()->getRates();
				// return $data;
				$this->timestamp = date("Y-m-d H:i:s");
				// endif;
			};

			$save_data = function () {
				if (get_option('currency_updater_rates') !== false) {
					update_option('currency_updater_rates', json_encode($this));
				} else {
					$deprecated = null;
					$autoload = 'no';
					add_option('currency_updater_rates', json_encode($this), $deprecated, $autoload);
				}
			};
			$load_data();
			$save_data();
			$this->redirect();
			die();
		}
		public function update_prices($post_ID = null)
		{
			$update_fields = function ($post_ID) {
				$convert = function ($base, $target, $value) {
					$round = function ($value, $profit) {
						// $profit = $profit / 100;
						// round func
						$options = get_option('currency_updater_options');
						$round_to = $options['currency_updater_options_general_currency_updater_round_symbols'] ?? 20;
						$round_to = intval($round_to) / 100;
						$price = $value * floatval($profit) / 100;
						$price = ceil($price);
						$count = strlen((string) $price);
						$price = round($price, floor(-$count * $round_to), PHP_ROUND_HALF_UP); // round setting
						return $price;
					};
					$options = get_option('currency_updater_options');
					// var_dump($options);
					$apply_profit = $options['currency_updater_options_general_currency_updater_apply_profit'] ?? 100;
					$rates = get_option('currency_updater_rates');
					$rates = json_decode($rates);
					// $rates = json_decode($rates);
					// 1000$ = 1000 * USD->EUR;
					$target = $rates->{$base}->{$target};
					$target = floatval($target);

					if ($target) :
						return $round(floatval($value * $target), $apply_profit);
					else : return $round(floatval($value), $apply_profit) * 1;
					endif;
				};
				$options = get_option('currency_updater_options');
				$fieldname = $options['currency_updater_options_general_currency_updater_fieldname'] ?? '_yacht_price';
				// if euro do nothing
				$price = get_post_meta($post_ID, $fieldname); // main price
				if (is_array($price)) {
					$price = $price[0];
				}
				$price = floatval($price); // main price
				$gbp = get_post_meta($post_ID, $fieldname . '_gbp'); // main price
				if (is_array($gbp)) {
					$gbp = $gbp[0];
				}
				$gbp = floatval($gbp);
				$usd = get_post_meta($post_ID, $fieldname . '_usd'); // main price
				if (is_array($usd)) {
					$usd = $usd[0];
				}
				$usd = floatval($usd);
				if ($price > 0) {
					update_post_meta($post_ID, $fieldname . '_cc_usd', $convert('EUR', 'USD', $price));
					update_post_meta($post_ID, $fieldname . '_cc_gbp', $convert('EUR', 'GBP', $price));
					update_post_meta($post_ID, $fieldname . '_cc_eur', $convert('EUR', 'EUR', $price));
					update_post_meta($post_ID, $fieldname . '_gbp', $convert('EUR', 'GBP', $price));
					update_post_meta($post_ID, $fieldname . '_usd', $convert('EUR', 'USD', $price));
				} elseif ($gbp > 0 && $price <= 0 && $usd <= 0) {
					update_post_meta($post_ID, $fieldname, $convert('GBP', 'EUR', $gbp));
					update_post_meta($post_ID, $fieldname . '_cc_usd', $convert('GBP', 'USD', $gbp));
					update_post_meta($post_ID, $fieldname . '_cc_gbp', $convert('GBP', 'GBP', $gbp));
					update_post_meta($post_ID, $fieldname . '_cc_eur', $convert('GBP', 'EUR', $gbp));
					update_post_meta($post_ID, $fieldname . '_gbp', $convert('GBP', 'GBP', $gbp));
					update_post_meta($post_ID, $fieldname . '_usd', $convert('GBP', 'USD', $gbp));
				} elseif ($usd > 0 && $gbp <= 0 && $price <= 0) {
					update_post_meta($post_ID, $fieldname, $convert('USD', 'EUR', $usd));
					update_post_meta($post_ID, $fieldname . '_cc_usd', $convert('USD', 'USD', $usd));
					update_post_meta($post_ID, $fieldname . '_cc_gbp', $convert('USD', 'GBP', $usd));
					update_post_meta($post_ID, $fieldname . '_cc_eur', $convert('USD', 'EUR', $usd));
					update_post_meta($post_ID, $fieldname . '_gbp', $convert('USD', 'GBP', $usd));
					update_post_meta($post_ID, $fieldname . '_usd', $convert('USD', 'USD', $usd));
				} else {
					// do nothing
				}
			};
			$post_type = get_option('currency_updater_options_general_currency_updater_custom_post_type', 'yacht');
			if (!$post_ID) {
				$posts = get_posts(array(
					'numberposts' => -1,
					'post_type'   => $post_type,
					'suppress_filters' => true,
				));
				foreach ($posts as $post) {
					$update_fields($post->ID);
				}
				if (get_option('currency_updater_posts_timestamp') !== false) {
					update_option('currency_updater_posts_timestamp', date("Y-m-d H:i:s"));
				} else {
					$deprecated = null;
					$autoload = 'no';
					add_option('currency_updater_posts_timestamp', date("Y-m-d H:i:s"), $deprecated, $autoload);
				}
			} else {
				if (get_post_type($post_ID) !== $post_type) return null;
				$update_fields($post_ID);
			}
			$this->redirect();
		}
		public function init()
		{
			$init_action = apply_filters('currency_updater_init_action', 'init');
			// add_action($init_action, array($this, 'update_rates'));
		}


		/**
		 * Returns the class name and version.
		 *
		 * @return string
		 */
		public function __toString()
		{
			return get_class($this) . ' ' . $this->get_version();
		}

		/**
		 * Returns the plugin version number.
		 *
		 * @return string
		 */
		public function get_version()
		{
			return CURRENCY_UPDATER_VERSION;
		}

		/**
		 * @return string
		 */
		public function get_plugin_url()
		{
			return CURRENCY_UPDATER_DIR_URL;
		}

		/**
		 * @return string
		 */
		public function get_plugin_path()
		{
			return CURRENCY_UPDATER_DIR_PATH;
		}

		/**
		 * Register the plugin text domain for translation
		 *
		 */
		public function load_textdomain()
		{
			load_plugin_textdomain(CURRENCY_UPDATER_PLUGIN_SLUG, FALSE, CURRENCY_UPDATER_DIR_PATH . '/lang');
		}

		/**
		 * Activation
		 */
		public function activate()
		{
			wp_schedule_event(time(), 'daily', 'currency_updater_rates_cron');
			wp_schedule_event(time(), 'daily', 'currency_updater_prices_cron');
		}

		/**
		 * Deactivation
		 */
		public function deactivate()
		{
			wp_clear_scheduled_hook('currency_updater_cron');
			wp_clear_scheduled_hook('currency_updater_rates_cron');
			wp_clear_scheduled_hook('currency_updater_prices_cron');
		}

		/**
		 * Install
		 */
		public function install()
		{
		}

		/**
		 * WordPress options page
		 *
		 */
		public function admin_menu()
		{
			// top level page
			add_menu_page(__(CURRENCY_UPDATER_PLUGIN_NAME, 'currency-updater'), __(CURRENCY_UPDATER_PLUGIN_NAME, 'currency-updater'), 'manage_options', CURRENCY_UPDATER_PLUGIN_SLUG, array($this, 'settings_page'));
			// Settings page
			// add_submenu_page('options-general.php', __(CURRENCY_UPDATER_PLUGIN_NAME . ' Settings', 'currency-updater'), __(CURRENCY_UPDATER_PLUGIN_NAME . ' Settings', 'currency-updater'), 'manage_options', CURRENCY_UPDATER_PLUGIN_SLUG, array(
			// 	$this,
			// 	'settings_page'
			// ));
		}

		/**
		 *  Settings page
		 *
		 */
		public function settings_page()
		{
			$rates = $this->get_settings('currency_updater_rates');
			$rates = json_decode($rates);
?>
			<div class="wrap">
				<h2><?php echo CURRENCY_UPDATER_PLUGIN_NAME; ?></h2>
				<h3>Exchange rates</h3>
				<p>This plugin downloads the latest bid and ask rates from API (<a href="https://exchangeratesapi.io/"> https://api.exchangeratesapi.io/</a>).
					Exchange rates API is a free service for current and historical foreign exchange rates
					published by the European Central Bank</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">USD</th>
							<td><?= $rates->USD->EUR ?>
								<p class="description">USD to Euro</p>
							</td>
							<td><?= $rates->USD->GBP ?>
								<p class="description">USD to GBP</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Euro</th>
							<td><?= $rates->EUR->USD ?>
								<p class="description">Euro to USD</p>
							</td>
							<td><?= $rates->EUR->GBP ?>
								<p class="description">Euro to GBP</p>
							</td>
						</tr>
						<tr>
							<th scope="row">GBP</th>
							<td><?= $rates->GBP->USD ?>
								<p class="description">GBP to USD</p>
							</td>
							<td><?= $rates->GBP->EUR ?>
								<p class="description">GBP to Euro</p>
							</td>
						</tr>
						<tr>
							<th scope="row"></th>

							<td><?= $rates->timestamp; ?>
								<p class="description">Exchange rates latest update</p>
							</td>
							<td>
								<form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
									<input type="hidden" name="action" value="update_rates">
									<? submit_button('Update rates'); ?>
								</form>
							</td>
						</tr>
						<tr>
							<th scope="row"></th>

							<td><?= get_option('currency_updater_posts_timestamp') ?>
								<p class="description">Posts prices latest update</p>
							</td>
							<td>
								<form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
									<input type="hidden" name="action" value="update_prices">
									<? submit_button('Update posts'); ?>
								</form>
							</td>
						</tr>
					</tbody>
				</table>
				<?php
				// Output settings-framework form
				$this->settings_framework->settings();
				?>
			</div>
<?php

			// Get settings


			// Get individual setting
			// $setting = $this->get_setting(CURRENCY_UPDATER_OPTIONS, 'general', 'text');
			// var_dump($setting);
		}

		/**
		 * Settings validation
		 *
		 * @see $sanitize_callback from http://codex.wordpress.org/Function_Reference/register_setting
		 *
		 * @param $input
		 *
		 * @return mixed
		 */
		public function validate_settings($input)
		{
			return $input;
		}

		/**
		 * Converts the settings-framework filename to option group id
		 *
		 * @param $settings_file string settings-framework file
		 *
		 * @return string option group id
		 */
		public function get_option_group($settings_file)
		{
			$option_group = preg_replace("/[^a-z0-9]+/i", "", basename($settings_file, '.php'));
			return $option_group;
		}

		/**
		 * Get the settings from a settings-framework file/option group
		 *
		 * @param $option_group string option group id
		 *
		 * @return array settings
		 */
		public function get_settings($option_group)
		{
			return get_option($option_group);
		}

		/**
		 * Get a setting from an option group
		 *
		 * @param $option_group string option group id
		 * @param $section_id   string section id
		 * @param $field_id     string field id
		 *
		 * @return mixed setting or false if no setting exists
		 */
		public function get_setting($option_group, $section_id, $field_id)
		{
			$options = get_option($option_group);
			if (isset($options[$option_group . '_' . $section_id . '_' . $field_id])) {
				return $options[$option_group . '_' . $section_id . '_' . $field_id];
			}
			return FALSE;
		}

		/**
		 * Delete all the saved settings from a settings-framework file/option group
		 *
		 * @param $option_group string option group id
		 */
		public function delete_settings($option_group)
		{
			delete_option($option_group);
		}

		/**
		 * Deletes a setting from an option group
		 *
		 * @param $option_group string option group id
		 * @param $section_id   string section id
		 * @param $field_id     string field id
		 *
		 * @return mixed setting or false if no setting exists
		 */
		public function delete_setting($option_group, $section_id, $field_id)
		{
			$options = get_option($option_group);
			if (isset($options[$option_group . '_' . $section_id . '_' . $field_id])) {
				$options[$option_group . '_' . $section_id . '_' . $field_id] = NULL;
				return update_option($option_group, $options);
			}
			return FALSE;
		}

		/**
		 *
		 * Add a settings link to plugins page
		 *
		 * @param $links
		 * @param $file
		 *
		 * @return array
		 */
		public function plugin_action_links($links, $file)
		{
			if ($file == plugin_basename(__FILE__)) {
				$settings_link = '<a href="options-general.php?page=' . CURRENCY_UPDATER_PLUGIN_SLUG . '" title="' . __(CURRENCY_UPDATER_PLUGIN_NAME, 'currency-updater') . '">' . __('Settings', 'currency-updater') . '</a>';
				array_unshift($links, $settings_link);
			}
			return $links;
		}

		/**
		 * Run
		 */
		private function run()
		{
			// var_dump($this->get_posts());
		}

		public function redirect()
		{
			$redirect = "admin.php?page=" . CURRENCY_UPDATER_PLUGIN_SLUG;
			if (isset($_POST['redirect'])) {
				$redirect = $_POST['redirect'];
				$redirect = wp_validate_redirect($redirect, home_url());
			}
			if ($_POST["action"] === 'update_prices') {
				wp_redirect($redirect);
			}
		}
	}

endif;

$currency_updater = new CURRENCY_UPDATER();
