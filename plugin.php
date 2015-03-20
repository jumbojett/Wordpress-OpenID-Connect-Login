<?php
/*
Plugin Name: OpenID Connect Login
Plugin URI:
Description: This plugin re-writes the login to utilize an openid connect server
Version: 1.0
Author: Michael Jett
Author URI:
Author Email: mjett@mitre.org
License:

* Licensed under the Apache License, Version 2.0 (the "License"); you may
* not use this file except in compliance with the License. You may obtain
* a copy of the License at
*
*      http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
* WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
* License for the specific language governing permissions and limitations
* under the License.
  
*/

if (!class_exists('OpenIDConnectLoginPlugin')):

    require('lib/helper/MY_Plugin.php');
    require('lib/auth/OpenIDConnectClient.php5');

    class WP_OpenIDConnectClient extends OpenIDConnectClient {

        public function __construct() {

            // Setup a proxy if defined in wp-config.php
            if (defined('WP_PROXY_HOST')) {
                $proxy = WP_PROXY_HOST;

                if (defined('WP_PROXY_PORT')) {
                    $proxy = rtrim($proxy, '/') . ':' . WP_PROXY_PORT . '/';
                }

                $this->setHttpProxy($proxy);
            }

            parent::__construct();
        }

    }

    class OpenIDConnectLoginPlugin extends MY_Plugin {

        /**
         * Initializes the plugin by setting localization, filters, and administration functions.
         */
        function __construct() {

            // Call the parent constructor
            parent::__construct(dirname(__FILE__));

            /**
             * Register settings options
             */
            add_action('admin_init', array($this, 'register_plugin_settings_api_init'));
            add_action('admin_menu', array($this, 'register_plugin_admin_add_page'));

            /**
             * Custom Login page
             */
            add_action('login_form', array($this, 'add_button_to_login'));

            /**
             * Add a plugin settings link on the plugins page
             */
            $plugin = plugin_basename(__FILE__);
            add_filter("plugin_action_links_$plugin", function ($links) {
                $settings_link = '<a href="options-general.php?page=openid-connect">Settings</a>';
                array_unshift($links, $settings_link);
                return $links;
            });

            /**
             * Build a new endpoint
             * process requests with "openid-connect=endpoint"
             */
            add_filter('query_vars', function ($vars) {
                $vars[] = 'openid-connect';
                return $vars;
            });
            $self = $this;
            add_action('parse_request', function ($wp) use ($self) {
                if (array_key_exists('openid-connect', $wp->query_vars)
                    && isset($wp->query_vars['openid-connect'])
                ) {
                    $provider_url = trim(urldecode($wp->query_vars['openid-connect']));
                    $self->authenticate($provider_url);

                }
            });


        } // end constructor

        /**
         *
         */
        public function add_button_to_login() {
            $this->load_view('button', null, true);
        }

        /**
         * @param $input
         * @return mixed
         */
        public function validate_options($input) {

            $input['openid_providers'] = str_replace(' ', '', $input['openid_providers']);

            $provider_arr = explode("\n", trim(str_replace("\r", '', $input['openid_providers'])));

            $return = $input;
            $return['openid_providers'] = '';
            $return['openid_provider_hash'] = $this->options['openid_provider_hash'];

            // Provider details are stored to a setting that is not displayed
            if (!is_array($return['openid_provider_hash'])) {
                $return['openid_provider_hash'] = array();
            }

            // Make sure each provider has a valid URL
            foreach ($provider_arr as $provider_url) {

                if (filter_var($provider_url, FILTER_VALIDATE_URL) === false || $provider_url == '') {
                    continue;
                }

                // Register with the provider if they don't exist
                if (!array_key_exists($provider_url, $return['openid_provider_hash'])) {

                    $oidc = new WP_OpenIDConnectClient();
                    $oidc->setProviderURL($provider_url);
                    $oidc->setRedirectURL(get_site_url(null, '', 'https') . '/?openid-connect=' . urlencode($provider_url));
                    $oidc->setClientName("(Wordpress Instance) " . get_bloginfo());

                    try {
                        $oidc->register();

                        $return['openid_provider_hash'][$provider_url] = (object)array(
                            'client_id' => $oidc->getClientID(),
                            'client_secret' => $oidc->getClientSecret()
                        );

                        $return['openid_providers'] .= "$provider_url\n";

                    } catch (OpenIDConnectClientException $e) {
                    }

                } else {
                    $return['openid_providers'] .= "$provider_url\n";
                }


            }

            // Remove deleted providers
            foreach ($return['openid_provider_hash'] as $provider_url => $value) {
                if (!in_array($provider_url, $provider_arr)) {
                    unset($return['openid_provider_hash'][$provider_url]);
                }
            }

            return $return;

        }


        /**
         *
         */
        public function authenticate($provider_url) {

            $providers = $this->options['openid_provider_hash'];

            if (!array_key_exists($provider_url, $providers)) {
                wp_die("We could not authenticate against that provider. They are not approved.");
            }

            $oidc = new WP_OpenIDConnectClient();

            $oidc->setProviderURL($provider_url);
            $oidc->setClientID($providers[$provider_url]->client_id);
            $oidc->setClientSecret($providers[$provider_url]->client_secret);
            $oidc->setRedirectURL(get_site_url(null, '', 'https') . '/?openid-connect=' . urlencode($provider_url));

            $oidc->addScope('openid');
            $oidc->addScope('email');
            $oidc->addScope('profile');

            try {
                $oidc->authenticate();
                self::login_oidc_user($oidc);

            } catch (Exception $e) {
                wp_die($e->getMessage());
            }

            return null;

        }

        /**
         * @param $oidc WP_OpenIDConnectClient
         * @throws OpenIDConnectClientException
         */
        private function login_oidc_user($oidc) {
            
            // User ID on issuer is always unique
            $unique_id = $oidc->requestUserInfo('sub') . '@' . $oidc->getProviderURL();

            $user_name = substr(sanitize_user($unique_id, TRUE), 0, 60);

            if (!function_exists('get_user_by')) {
                die("Could not load user data");
            }

            // Login the user by email if it's verified, otherwise login via meta
            if ($oidc->requestUserInfo('email_verified') == true) {
                $user = get_user_by('email', $oidc->requestUserInfo('email'));
            } else {
                list($user) = get_users(array('meta_key' => '_openid_connect', 'meta_value' => $unique_id));
            }

            $wp_uid = null;

            if ($user) {
                // User already exists
                $wp_uid = $user->ID;
            } else {

                // First time logging in
                // User is not in the WordPress database
                // Add them to the database

                $email = $oidc->requestUserInfo('email');

                // If the user's email isn't verified on the provider then we discard it when creating a new user
                if ($oidc->requestUserInfo('email_verified') != true) $email = '';

                $wp_uid = wp_insert_user(array(
                    'user_login' => $user_name,
                    'user_pass' => wp_generate_password(12, true),
                    'user_email' => $email,
                    'first_name' => $oidc->requestUserInfo('given_name'),
                    'last_name' => $oidc->requestUserInfo('family_name')
                ));

                if (get_class($wp_uid) == 'WP_Error') {
                    wp_die("We're having some trouble creating a local account for you on this instance. Contact your wordpress admin.");
                }

                // Add meta to identify this user in the future
                add_user_meta($wp_uid, '_openid_connect', $unique_id, true);

            }

            $user = wp_set_current_user($wp_uid, $user_name);
            wp_set_auth_cookie($wp_uid);
            do_action('wp_login', $user_name);

            // Redirect the user
            wp_safe_redirect(admin_url());
            exit();

        }

        /**
         *
         */
        public function register_plugin_settings_api_init() {

            register_setting($this->get_option_name(), $this->get_option_name(), array($this, 'validate_options'));

            add_settings_section('openid_connect_client', 'Main Settings', function () {
                echo "<p>These settings are required for the plugin to work properly.
                If you are behind a proxy, make sure <i>WP_PROXY_HOST</i> and <i>WP_PROXY_PORT</i> are defined in wp-config
                </p>";
            }, 'openid-connect');

            // Add a Server URL setting
            $this->add_settings_field('openid_providers', 'openid-connect', 'openid_connect_client', 'textarea');

        }

        /**
         *
         */
        public function register_plugin_admin_add_page() {

            $self = $this;
            add_options_page('OpenID Connect Login Page', 'OpenID Connect', 'manage_options', 'openid-connect', function () use ($self) {
                $self->load_view('settings', null);
            });
        }


    } // end class

// Init plugin
    $openid_connect_plugin = new OpenIDConnectLoginPlugin();

endif;
