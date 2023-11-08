<?php

namespace Classifai\Features;

use \Classifai\Providers\OpenAI\ChatGPT;
use \Classifai\Providers\Azure\Speech;

use function Classifai\find_provider_class;

/**
 * Class TitleGeneration
 */
class TextToSpeech extends Feature {
	/**
	 * ID of the current feature.
	 *
	 * @var string
	 */
	const ID = 'feature_text_to_speech';

	/**
	 * Returns the label of the feature.
	 *
	 * @return string
	 */
	public function get_label() {
		return apply_filters(
			'classifai_' . static::ID . '_label',
			__( 'Text to speech', 'classifai' )
		);
	}

	/**
	 * Returns the providers supported by the feature.
	 *
	 * @return array
	 */
	public function get_providers() {
		return apply_filters(
			'classifai_' . static::ID . '_providers',
			[
				Speech::ID => __( 'Microsoft Azure AI Speech', 'classifai' ),
			]
		);
	}

	/**
	 * Sets up the fields and sections for the feature.
	 */
	public function setup_fields_sections() {
		$settings = $this->get_settings();

		add_settings_section(
			$this->get_option_name() . '_section',
			esc_html__( 'Feature settings', 'classifai' ),
			'__return_empty_string',
			$this->get_option_name()
		);

		add_settings_field(
			'status',
			esc_html__( 'Enable text to speech', 'classifai' ),
			[ $this, 'render_input' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'     => 'status',
				'input_type'    => 'checkbox',
				'default_value' => $settings['status'],
				'description'   => __( 'A button will be added to the status panel that can be used to generate titles.', 'classifai' ),
			]
		);

		add_settings_field(
			'roles',
			esc_html__( 'Allowed roles', 'classifai' ),
			[ $this, 'render_checkbox_group' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'      => 'roles',
				'options'        => $this->roles,
				'default_values' => $settings['roles'],
				'description'    => __( 'Choose which roles are allowed to use this feature.', 'classifai' ),
			]
		);

		$post_types        = \Classifai\get_post_types_for_language_settings();
		$post_type_options = array();

		foreach ( $post_types as $post_type ) {
			$post_type_options[ $post_type->name ] = $post_type->label;
		}

		add_settings_field(
			'post_types',
			esc_html__( 'Allowed post types', 'classifai' ),
			[ $this, 'render_checkbox_group' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'      => 'post_types',
				'options'        => $post_type_options,
				'default_values' => $settings['post_types'],
				'description'    => __( 'Choose which post types support this feature.', 'classifai' ),
			]
		);

		add_settings_field(
			'provider',
			esc_html__( 'Select a provider', 'classifai' ),
			[ $this, 'render_select' ],
			$this->get_option_name(),
			$this->get_option_name() . '_section',
			[
				'label_for'     => 'provider',
				'options'       => $this->get_providers(),
				'default_value' => $settings['provider'],
			]
		);

		/*
		 * The following fields are specific to the OpenAI ChatGPT provider.
		 * These fields will only be displayed if the provider is selected, and will remain hidden otherwise.
		 *
		 * If the feature supports multiple providers, then the fields should be added for each provider.
		 */
		$azure_speech = new Speech( $this );
		$azure_speech->add_api_key_field();
		$azure_speech->add_endpoint_url_field();
		$azure_speech->add_voices_options_field();
	}

	/**
	 * Returns true if the feature meets all the criteria to be enabled.
	 *
	 * @return boolean
	 */
	public function is_feature_enabled() {
		$access        = false;
		$settings      = $this->get_settings();
		$user_roles    = wp_get_current_user()->roles ?? [];
		$feature_roles = $settings['roles'] ?? [];

		// Check if user has access to the feature and the feature is turned on.
		if ( ! empty( $feature_roles ) && ! empty( array_intersect( $user_roles, $feature_roles ) ) ) {
			$access = true;
		}

		/**
		 * Filter to override permission to the generate title feature.
		 *
		 * @since 2.3.0
		 * @hook classifai_openai_chatgpt_{$feature}
		 *
		 * @param {bool}  $access Current access value.
		 * @param {array} $settings Current feature settings.
		 *
		 * @return {bool} Should the user have access?
		 */
		return apply_filters( 'classifai_' . static::ID . '_is_feature_enabled', $access, $settings );
	}

	protected function get_post_types_select_options() {
		$post_types = \Classifai\get_post_types_for_language_settings();
		$options    = array();

		foreach ( $post_types as $post_type ) {
			$options[ $post_type->name ] = $post_type->label;
		}

		return $options;
	}

	/**
	 * Returns the default settings for the feature.
	 *
	 * The root-level keys are the setting keys that are independent of the provider.
	 * Provider specific settings should be nested under the provider key.
	 *
	 * @todo Add a filter hook to allow other plugins to add their own settings.
	 *
	 * @return array
	 */
	public function get_default_settings() {
		return [
			'status'     => '0',
			'roles'      => $this->roles,
			'post_types' => [],
			'provider'   => Speech::ID,
			Speech::ID => [
				'endpoint_url' => '',
				'api_key'       => '',
				'authenticated' => false,
				'voice'         => '',
				'voices'        => [],
			],
		];
	}

	/**
	 * Sanitizes the settings before saving.
	 *
	 * @param array $settings The settings to be sanitized on save.
	 *
	 * @return array
	 */
	public function sanitize_settings( $settings ) {
		$new_settings = $this->get_settings();

		if ( empty( $settings['status'] ) || 1 !== (int) $settings['status'] ) {
			$new_settings['status'] = 'no';
		} else {
			$new_settings['status'] = '1';
		}

		if ( isset( $settings['roles'] ) && is_array( $settings['roles'] ) ) {
			$new_settings['roles'] = array_map( 'sanitize_text_field', $settings['roles'] );
		} else {
			$new_settings['roles'] = array_keys( get_editable_roles() ?? [] );
		}

		if ( isset( $settings['provider'] ) ) {
			$new_settings['provider'] = sanitize_text_field( $settings['provider'] );
		} else {
			$new_settings['provider'] = Speech::ID;
		}

		$post_types = \Classifai\get_post_types_for_language_settings();

		foreach ( $post_types as $post_type ) {
			if ( isset( $settings['post_types'][ $post_type->name ] ) ) {
				$new_settings['post_types'][ $post_type->name ] = $settings['post_types'][ $post_type->name ];
			} else {
				$new_settings['post_types'][ $post_type->name ] = null;
			}
		}

		/*
		 * These are the sanitization methods specific to the OpenAI ChatGPT provider.
		 * They sanitize the settings for the provider and then merge them into the new settings array.
		 *
		 * When multiple providers are supported, the sanitization methods for each provider should be called here.
		 */
		if ( isset( $settings[ Speech::ID ] ) ) {
			$provider_instance                           = new Speech( $this );
			$api_key_settings                            = $provider_instance->sanitize_settings( $settings );
			$new_settings[ Speech::ID ]['api_key']       = $api_key_settings[ Speech::ID ]['api_key'];
			$new_settings[ Speech::ID ]['endpoint_url']  = $api_key_settings[ Speech::ID ]['endpoint_url'];
			$new_settings[ Speech::ID ]['authenticated'] = $api_key_settings[ Speech::ID ]['authenticated'];
			$new_settings[ Speech::ID ]['voices']        = $api_key_settings[ Speech::ID ]['voices'];
			$new_settings[ Speech::ID ]['voice']         = $api_key_settings[ Speech::ID ]['voice'];
		}

		return apply_filters(
			'classifai_' . static::ID . '_sanitize_settings',
			$new_settings
		);
	}
}
