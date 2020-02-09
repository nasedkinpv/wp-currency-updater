<?php

/**
 * Example settings page
 */
add_filter('currency_updater_register_settings', 'currency_updater_settings');

function currency_updater_settings($settings)
{
	// General Settings section
	$settings[] = array(
		'section_id'          => 'general',
		'section_title'       => 'General Settings',
		'section_description' => 'This plugin fills custom post fields via exchange API rates (https://api.exchangeratesapi.io/)',
		'section_order'       => 5,
		'fields'              => array(
			array(
				'id'    => 'currency_updater_fieldname',
				'title' => 'Fieldname',
				'desc'  => 'Update to euro field with name',
				'type'  => 'text',
				'std'   => '_yacht_price'
			),
			array(
				'id'    => 'currency_updater_custom_post_type',
				'title' => 'Post type',
				'desc'  => 'Post type for update custom fields',
				'type'  => 'text',
				'std'   => 'yacht'
			),
			array(
				'id'    => 'currency_updater_apply_profit',
				'title' => 'Profit',
				'desc'  => 'Profit in % (added to converted)',
				'type'  => 'text',
				'std'   => '100'
			),
			array(
				'id'    => 'currency_updater_round_symbols',
				'title' => 'Round to',
				'desc'  => 'Which part of price round up in %',
				'type'  => 'text',
				'std'   => '30'
			)
		)
	);


	return $settings;
}
