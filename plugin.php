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
        /**
         * @return string
         */
        public function getRedirectURL() {
            return get_site_url() . '/?openid-connect=endpoint';
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
                    && $wp->query_vars['openid-connect'] == 'endpoint'
                ) {
                    $self->authenticate();
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
         *
         */
        public function authenticate() {

            if (!$this->get_option('openid_client_id')
                || !$this->get_option('openid_client_secret')
                || !$this->get_option('openid_server_url')
            ) {
                wp_die("OpenID Connect plugin is not configured");
            }

            $oidc = new WP_OpenIDConnectClient($this->get_option('openid_server_url')
                , $this->get_option('openid_client_id')
                , $this->get_option('openid_client_secret'));

            // Setup a proxy if defined in wp-config.php
            if (defined('WP_PROXY_HOST')) {
                $proxy = WP_PROXY_HOST;

                if (defined('WP_PROXY_PORT')) {
                    $proxy = rtrim($proxy, '/') . ':' . WP_PROXY_PORT . '/';
                }

                $oidc->setHttpProxy($proxy);
            }

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

            /*
                * Only allow usernames that are not affected by sanitize_user(), and that are not
                * longer than 60 characters (which is the 'user_login' database field length).
                * Otherwise an account would be created but with a sanitized username, which might
                * clash with an already existing account.
                * See sanitize_user() in wp-includes/formatting.php.
                *
            */
            $username = $oidc->requestUserInfo('preferred_username');

            if ($username != substr(sanitize_user($username, TRUE), 0, 60)) {
                $error = sprintf(__('<p><strong>ERROR</strong><br /><br />
				We got back the following identifier from the login process:<pre>%s</pre>
				Unfortunately that is not suitable as a username.<br />
				Please contact the <a href="mailto:%s">blog administrator</a> and ask to reconfigure the
				OpenID connect plugin!</p>'), $username, get_option('admin_email'));
                $errors['registerfail'] = $error;
                print($error);
                exit();
            }

            if (!function_exists('get_user_by')) {
                die("Could not load user data");
            }

            if ($oidc->requestUserInfo('email_verified') != true) {
                throw new OpenIDConnectClientException("Your email address has not been verified with your provider.");
            }

            $user = get_user_by('email', $oidc->requestUserInfo('email'));
            $wp_uid = null;

            if ($user) {
                // user already exists
                $wp_uid = $user->ID;
            } else {

                // First time logging in
                // User is not in the WordPress database
                // Add them to the database

                // User must have an e-mail address to register

                $wp_uid = wp_insert_user(array(
                    'user_login' => $username,
                    'user_pass' => wp_generate_password(12, true),
                    'user_email' => $oidc->requestUserInfo('email'),
                    'first_name' => $oidc->requestUserInfo('given_name'),
                    'last_name' => $oidc->requestUserInfo('family_name')
                ));


            }

            $user = wp_set_current_user($wp_uid, $username);
            wp_set_auth_cookie($wp_uid);
            do_action('wp_login', $username);

            // Redirect the user
            wp_safe_redirect(admin_url());
            exit();

        }

        /**
         *
         */
        public function register_plugin_settings_api_init() {

            register_setting($this->get_option_name(), $this->get_option_name());

            add_settings_section('openid_connect_client', 'Main Settings', function () {
                echo "<p>These settings are required for the plugin to work properly.
                If you are behind a proxy, make sure <i>WP_PROXY_HOST</i> and <i>WP_PROXY_PORT</i> are defined in wp-config
                </p>";
            }, 'openid-connect');

            // Add a Server URL setting
            $this->add_settings_field('openid_server_url', 'openid-connect', 'openid_connect_client');
            // Add a Client ID setting
            $this->add_settings_field('openid_client_id', 'openid-connect', 'openid_connect_client');
            // Add a Client Secret setting
            $this->add_settings_field('openid_client_secret', 'openid-connect', 'openid_connect_client');


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
$plugin_name = new OpenIDConnectLoginPlugin();

endif;