<?php
/**
 * SpecialOAuth2Client.php
 * Based on TwitterLogin by David Raison, which is based on the guideline published by Dave Challis at http://blogs.ecs.soton.ac.uk/webteam/2010/04/13/254/
 * @license: LGPL (GNU Lesser General Public License) http://www.gnu.org/licenses/lgpl.html
 *
 * @file SpecialOAuth2Client.php
 * @ingroup OAuth2Client
 *
 * @author Joost de Keijzer
 *
 * Uses the OAuth2 library https://github.com/vznet/oauth_2.0_client_php
 *
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );
}

spl_autoload_register(function ($class) {
	if( substr( $class, 0, 7 ) == 'OAuth2\\' ) {
		include __DIR__ . DIRECTORY_SEPARATOR . 'vendors' . DIRECTORY_SEPARATOR . 'oauth_2' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
	}
});

class SpecialOAuth2Client extends SpecialPage {

	private $_clientId;
	private $_clientSecret;
	private $_clientCallbackUrl;

	private $_serviceAuthorizeEndpointUrl;
	private $_serviceAccessTokenEndpointUrl;
	private $_serviceApiEndpointUrl;

	private $_euTable = 'external_user';

	private $_oAuth2Service;

	/**
	 * Required settings in global $wgOAuth2Client
	 *
	 * $wgOAuth2Client['client']['id']
	 * $wgOAuth2Client['client']['secret']
	 * //$wgOAuth2Client['client']['callback_url'] // extension should know
	 *
	 * $wgOAuth2Client['configuration']['authorize_endpoint']
	 * $wgOAuth2Client['configuration']['access_token_endpoint']
	 * $wgOAuth2Client['configuration']['http_bearer_token']
	 * $wgOAuth2Client['configuration']['query_parameter_token']
	 * $wgOAuth2Client['configuration']['api_endpoint']
	 */
	public function __construct() {
		parent::__construct('OAuth2Client'); // ???: wat doet dit?
		global $wgOAuth2Client, $wgScriptPath;

		$client = new OAuth2\Client(
			$wgOAuth2Client['client']['id'],
			$wgOAuth2Client['client']['secret'],
			SpecialPage::getTitleFor( 'OAuth2Client', 'callback' )->getFullURL()
		);

		// configuration of service
		$configuration = new OAuth2\Service\Configuration(
			$wgOAuth2Client['configuration']['authorize_endpoint'],
			$wgOAuth2Client['configuration']['access_token_endpoint'],
			$wgOAuth2Client['configuration']['http_bearer_token'],
			$wgOAuth2Client['configuration']['query_parameter_token']
		);
		//$configuration->setAuthorizationMethod( OAuth2\Service\Configuration::AUTHORIZATION_METHOD_ALTERNATIVE);

		// storage class for access token, just implement OAuth2\DataStore interface for
		// your own implementation
		$dataStore = new OAuth2\DataStore\Session();

		$scope = null;

		$this->_oAuth2Service = new OAuth2\Service($client, $configuration, $dataStore, $scope);
	}

	// default method being called by a specialpage
	public function execute( $parameter ){
		$this->setHeaders();
		switch($parameter){
			case 'redirect':
				$this->_redirect();
			break;
			case 'callback':
				$this->_handleCallback();
			break;
			default:
				$this->_default();
			break;
		}
		
	}

	private function _redirect() {
		$this->_oAuth2Service->authorize();
	}

	private function _handleCallback(){
		global $wgOAuth2Client, $wgOut;
		if( $this->_oAuth2Service->getAccessToken() ) {
			$requestApiResponse = $this->_oAuth2Service->callApiEndpoint($wgOAuth2Client['configuration']['api_endpoint']);
		} else {
			throw new MWException('Invalid callback');
		}

		$user = $this->_userHandling( $requestApiResponse );
		$user->setCookies();

		if( $user->getRegistration() > wfTimestamp( TS_MW ) - 1 ) {
			// new user!
			$wgOut->redirect(SpecialPage::getTitleFor('Preferences')->getLinkUrl());
		} else {
			$wgOut->redirect('/');
		}
		return true;
	}

	private function _default(){
		global $wgOut, $wgUser, $wgScriptPath, $wgExtensionAssetsPath;

		$wgOut->setPagetitle("OAuth2 Login");

		if ( !$wgUser->isLoggedIn() ) {
			$wgOut->addWikiMsg( 'oauth2client-you-can-register-to-this-wiki-using-oauth2' );

			$wgOut->addHTML( '<a href="' . $this->getTitle( 'redirect' )->getFullURL() .'">'
				.wfMsg( 'oauth2client-login-with-oauth2' ).'</a>' );
		} else {
			$wgOut->addWikiMsg( 'oauth2client-youre-already-loggedin' );
		}
		return true;
	}

	protected function _userHandling( $response ) {
		global $wgOAuth2Client, $wgAuth;

		// TODO: make id, name etc. parameters configurable
		$oAuth2Id = $response['id'];
		$oAuth2Name = $response['full_name'];

		// not required
		$oAuth2Email = ( isset( $response['email'] ) ? $response['email'] : '' );


		$externalId = 'OAuth2Client.' . $wgOAuth2Client['client']['id'] . '.' . $oAuth2Id;

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			'external_user',
			'*',
			array( 'eu_external_id' => $externalId )
		);
		if( $row ) {
			// existing OAuth2 user
			return User::newFromId( $row->eu_local_id );
		}
		// create user based on $oAuth2Name
		$counter = 1;
		$success = false; 
		while( !$success && $counter <= 1000 ) {
			$checkName = $oAuth2Name . ( $counter > 1 ? ' ' . $counter : '' );
			$user = User::newFromName( $checkName, 'creatable' );
			$counter ++;
			$success = ($user->getId() == 0);
		}
		if( $user->getId() != 0 ) {
			throw new MWException('Unable to create new user account, please contact the Wiki administrator');
		}
		$user->setRealName($oAuth2Name);
		if( strlen($oAuth2Email) > 0 ) {
			$user->setEmail($oAuth2Email);
			$user->setEmailAuthenticationTimestamp(time()); // ???: should we auto-authenticate e-mail?
		}
		if ( $wgAuth->allowPasswordChange() ) {
			$user->setPassword(User::randomPassword());
		}
		$user->addToDatabase();

		// link local user to remote OAuth2
		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace( 'external_user',
			array( 'eu_local_id', 'eu_external_id' ),
			array( 'eu_local_id' => $user->getId(),
				   'eu_external_id' => $externalId ),
			__METHOD__ );

		return $user;
	}

}
