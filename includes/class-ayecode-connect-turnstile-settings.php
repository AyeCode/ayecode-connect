<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle Cloudflare Turnstile settings for AyeCode Connect.
 */
class AyeCode_Connect_Turnstile_Settings {
	private static $instance = null;

	/**
	 * Settings array.
	 */
	private $settings = array(
		'site_key'      => '',
		'secret_key'    => '',
		'theme'         => 'light',
		'size'          => 'normal',
		'check_verified'=> '',
		'disable_roles' => [],
		'protections'   => array(
			'login'            => 1,
			'register'         => 1,
			'forgot_password'  => 1,
			'comments'         => 1,
			'gd_add_listing'   => 1,
			'gd_report_post'   => 1,
			'gd_claim_listing' => 1,
			'uwp_login'        => 1,
			'uwp_register'     => 1,
			'uwp_forgot'       => 1,
			'uwp_account'      => 1,
			'uwp_frontend'     => 1,
			'bs_contact'       => 1,
			'gp_checkout'      => 1,
			'gd_pay_per_lead'  => 1,
		)
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_item' ), 11 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Add submenu item.
	 */
	public function add_menu_item() {
		add_submenu_page(
			'ayecode-connect',
			__( 'Turnstile Settings', 'ayecode-connect' ),
			__( 'Turnstile Captcha', 'ayecode-connect' ),
			'manage_options',
			'ayecode-turnstile',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'ayecode_turnstile_settings', 'ayecode_turnstile_options' );
	}

	/**
	 * Settings page content.
	 */
	public function settings_page() {
		global $aye_turnstile_setting;

		$options             = $this->get_turnstile_options();
		$site_key_constant   = defined( 'AYECODE_TURNSTILE_SITE_KEY' );
		$secret_key_constant = defined( 'AYECODE_TURNSTILE_SECRET_KEY' );
		$keys_found          = $this->get_site_key() && $this->get_secret_key() ? true : false;
		$option_verified     = get_option( 'ayecode_turnstile_verified' );
		$is_verified         = $keys_found && $this->is_verified( false ) ? true : false;
		$aye_turnstile_setting = true;

		// Set default to know if not set yet.
		if ( ! $is_verified && $option_verified != 'no' ) {
			update_option( 'ayecode_turnstile_verified', 'no' );
		}
		?>
        <div class="bsui" style="margin-left: -20px;">
            <!-- Clean & Mean UI -->
            <style>
                #wpbody-content > div.notice,
                #wpbody-content > div.error {
                    display: none;
                }
            </style>
            <!-- Header -->
            <nav class="navbar bg-white border-bottom">
                <a class="navbar-brand p-0" href="#">
                    <img src="<?php echo plugins_url( 'assets/img/ayecode.png', dirname( __FILE__ ) ); ?>" width="120"
                         alt="AyeCode Ltd">
                </a>
            </nav>

            <div class="container-fluid py-4">
                <div class="row">
                    <div class="col-12 col-md-8 mx-auto">
                        <div class="card shadow-sm mw-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h2 class="h5 mb-0"><i class="fab fa-cloudflare text-orange"></i> <?php _e( 'Cloudflare Turnstile Settings', 'ayecode-connect' ); ?></h2>
                                <div class=""><i class="fas fa-book"></i> <a href="https://wpgeodirectory.com/documentation/article/how-tos/setting-up-cloudflare-turnstile-in-ayecode-connect/" target="_blank"><?php _e( 'Documentation', 'ayecode-connect' ); ?></a> </div>
                            </div>
                            <div class="card-body">
								<?php

                                // settings saved
								if ( ! empty( $_REQUEST['settings-updated'] ) ) {
									echo aui()->alert( array(
											'type'    => 'success',
											'content' => __( "Settings Saved", "ayecode-connect" )
										)
									);
								}

								if ( $site_key_constant || $secret_key_constant ) {
									echo aui()->alert( array(
											'type'    => 'info',
											'content' => __( "Some settings are defined in wp-config.php and cannot be modified here.", "ayecode-connect" )
										)
									);
								}

								if ( defined( 'UWP_RECAPTCHA_VERSION' ) ) {
									echo aui()->alert( array(
											'type'    => 'danger',
											'content' => __( "Please disable UsersWP reCaptch plugin. This plugin replaces the need for it.", "ayecode-connect" )
										)
									);
								}

								if ( ! $is_verified && $this->check_verified() ) {
									echo aui()->alert( array(
											'type'    => 'danger',
											'content' => __( 'Turnstile will be added to any frontend forms only after the api keys are successfully verified.', 'ayecode-connect' )
										)
									);
								}

								if ( ! $is_verified && $keys_found ) {
								?>
								<?php $this->enqueue_turnstile_script(); ?>
                                        <div class="row alert alert-danger">
                                            <div class="  col-sm-4 col-form-label text form-label">
                                                <?php
                                                _e('Keys MUST be verified before use.','ayecode-connect');
                                                ?>
                                            </div>
                                            <div class="col-sm-8">
                                                <form id="ayecode_turnstile_form" method="POST">
                                                    <input type="hidden" name="action" value="ayecode_connect_verify_turnstile_keys">
                                                    <input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( "ayecode-turnstile-verify-keys" ) ); ?>">
                                                    <div class="mb-1" stylex="height:0;overflow:hidden;width:0">
			                                            <?php
                                                        do_action( 'ayecode_verify_turnstile_form_fields' );
			                                            _e('Please complete the captcha and then click "verify keys" below. If captcha is not shown, your keys may be invalid or using "invisible" mode. ','ayecode-connect');

			                                            ?>

                                                    </div>
                                                </form>
                                            </div>

                                        </div>

								<?php } ?>

                                <form method="post" action="options.php">
									<?php settings_fields( 'ayecode_turnstile_settings' ); ?>

                                    <!-- API Keys -->
                                    <div class="mb-4">
                                        <h3 class="h6"><?php _e( 'API Keys', 'ayecode-connect' ); ?></h3>
										<?php
										echo aui()->input(
											array(
												'id'               => 'site_key',
												'name'             => 'ayecode_turnstile_options[site_key]',
												'label_type'       => 'horizontal',
												'label'            => __( 'Site Key', 'ayecode-connect' ),
												'label_col'        => '4',
												'type'             => 'text',
												'value'            => esc_attr( $site_key_constant ? constant( 'AYECODE_TURNSTILE_SITE_KEY' ) : $options['site_key'] ),
												'extra_attributes' => $site_key_constant ? [ 'disabled' => 'disabled' ] : array()
											)
										);

										echo aui()->input(
											array(
												'id'               => 'secret_key',
												'name'             => 'ayecode_turnstile_options[secret_key]',
												'label_type'       => 'horizontal',
												'label'            => __( 'Secret Key', 'ayecode-connect' ),
												'label_col'        => '4',
												'type'             => 'password',
												'value'            => esc_attr( $secret_key_constant ? constant( 'AYECODE_TURNSTILE_SECRET_KEY' ) : $options['secret_key'] ),
												'extra_attributes' => $secret_key_constant ? [ 'disabled' => 'disabled' ] : array()
											)
										);
										?>
                                    </div>

									<input type="hidden" name="ayecode_turnstile_options[check_verified]" id="check_verified" value="1">
									<?php if ( $keys_found ) { ?>
									<div class="mb-3 row">
										<?php if ( $is_verified ) { ?>
										<div class="col-sm-4"><h3 class="h6"><?php esc_html_e( 'Status', 'ayecode-connect' ); ?></h3></div>
										<div class="col-sm-8"><button class="btn btn-success btn-sm disabled"><i class="fas fa-check-circle mr-2 me-2"></i><?php esc_html_e( 'Verified', 'ayecode-connect' ); ?></button></div>
										<?php } else { ?>
										<div class="col-sm-4"><h3 class="h6"><?php esc_html_e( 'Status', 'ayecode-connect' ); ?></h3></div>
										<div class="col-sm-8"><button class="btn border-0 disabled text-danger p-0 mr-4 me-4"><i class="fas fa-circle-exclamation mr-2 me-2"></i><?php esc_html_e( 'Not Verified', 'ayecode-connect' ); ?></button><button id="ayecode_turnstile_verify" type="button" data-label-process="<?php esc_html_e( 'Verifying...', 'ayecode-connect' ); ?>" data-label-done="<?php esc_html_e( 'Verified', 'ayecode-connect' ); ?>" class="btn btn-info btn-sm"><?php esc_html_e( 'Verify Keys', 'ayecode-connect' ); ?></button></div>
										<?php } ?>
									</div>
									<div class="aye-turnstile-message mb-3 d-none"></div>
									<?php } ?>

                                    <!-- Appearance -->
                                    <div class="mb-4">
                                        <h3 class="h6"><?php _e( 'Appearance', 'ayecode-connect' ); ?></h3>

										<?php
										echo aui()->select(
											array(
												'id'         => "theme",
												'name'       => "ayecode_turnstile_options[theme]",
												'label_type' => 'horizontal',
												'label_col'  => '4',
												'multiple'   => false,
												'class'      => 'mw-100',
												'options'    => array(
													'light' => __( 'Light', 'ayecode-connect' ),
													'dark'  => __( 'Dark', 'ayecode-connect' ),
													'auto'  => __( 'Auto', 'ayecode-connect' ),
												),
												'label'      => __( 'Theme', 'ayecode-connect' ),
												'value'      => $options['theme'],
											)
										);

										echo aui()->select(
											array(
												'id'         => "size",
												'name'       => "ayecode_turnstile_options[size]",
												'label_type' => 'horizontal',
												'label_col'  => '4',
												'multiple'   => false,
												'class'      => 'mw-100',
												'options'    => array(
													'normal'   => __( 'Normal', 'ayecode-connect' ),
													'compact'  => __( 'Compact', 'ayecode-connect' ),
													'flexible' => __( 'Flexible', 'ayecode-connect' ),
												),
												'label'      => __( 'Size', 'ayecode-connect' ),
												'value'      => $options['size'],
											)
										);
										?>
                                    </div>

                                    <!-- Integration Settings -->
                                    <div class="mb-4">
                                        <h3 class="h6"><?php _e( 'Enable Protection On', 'ayecode-connect' ); ?></h3>

										<?php

										$turnstile_protections = [
											'login'           => [
												'title'   => __( 'WordPress Login', 'ayecode-connect' ),
												'default' => true
											],
											'register'        => [
												'title'   => __( 'WordPress Registration', 'ayecode-connect' ),
												'default' => true
											],
											'forgot_password' => [
												'title'   => __( 'WordPress Forgot Password', 'ayecode-connect' ),
												'default' => true
											],
											'comments'        => [
												'title'   => __( 'Comments Form (includes GeoDirectory Reviews)', 'ayecode-connect' ),
												'default' => true
											]
										];


										// GeoDirectory
										if ( defined( 'GEODIRECTORY_VERSION' ) ) {
											$turnstile_protections['gd_add_listing'] = [
												'title'   => __( 'GeoDirectory Add Listing Form', 'ayecode-connect' ),
												'default' => true
											];

											$turnstile_protections['gd_report_post'] = [
												'title'   => __( 'GeoDirectory Report Post Form', 'ayecode-connect' ),
												'default' => true
											];
										}

										// GD Claim listing addon
										if ( defined( 'GEODIR_CLAIM_VERSION' ) ) {
											$turnstile_protections['gd_claim_listing'] = [
												'title'   => __( 'GeoDirectory Claim Listing Form (standard)', 'ayecode-connect' ),
												'default' => true
											];
										}

										// GD PayPer Lead
										if ( defined( 'GEODIR_PPL_VERSION' ) ) {
											$turnstile_protections['gd_pay_per_lead'] = [
												'title'   => __( 'GeoDirectory PayPer Lead', 'ayecode-connect' ),
												'default' => true
											];
										}

										if ( defined( 'USERSWP_VERSION' ) ) {
											$turnstile_protections['uwp_login']    = [
												'title'   => __( 'UsersWP Login', 'ayecode-connect' ),
												'default' => true
											];
											$turnstile_protections['uwp_register'] = [
												'title'   => __( 'UsersWP Registration', 'ayecode-connect' ),
												'default' => true
											];
											$turnstile_protections['uwp_forgot']   = [
												'title'   => __( 'UsersWP Forgot Password', 'ayecode-connect' ),
												'default' => true
											];
											$turnstile_protections['uwp_account']  = [
												'title'   => __( 'UsersWP Account', 'ayecode-connect' ),
												'default' => true
											];

										}

                                        if ( defined( 'UWP_MAILERLITE_VERSION' ) ) {
                                            $turnstile_protections['uwp_mailerlite_subscribe'] = [
                                                'title'   => __( 'UsersWP  - MailerLite Subscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                            $turnstile_protections['uwp_mailerlite_unsubscribe'] = [
                                                'title'   => __( 'UsersWP  - MailerLite Unsubscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                        }

										if ( defined( 'UWP_MC_VERSION' ) ) {
                                            $turnstile_protections['uwp_mc_subscribe'] = [
                                                'title'   => __( 'UsersWP  - MailChimp Subscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                            $turnstile_protections['uwp_mc_unsubscribe'] = [
                                                'title'   => __( 'UsersWP  - MailChimp Unsubscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                        }

										if ( defined( 'UWP_MAILPOET_VERSION' ) ) {
                                            $turnstile_protections['uwp_mailpoet_subscribe'] = [
                                                'title'   => __( 'UsersWP  - Mailpoet Subscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                            $turnstile_protections['uwp_mailpoet_unsubscribe'] = [
                                                'title'   => __( 'UsersWP  - Mailpoet Unsubscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                        }

										if ( defined( 'UWP_ACTIVECAMPAIGN_VERSION' ) ) {
                                            $turnstile_protections['uwp_active_campaign_subscribe'] = [
                                                'title'   => __( 'UsersWP  - ActiveCampaign Subscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                            $turnstile_protections['uwp_active_campaign_unsubscribe'] = [
                                                'title'   => __( 'UsersWP  - ActiveCampaign Unsubscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                        }

										if ( defined( 'UWP_BREVO_VERSION' ) ) {
                                            $turnstile_protections['uwp_brevo_subscribe'] = [
                                                'title'   => __( 'UsersWP  - Brevo Subscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                            $turnstile_protections['uwp_brevo_unsubscribe'] = [
                                                'title'   => __( 'UsersWP  - Brevo Unsubscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                        }

                                        if ( defined( 'UWP_GETRESPONSE_VERSION' ) ) {
                                            $turnstile_protections['uwp_getresponse_subscribe'] = [
                                                'title'   => __( 'UsersWP  - Getresponse Subscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                            $turnstile_protections['uwp_getresponse_unsubscribe'] = [
                                                'title'   => __( 'UsersWP  - Getresponse Unsubscribe Form', 'ayecode-connect' ),
                                                'default' => true
                                            ];
                                        }

										// UWP Frontend Post Addon
										if ( defined( 'UWP_FRONTEND_POST_VERSION' ) ) {
											$turnstile_protections['uwp_frontend'] = [
												'title'   => __( 'UsersWP Frontend Post', 'ayecode-connect' ),
												'default' => true
											];
										}


										// BlockStrap Contact Form
										if ( defined( 'BLOCKSTRAP_BLOCKS_VERSION' ) ) {
											$turnstile_protections['bs_contact'] = [
												'title'   => __( 'BlockStrap Contact Form', 'ayecode-connect' ),
												'default' => true
											];
										}

										// GetPaid Checkout Form
										if ( defined( 'WPINV_VERSION' ) ) {
											$turnstile_protections['gp_checkout'] = [
												'title'   => __( 'GetPaid Checkout Form', 'ayecode-connect' ),
												'default' => true
											];
										}




										$turnstile_protections = apply_filters( 'ayecode_turnstile_protections', $turnstile_protections );

										if ( ! empty( $turnstile_protections ) ) {
											foreach ( $turnstile_protections as $protection_key => $protection_value ) {
                                                $value = isset($options['protections'][$protection_key]) ? absint($options['protections'][$protection_key]) : absint( $protection_value['default'] );
												echo aui()->input(
													array(
														'id'               => $protection_key,
														'name'             => 'ayecode_turnstile_options[protections][' . $protection_key . ']',
														'label'            => $protection_value['title'],
														'label_type'       => 'horizontal',
														'label_col'        => '4',
														'type'             => 'checkbox',
                                                        'with_hidden'      => true,
														'label_force_left' => true,
														'checked'          => $value,
														'value'            => '1',
														'switch'           => 'md',
													)
												);
											}
										}

										?>

                                    </div>


                                    <!-- User Role Settings -->
                                    <div class="mb-4">
                                        <h3 class="h6"><?php _e( 'Disable for', 'ayecode-connect' ); ?></h3>

										<?php

										$roles        = get_editable_roles();
										$role_options = array();
										if ( count( $roles ) > 0 ) {
											foreach ( $roles as $role => $data ) {
												$role_options[ $role ] = $data['name'];
											}
										}

										echo aui()->select(
											array(
												'id'         => 'disable_roles',
												'name'       => 'ayecode_turnstile_options[disable_roles][]',
												'label_type' => 'horizontal',
												'label_col'  => '4',
												'multiple'   => true,
												'select2'    => true,
												'class'      => ' mw-100',
												'options'    => $role_options,
												'label'      => __( 'Disable for user roles', 'geodirectory' ),
												'value'      => $options['disable_roles'],
											)
										);
										?>

                                    </div>
                                    <button type="submit"
                                            class="btn btn-primary"><?php _e( 'Save Changes', 'ayecode-connect' ); ?></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
		<?php
	}

	/**
	 * Get turnstile options.
	 *
	 * @since.1.4.3
	 *
	 * @return array Turnstile options.
	 */
	public function get_turnstile_options() {
		$options = get_option( 'ayecode_turnstile_options', $this->settings );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return $options;
	}

	/**
	 * Check keys verification for backward compatibility.
	 *
	 * @since.1.4.3
	 *
	 * @return bool The site key if defined, or an empty string if not available.
	 */
	public function check_verified() {
		$options = $this->get_turnstile_options();

		if ( ! empty( $options['check_verified'] ) ) {
			return true;
		}

		// Skip to allow backward compatibility.
		return false;
	}

	/**
	 * Retrieves the site key for the configuration.
	 *
	 * This function checks if a predefined constant `AYECODE_TURNSTILE_SITE_KEY` is available and returns its value.
	 * If the constant is not defined, it retrieves the site key from the options, sanitizes it, and returns the sanitized value.
	 *
	 * @since.1.4.3
	 *
	 * @return string The site key if defined, or an empty string if not available.
	 */
	private function get_site_key() {
		if ( defined( 'AYECODE_TURNSTILE_SITE_KEY' ) ) {
			return AYECODE_TURNSTILE_SITE_KEY;
		}

		$options = $this->get_turnstile_options();

		return isset( $options['site_key'] ) ? sanitize_text_field( $options['site_key'] ) : '';
	}

	/**
	 * Retrieves the secret key used for authentication or configuration.
	 *
	 * This function checks if a constant (`AYECODE_TURNSTILE_SECRET_KEY`) is defined and returns its value if available.
	 * If the constant is not defined, it attempts to retrieve the secret key from the `options` array, sanitizing the value.
	 *
	 * @since.1.4.3
	 *
	 * @return string Returns the secret key if available; otherwise, returns an empty string.
	 */
	private function get_secret_key() {
		if ( defined( 'AYECODE_TURNSTILE_SECRET_KEY' ) ) {
			return AYECODE_TURNSTILE_SECRET_KEY;
		}

		$options = $this->get_turnstile_options();

		return isset( $options['secret_key'] ) ? sanitize_text_field( $options['secret_key'] ) : '';
	}

	/**
	 * Enqueue turnstile script.
	 *
	 * @since.1.4.3
	 */
	public function enqueue_turnstile_script() {
		add_action( 'admin_footer', array( $this, 'add_turnstile_script' ) );
	}

	/**
	 * Check turnstile is verified.
	 *
	 * @since.1.4.3
	 *
	 * @param bool $skip_check Force true for backward compatibility.
	 * @return bool True if validated, else False.
	 */
	public function is_verified( $skip_check = false ) {
		if ( $skip_check && ! $this->check_verified() ) {
			return true;
		}

		$option_value = get_option( 'ayecode_turnstile_verified' );

		if ( empty( $option_value ) || $option_value == 'no' ) {
			return false;
		}

		$site_key = $this->get_site_key();
		if ( empty( $site_key ) ) {
			return false;
		}

		$secret_key = $this->get_secret_key();
		if ( empty( $secret_key ) ) {
			return false;
		}

		$is_verified = $option_value === md5( $site_key . '::' . $secret_key ) ? true : false;

		return $is_verified;
	}

	/**
	 * Add turnstile script in footer.
	 *
	 * @since.1.4.3
	 */
	public function add_turnstile_script() {
?>
<script type="text/javascript">
jQuery(function($){
	$('input#site_key,input#secret_key').on('change', function() {
		$('#ayecode_turnstile_verify').hide();
	});
	$('#ayecode_turnstile_verify').on('click', function() {
		var $btn = $(this);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: $("#ayecode_turnstile_form").serialize(),
			beforeSend: function() {
				$btn.prop('disabled', true);
				$btn.data('label-default', $btn.text());
				$btn.text($btn.data('label-process'));
				$('.aye-turnstile-message').addClass('d-none').html('');
			},
			success: function(res, textStatus, xhr) {
				if (res.data) {
					$('.aye-turnstile-message').removeClass('d-none').html(res.data);
				}

				if (res.success) {
					$btn.text($btn.data('label-done'));
					$btn.removeClass('btn-info').addClass('btn-success');
					window.location.reload();
				} else {
					$btn.prop('disabled', false);
					$btn.text($btn.data('label-default'));
				}
			},
			error: function(xhr, textStatus, errorThrown) {
				$btn.prop('disabled', false);
				$btn.text($btn.data('label-default'));
				console.log(textStatus);
				$('.aye-turnstile-message').removeClass('d-none').html(textStatus);
			}
		});
	});
});
</script>
<?php
	}
}

// Initialize the class
AyeCode_Connect_Turnstile_Settings::instance();