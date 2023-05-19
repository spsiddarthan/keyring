<?php
//phpcs:ignoreFile

/**
 * Facebook service definition for Keyring. Clean implementation of OAuth2
 */

abstract class Keyring_Service_Meta extends Keyring_Service_OAuth2 {

	function __construct() {
		parent::__construct();

		// Enable "basic" UI for entering key/secret
		if ( ! KEYRING__HEADLESS_MODE ) {
			add_action( 'keyring_facebook_manage_ui', array( $this, 'basic_ui' ) );
			add_filter( 'keyring_facebook_basic_ui_intro', array( $this, 'basic_ui_intro' ) );
		}

		$this->set_endpoint( 'authorize', 'https://www.facebook.com/v6.0/dialog/oauth', 'GET' );
		$this->set_endpoint( 'access_token', 'https://graph.facebook.com/v6.0/oauth/access_token', 'GET' );
		$this->set_endpoint( 'self', 'https://graph.facebook.com/v6.0/me', 'GET' );
		$this->set_endpoint( 'profile_pic', 'https://graph.facebook.com/v6.0/me/picture/?redirect=false&width=150&height=150', 'GET' );

		$creds        = $this->get_credentials();
		$this->app_id = isset( $creds['app_id'] ) ? $creds['app_id'] : null;
		$this->key    = isset( $creds['key'] ) ? $creds['key'] : null;
		$this->secret = isset( $creds['secret'] ) ? $creds['secret'] : null;

		$admin_url          = Keyring_Util::admin_url();
		$this->redirect_uri = substr( $admin_url, 0, strpos( $admin_url, '?' ) );

		$this->requires_token( true );

		add_filter( 'keyring_' . $this->get_name() . '_request_token_params', array( $this, 'filter_request_token' ) );
		add_filter( 'keyring_' . $this->get_name() . '_verify_token_params', array( $this, 'verify_token_params' ) );
	}

	function basic_ui_intro() {
		echo '<p>' . __( "If you haven't already, you'll need to set up an app on Facebook:", 'keyring' ) . '</p>';
		echo '<ol>';
		/* translators: url */
		echo '<li>' . sprintf( __( "Click <strong>+ Create New App</strong> at the top-right of <a href='%s'>this page</a>", 'keyring' ), 'https://developers.facebook.com/apps' ) . '</li>';
		echo '<li>' . __( 'Enter a name for your app (maybe the name of your website?) and a Category, click <strong>Continue</strong> (you can skip optional things)', 'keyring' ) . '</li>';
		echo '<li>' . __( 'Enter whatever is in the CAPTCHA and click <strong>Continue</strong>', 'keyring' ) . '</li>';
		/* translators: url */
		echo '<li>' . sprintf( __( 'Click <strong>Settings</strong> on the left and then <strong>Advanced</strong> at the top of that page. Under <strong>Valid OAuth redirect URIs</strong>, enter your domain name. That value is probably <code>%s</code>', 'keyring' ), $_SERVER['HTTP_HOST'] ) . '</li>';
		/* translators: url */
		echo '<li>' . sprintf( __( 'Click the <strong>Website with Facebook Login</strong> box and enter the URL to your website, which is probably <code>%s</code>', 'keyring' ), get_bloginfo( 'url' ) ) . '</li>';
		echo '<li>' . __( 'Click <strong>Save Changes</strong>', 'keyring' ) . '</li>';
		echo '</ol>';
		echo '<p>' . __( "Once you're done configuring your app, copy and paste your <strong>App ID</strong> and <strong>App Secret</strong> (in the top section of your app's Basic details) into the appropriate fields below. Leave the App Key field blank.", 'keyring' ) . '</p>';
	}

	function is_configured() {
		$credentials = $this->get_credentials();
		return ! empty( $credentials['app_id'] ) && ! empty( $credentials['secret'] );
	}


	/**
	 * Filters the parameters used to verify the token, to make sure that we
	 * pass the strict version of the redirect_uri
	 *
	 * @param array $params The parameters that will be passed to verify the token
	 * @return array The parameters with the `redirect_uri` set correctly
	 */
	public function verify_token_params( $params ) {
		$params['redirect_uri'] = $this->redirect_uri;
		return $params;
	}

	function build_token_meta( $token ) {
		$this->set_token(
			new Keyring_Access_Token(
				$this->get_name(),
				$token['access_token'],
				array()
			)
		);
		$response = $this->request( $this->self_url, array( 'method' => $this->self_method ) );
		if ( Keyring_Util::is_error( $response ) ) {
			$meta = array();
		} else {
			$meta = array(
				'user_id' => $response->id,
				'name'    => $response->name,
				'picture' => "https://graph.facebook.com/v6.0/{$response->id}/picture?type=large",
			);
		}

		return apply_filters( 'keyring_access_token_meta', $meta, $this->get_name(), $token, $response, $this );
	}

	function get_display( Keyring_Access_Token $token ) {
		return $token->get_meta( 'name' );
	}

	function test_connection() {
		$res = $this->request( $this->self_url, array( 'method' => $this->self_method ) );
		if ( ! Keyring_Util::is_error( $res ) ) {
			return true;
		}

		return $res;
	}

	public function can_post( $id, $access_token ) {
		$fb_page = $this->request(
			add_query_arg(
				array(
					'access_token' => $access_token,
					'fields'       => 'can_post',
				),
				'https://graph.facebook.com/v14.0/' . rawurlencode( $id )
			)
		);

		// only continue with this account as a viable option if we can post content to it
		return ! empty( $fb_page->can_post );
	}

	/**
	 * Get a list of FB Pages that this user has permissions to manage
	 * @param  Keyring_Token $connection A connection to FB.
	 * @return Array containing the raw results for each page, or empty if none.
	 */
	function get_fb_pages( $connection = false ) {
		if ( $connection ) {
			$this->set_token( $connection );
		}

		$additional_external_users = array();
		$fb_accounts               = $this->request( 'https://graph.facebook.com/v14.0/me/accounts/?fields=name,id,category,access_token,picture{url},is_published' );
		if ( ! empty( $fb_accounts ) && ! is_wp_error( $fb_accounts ) ) {
			foreach ( $fb_accounts->data as $fb_account ) {
				// only continue with this account as a viable option if we can post content to it
				if ( empty( $fb_account->access_token ) || ! $fb_account->is_published || ! $this->can_post( $fb_account->id, $fb_account->access_token ) ) {
					continue;
				}

				$this_fb_page = array(
					'id'           => $fb_account->id,
					'name'         => $fb_account->name,
					'access_token' => $fb_account->access_token,
					'category'     => $fb_account->category,
					'picture'      => null,
				);

				if ( ! empty( $fb_account->picture ) && ! empty( $fb_account->picture->data ) ) {
					$this_fb_page['picture'] = esc_url_raw( $fb_account->picture->data->url );
				}

				$additional_external_users[] = (object) $this_fb_page;
			}
		}

		return $additional_external_users;
	}

	abstract public function fetch_additional_external_users();

	function fetch_profile_picture() {
		$res = $this->request( $this->profile_pic_url, array( 'method' => $this->profile_pic_method ) );
		return empty( $res->data->url ) ? null : esc_url_raw( $res->data->url );
	}
}
