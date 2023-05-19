<?php
//phpcs:ignoreFile
/**
 * Facebook service definition for Keyring. Clean implementation of OAuth2
 */

class Keyring_Service_Instagram_Business extends Keyring_Service_Meta {
	const NAME  = 'instagram-business';
	const LABEL = 'Instagram Business';

	function __construct() {
		parent::__construct();
	}

	function _get_credentials() {
		if (
			defined( 'KEYRING__INSTAGRAM_BUSINESS_ID' )
		&&
			defined( 'KEYRING__INSTAGRAM_BUSINESS_SECRET' )
		) {
			return array(
				'app_id' => constant( 'KEYRING__INSTAGRAM_BUSINESS_ID' ),
				'key'    => constant( 'KEYRING__INSTAGRAM_BUSINESS_ID' ),
				'secret' => constant( 'KEYRING__INSTAGRAM_BUSINESS_SECRET' ),
			);
		}

		// Return null to allow fall-thru to checking generic constants + DB
		return null;

	}

	/**
	 * Add scope to the outbound URL, and allow developers to modify it
	 * @param  array $params Core request parameters
	 * @return Array containing originals, plus the scope parameter
	 */
	function filter_request_token( $params ) {
		$scope = implode( ',', apply_filters( 'keyring_instagram_business_scope', array() ) );
		if ( $scope ) {
			$params['scope'] = $scope;
		}

		$url_components = parse_url( $params['redirect_uri'] );
		parse_str( $url_components['query'], $redirect_state );
		$redirect_state['state'] = $params['state'];
		$params['state']         = Keyring_Util::get_hashed_parameters( $redirect_state );
		$params['redirect_uri']  = $this->redirect_uri;

		return $params;
	}

	/**
	 * Get a list of FB Pages that this user has permissions to manage
	 * @param  Keyring_Token $connection A connection to FB.
	 * @return Array containing the raw results for each page, or empty if none.
	 */
	function get_instagram_pages( $connection = false ) {
		if ( $connection ) {
			$this->set_token( $connection );
		}

		$additional_external_users = array();
		$fb_accounts               = $this->request( 'https://graph.facebook.com/v14.0/me/accounts/?fields=category,access_token,instagram_business_account{id,name,username,profile_picture_url}' );
		if ( ! empty( $fb_accounts ) && ! is_wp_error( $fb_accounts ) ) {
			foreach ( $fb_accounts->data as $fb_account ) {
				// only continue with this account as a viable option if we can post content to it
				if ( empty( $fb_account->access_token ) || ! isset( $fb_account->instagram_business_account ) ) {
					continue;
				}
				$ig_account   = $fb_account->instagram_business_account;
				$this_ig_page = array(
					'id'           => $ig_account->id,
					'name'         => $ig_account->username,
					'access_token' => $fb_account->access_token,
					'category'     => $fb_account->category,
					'picture'      => $ig_account->profile_picture_url,
				);

				$additional_external_users[] = (object) $this_ig_page;
			}
		}

		return $additional_external_users;
	}

	function fetch_additional_external_users() {
		return $this->get_instagram_pages();
	}
}

add_action( 'keyring_load_services', array( 'Keyring_Service_Instagram_Business', 'init' ) );
