<?php
/**
 * This file is part of php-saml.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package OneLogin
 * @author  Sixto Martin <sixto.martin.garcia@gmail.com>
 * @license MIT https://github.com/SAML-Toolkits/php-saml/blob/master/LICENSE
 * @link    https://github.com/SAML-Toolkits/php-saml
 */

namespace OneLogin\Saml2;

use RobRichards\XMLSecLibs\XMLSecurityKey;

use Exception;

/**
 * Main class of SAML PHP Toolkit
 */
class Auth
{
    /**
     * Settings data.
     *
     * @var Settings
     */
    private $_settings;

    /**
     * User attributes data.
     *
     * @var array
     */
    private $_attributes = array();

    /**
     * User attributes data with FriendlyName index.
     *
     * @var array
     */
    private $_attributesWithFriendlyName = array();

    /**
     * NameID
     *
     * @var string
     */
    private $_nameid;

    /**
     * NameID Format
     *
     * @var string
     */
    private $_nameidFormat;

    /**
     * NameID NameQualifier
     *
     * @var string
     */
    private $_nameidNameQualifier;

    /**
     * NameID SP NameQualifier
     *
     * @var string
     */
    private $_nameidSPNameQualifier;

    /**
     * If user is authenticated.
     *
     * @var bool
     */
    private $_authenticated = false;


    /**
     * SessionIndex. When the user is logged, this stored it
     * from the AuthnStatement of the SAML Response
     *
     * @var string
     */
    private $_sessionIndex;

    /**
     * SessionNotOnOrAfter. When the user is logged, this stored it
     * from the AuthnStatement of the SAML Response
     *
     * @var int|null
     */
    private $_sessionExpiration;

    /**
     * The ID of the last message processed
     *
     * @var string
     */
    private $_lastMessageId;

    /**
     * The ID of the last assertion processed
     *
     * @var string
     */
    private $_lastAssertionId;

    /**
     * The NotOnOrAfter value of the valid SubjectConfirmationData
     * node (if any) of the last assertion processed
     *
     * @var int
     */
    private $_lastAssertionNotOnOrAfter;

    /**
     * If any error.
     *
     * @var array
     */
    private $_errors = array();

    /**
     * Last error object.
     *
     * @var Error|null
     */
    private $_lastErrorException;

    /**
     * Last error.
     *
     * @var string|null
     */
    private $_lastError;

    /**
     * Last AuthNRequest ID or LogoutRequest ID generated by this Service Provider
     *
     * @var string
     */
    private $_lastRequestID;

    /**
     * The most recently-constructed/processed XML SAML request
     * (AuthNRequest, LogoutRequest)
     *
     * @var string
     */
    private $_lastRequest;

    /**
     * The most recently-constructed/processed XML SAML response
     * (SAMLResponse, LogoutResponse). If the SAMLResponse was
     * encrypted, by default tries to return the decrypted XML
     *
     * @var string|\DomDocument|null
     */
    private $_lastResponse;

    /**
     * Initializes the SP SAML instance.
     *
     * @param array|null $settings Setting data
     * @param bool $spValidationOnly if true, The library will only validate the SAML SP settings,
     *
     * @throws Exception
     * @throws Error
     */
    public function __construct(array $settings = null, bool $spValidationOnly = false)
    {
        $this->_settings = new Settings($settings, $spValidationOnly);
    }

    /**
     * Returns the settings info
     *
     * @return Settings The settings data.
     */
    public function getSettings()
    {
        return $this->_settings;
    }

    /**
     * Set the strict mode active/disable
     *
     * @param bool $value Strict parameter
     *
     * @throws Error
     */
    public function setStrict($value)
    {
        if (!is_bool($value)) {
            throw new Error(
                'Invalid value passed to setStrict()',
                Error::SETTINGS_INVALID_SYNTAX
            );
        }

        $this->_settings->setStrict($value);
    }

    /**
     * Set schemas path
     *
     * @param string $path
     * @return $this
     */
    public function setSchemasPath($path)
    {
        $this->_paths['schemas'] = $path;
    }

    /**
     * Process the SAML Response sent by the IdP.
     *
     * @param string|null $requestId The ID of the AuthNRequest sent by this SP to the IdP
     *
     * @throws Error
     * @throws ValidationError
     */
    public function processResponse($requestId = null)
    {
        $this->_errors = array();
        $this->_lastError = $this->_lastErrorException = null;
        if (isset($_POST['SAMLResponse'])) {
            // AuthnResponse -- HTTP_POST Binding
            $response = new Response($this->_settings, $_POST['SAMLResponse']);
            $this->_lastResponse = $response->getXMLDocument();

            if ($response->isValid($requestId)) {
                $this->_attributes = $response->getAttributes();
                $this->_attributesWithFriendlyName = $response->getAttributesWithFriendlyName();
                $this->_nameid = $response->getNameId();
                $this->_nameidFormat = $response->getNameIdFormat();
                $this->_nameidNameQualifier = $response->getNameIdNameQualifier();
                $this->_nameidSPNameQualifier = $response->getNameIdSPNameQualifier();
                $this->_authenticated = true;
                $this->_sessionIndex = $response->getSessionIndex();
                $this->_sessionExpiration = $response->getSessionNotOnOrAfter();
                $this->_lastMessageId = $response->getId();
                $this->_lastAssertionId = $response->getAssertionId();
                $this->_lastAssertionNotOnOrAfter = $response->getAssertionNotOnOrAfter();
            } else {
                $this->_errors[] = 'invalid_response';
                $this->_lastErrorException = $response->getErrorException();
                $this->_lastError = $response->getError();
            }
        } else {
            $this->_errors[] = 'invalid_binding';
            throw new Error(
                'SAML Response not found, Only supported HTTP_POST Binding',
                Error::SAML_RESPONSE_NOT_FOUND
            );
        }
    }

    /**
     * Process the SAML Logout Response / Logout Request sent by the IdP.
     *
     * @param bool        $keepLocalSession             When false will destroy the local session, otherwise will keep it
     * @param string|null $requestId                    The ID of the LogoutRequest sent by this SP to the IdP
     * @param bool        $retrieveParametersFromServer True if we want to use parameters from $_SERVER to validate the signature
     * @param callable    $cbDeleteSession              Callback to be executed to delete session
     * @param bool        $stay                         True if we want to stay (returns the url string) False to redirect
     *
     * @return string|null
     * @phpstan-return ($stay is true ? string : never)
     *
     * @throws Error
     */
    public function processSLO($keepLocalSession = false, $requestId = null, $retrieveParametersFromServer = false, $cbDeleteSession = null, $stay = false)
    {
        $this->_errors = array();
        $this->_lastError = $this->_lastErrorException = null;
        if (isset($_GET['SAMLResponse'])) {
            $logoutResponse = $this->buildLogoutResponse($this->_settings, $_GET['SAMLResponse']);
            $this->_lastResponse = $logoutResponse->getXML();
            if (!$logoutResponse->isValid($requestId, $retrieveParametersFromServer)) {
                $this->_errors[] = 'invalid_logout_response';
                $this->_lastErrorException = $logoutResponse->getErrorException();
                $this->_lastError = $logoutResponse->getError();

            } else if ($logoutResponse->getStatus() !== Constants::STATUS_SUCCESS) {
                $this->_errors[] = 'logout_not_success';
            } else {
                $this->_lastMessageId = $logoutResponse->id;
                if (!$keepLocalSession) {
                    if ($cbDeleteSession === null) {
                        Utils::deleteLocalSession();
                    } else {
                        call_user_func($cbDeleteSession);
                    }
                }
            }
        } else if (isset($_GET['SAMLRequest'])) {
            $logoutRequest = $this->buildLogoutRequest($this->_settings, $_GET['SAMLRequest']);
            $this->_lastRequest = $logoutRequest->getXML();
            if (!$logoutRequest->isValid($retrieveParametersFromServer)) {
                $this->_errors[] = 'invalid_logout_request';
                $this->_lastErrorException = $logoutRequest->getErrorException();
                $this->_lastError = $logoutRequest->getError();
            } else {
                if (!$keepLocalSession) {
                    if ($cbDeleteSession === null) {
                        Utils::deleteLocalSession();
                    } else {
                        call_user_func($cbDeleteSession);
                    }
                }
                $inResponseTo = $logoutRequest->id;
                $this->_lastMessageId = $logoutRequest->id;
                $responseBuilder = $this->buildLogoutResponse($this->_settings);
                $responseBuilder->build($inResponseTo);
                $this->_lastResponse = $responseBuilder->getXML();

                $logoutResponse = $responseBuilder->getResponse();

                $parameters = array('SAMLResponse' => $logoutResponse);
                if (isset($_GET['RelayState'])) {
                    $parameters['RelayState'] = $_GET['RelayState'];
                }

                $security = $this->_settings->getSecurityData();
                if (isset($security['logoutResponseSigned']) && $security['logoutResponseSigned']) {
                    $signature = $this->buildResponseSignature($logoutResponse, isset($parameters['RelayState'])? $parameters['RelayState']: null, $security['signatureAlgorithm']);
                    $parameters['SigAlg'] = $security['signatureAlgorithm'];
                    $parameters['Signature'] = $signature;
                }

                return $this->redirectTo($this->getSLOResponseUrl(), $parameters, $stay);
            }
        } else {
            $this->_errors[] = 'invalid_binding';
            throw new Error(
                'SAML LogoutRequest/LogoutResponse not found. Only supported HTTP_REDIRECT Binding',
                Error::SAML_LOGOUTMESSAGE_NOT_FOUND
            );
        }
    }

    /**
     * Redirects the user to the url past by parameter
     * or to the url that we defined in our SSO Request.
     *
     * @param string $url        The target URL to redirect the user.
     * @param array  $parameters Extra parameters to be passed as part of the url
     * @param bool   $stay       True if we want to stay (returns the url string) False to redirect
     *
     * @return string|null
     * @phpstan-return ($stay is true ? string : never)
     */
    public function redirectTo($url = '', array $parameters = array(), $stay = false)
    {
        assert(is_string($url));

        if (empty($url) && isset($_REQUEST['RelayState'])) {
            $url = $_REQUEST['RelayState'];
        }

        return Utils::redirect($url, $parameters, $stay);
    }

    /**
     * Checks if the user is authenticated or not.
     *
     * @return bool  True if the user is authenticated
     */
    public function isAuthenticated()
    {
        return $this->_authenticated;
    }

    /**
     * Returns the set of SAML attributes.
     *
     * @return array  Attributes of the user.
     */
    public function getAttributes()
    {
        return $this->_attributes;
    }


    /**
     * Returns the set of SAML attributes indexed by FriendlyName
     *
     * @return array  Attributes of the user.
     */
    public function getAttributesWithFriendlyName()
    {
        return $this->_attributesWithFriendlyName;
    }

    /**
     * Returns the nameID
     *
     * @return string  The nameID of the assertion
     */
    public function getNameId()
    {
        return $this->_nameid;
    }

    /**
     * Returns the nameID Format
     *
     * @return string  The nameID Format of the assertion
     */
    public function getNameIdFormat()
    {
        return $this->_nameidFormat;
    }

    /**
     * Returns the nameID NameQualifier
     *
     * @return string  The nameID NameQualifier of the assertion
     */
    public function getNameIdNameQualifier()
    {
        return $this->_nameidNameQualifier;
    }

    /**
     * Returns the nameID SP NameQualifier
     *
     * @return string  The nameID SP NameQualifier of the assertion
     */
    public function getNameIdSPNameQualifier()
    {
        return $this->_nameidSPNameQualifier;
    }

    /**
     * Returns the SessionIndex
     *
     * @return string|null  The SessionIndex of the assertion
     */
    public function getSessionIndex()
    {
        return $this->_sessionIndex;
    }

    /**
     * Returns the SessionNotOnOrAfter
     *
     * @return int|null  The SessionNotOnOrAfter of the assertion
     */
    public function getSessionExpiration()
    {
        return $this->_sessionExpiration;
    }

    /**
     * Returns if there were any error
     *
     * @return array  Errors
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Returns the reason for the last error
     *
     * @return string|null  Error reason
     */
    public function getLastErrorReason()
    {
        return $this->_lastError;
    }


    /**
     * Returns the last error
     *
     * @return Exception|null Error
     */
    public function getLastErrorException()
    {
        return $this->_lastErrorException;
    }

    /**
     * Returns the requested SAML attribute
     *
     * @param string $name The requested attribute of the user.
     *
     * @return array|null Requested SAML attribute ($name).
     */
    public function getAttribute($name)
    {
        assert(is_string($name));

        $value = null;
        if (isset($this->_attributes[$name])) {
            return $this->_attributes[$name];
        }
        return $value;
    }

    /**
     * Returns the requested SAML attribute indexed by FriendlyName
     *
     * @param string $friendlyName The requested attribute of the user.
     *
     * @return array|null Requested SAML attribute ($friendlyName).
     */
    public function getAttributeWithFriendlyName($friendlyName)
    {
        assert(is_string($friendlyName));
        $value = null;
        if (isset($this->_attributesWithFriendlyName[$friendlyName])) {
            return $this->_attributesWithFriendlyName[$friendlyName];
        }
        return $value;
    }

    /**
     * Initiates the SSO process.
     *
     * @param string|null $returnTo        The target URL the user should be returned to after login.
     * @param array       $parameters      Extra parameters to be added to the GET
     * @param bool        $forceAuthn      When true the AuthNRequest will set the ForceAuthn='true'
     * @param bool        $isPassive       When true the AuthNRequest will set the Ispassive='true'
     * @param bool        $stay            True if we want to stay (returns the url string) False to redirect
     * @param bool        $setNameIdPolicy When true the AuthNRequest will set a nameIdPolicy element
     * @param string      $nameIdValueReq  Indicates to the IdP the subject that should be authenticated
     *
     * @return string|null If $stay is True, it return a string with the SLO URL + LogoutRequest + parameters
     * @phpstan-return ($stay is true ? string : never)
     *
     * @throws Error
     */
    public function login($returnTo = null, array $parameters = array(), $forceAuthn = false, $isPassive = false, $stay = false, $setNameIdPolicy = true, $nameIdValueReq = null)
    {
        $authnRequest = $this->buildAuthnRequest($this->_settings, $forceAuthn, $isPassive, $setNameIdPolicy, $nameIdValueReq);

        $this->_lastRequest = $authnRequest->getXML();
        $this->_lastRequestID = $authnRequest->getId();

        $samlRequest = $authnRequest->getRequest();
        $parameters['SAMLRequest'] = $samlRequest;

        if (!empty($returnTo)) {
            $parameters['RelayState'] = $returnTo;
        } else {
            $parameters['RelayState'] = Utils::getSelfRoutedURLNoQuery();
        }

        $security = $this->_settings->getSecurityData();
        if (isset($security['authnRequestsSigned']) && $security['authnRequestsSigned']) {
            $signature = $this->buildRequestSignature($samlRequest, $parameters['RelayState'], $security['signatureAlgorithm']);
            $parameters['SigAlg'] = $security['signatureAlgorithm'];
            $parameters['Signature'] = $signature;
        }
        return $this->redirectTo($this->getSSOurl(), $parameters, $stay);
    }

    /**
     * Initiates the SLO process.
     *
     * @param string|null $returnTo            The target URL the user should be returned to after logout.
     * @param array       $parameters          Extra parameters to be added to the GET
     * @param string|null $nameId              The NameID that will be set in the LogoutRequest.
     * @param string|null $sessionIndex        The SessionIndex (taken from the SAML Response in the SSO process).
     * @param bool        $stay                True if we want to stay (returns the url string) False to redirect
     * @param string|null $nameIdFormat        The NameID Format will be set in the LogoutRequest.
     * @param string|null $nameIdNameQualifier The NameID NameQualifier will be set in the LogoutRequest.
     *
     * @return string|null If $stay is True, it return a string with the SLO URL + LogoutRequest + parameters
     * @phpstan-return ($stay is true ? string : never)
     *
     * @throws Error
     */
    public function logout($returnTo = null, array $parameters = array(), $nameId = null, $sessionIndex = null, $stay = false, $nameIdFormat = null, $nameIdNameQualifier = null, $nameIdSPNameQualifier = null)
    {
        $sloUrl = $this->getSLOurl();
        if (empty($sloUrl)) {
            throw new Error(
                'The IdP does not support Single Log Out',
                Error::SAML_SINGLE_LOGOUT_NOT_SUPPORTED
            );
        }

        if (empty($nameId) && !empty($this->_nameid)) {
            $nameId = $this->_nameid;
        }
        if (empty($nameIdFormat) && !empty($this->_nameidFormat)) {
            $nameIdFormat = $this->_nameidFormat;
        }

        $logoutRequest = $this->buildLogoutRequest($this->_settings, null, $nameId, $sessionIndex, $nameIdFormat, $nameIdNameQualifier, $nameIdSPNameQualifier);

        $this->_lastRequest = $logoutRequest->getXML();
        $this->_lastRequestID = $logoutRequest->id;

        $samlRequest = $logoutRequest->getRequest();

        $parameters['SAMLRequest'] = $samlRequest;
        if (!empty($returnTo)) {
            $parameters['RelayState'] = $returnTo;
        } else {
            $parameters['RelayState'] = Utils::getSelfRoutedURLNoQuery();
        }

        $security = $this->_settings->getSecurityData();
        if (isset($security['logoutRequestSigned']) && $security['logoutRequestSigned']) {
            $signature = $this->buildRequestSignature($samlRequest, $parameters['RelayState'], $security['signatureAlgorithm']);
            $parameters['SigAlg'] = $security['signatureAlgorithm'];
            $parameters['Signature'] = $signature;
        }

        return $this->redirectTo($sloUrl, $parameters, $stay);
    }

   /**
     * Gets the IdP SSO url.
     *
     * @return string The url of the IdP Single Sign On Service
     */
    public function getSSOurl()
    {
        return $this->_settings->getIdPSSOUrl();
    }

    /**
     * Gets the IdP SLO url.
     *
     * @return string|null The url of the IdP Single Logout Service
     */
    public function getSLOurl()
    {
        return $this->_settings->getIdPSLOUrl();
    }

    /**
     * Gets the IdP SLO response url.
     *
     * @return string|null The response url of the IdP Single Logout Service
     */
    public function getSLOResponseUrl()
    {
        return $this->_settings->getIdPSLOResponseUrl();
    }


    /**
     * Gets the ID of the last AuthNRequest or LogoutRequest generated by the Service Provider.
     *
     * @return string The ID of the Request SAML message.
     */
    public function getLastRequestID()
    {
        return $this->_lastRequestID;
    }

    /**
     * Creates an AuthnRequest
     *
     * @param Settings $settings        Setting data
     * @param bool     $forceAuthn      When true the AuthNRequest will set the ForceAuthn='true'
     * @param bool     $isPassive       When true the AuthNRequest will set the Ispassive='true'
     * @param bool     $setNameIdPolicy When true the AuthNRequest will set a nameIdPolicy element
     * @param string   $nameIdValueReq  Indicates to the IdP the subject that should be authenticated
     *
     * @return AuthnRequest The AuthnRequest object
     */
    public function buildAuthnRequest(Settings $settings, $forceAuthn, $isPassive, $setNameIdPolicy, $nameIdValueReq = null)
    {
        return new AuthnRequest($settings, $forceAuthn, $isPassive, $setNameIdPolicy, $nameIdValueReq);
    }

    /**
     * Creates an LogoutRequest
     *
     * @param Settings    $settings            Settings
     * @param string|null $request             A UUEncoded Logout Request.
     * @param string|null $nameId              The NameID that will be set in the LogoutRequest.
     * @param string|null $sessionIndex        The SessionIndex (taken from the SAML Response in the SSO process).
     * @param string|null $nameIdFormat        The NameID Format will be set in the LogoutRequest.
     * @param string|null $nameIdNameQualifier The NameID NameQualifier will be set in the LogoutRequest.
     * @param string|null $nameIdSPNameQualifier The NameID SP NameQualifier will be set in the LogoutRequest.
     */
    public function buildLogoutRequest(Settings $settings, $request = null, $nameId = null, $sessionIndex = null, $nameIdFormat = null, $nameIdNameQualifier = null, $nameIdSPNameQualifier = null)
    {
        return new LogoutRequest($settings, $request, $nameId, $sessionIndex, $nameIdFormat, $nameIdNameQualifier, $nameIdSPNameQualifier);
    }

    /**
     * Constructs a Logout Response object (Initialize params from settings and if provided
     * load the Logout Response.
     *
     * @param Settings    $settings Settings.
     * @param string|null $response An UUEncoded SAML Logout response from the IdP.
     *
     * @throws Error
     * @throws Exception
     */
    public function buildLogoutResponse(Settings $settings, $response = null)
    {
        return new LogoutResponse($settings, $response);
    }

    /**
     * Generates the Signature for a SAML Request
     *
     * @param string $samlRequest   The SAML Request
     * @param string $relayState    The RelayState
     * @param string $signAlgorithm Signature algorithm method
     *
     * @return string A base64 encoded signature
     *
     * @throws Exception
     * @throws Error
     */
    public function buildRequestSignature($samlRequest, $relayState, $signAlgorithm = XMLSecurityKey::RSA_SHA256)
    {
        return $this->buildMessageSignature($samlRequest, $relayState, $signAlgorithm, "SAMLRequest");
    }

    /**
     * Generates the Signature for a SAML Response
     *
     * @param string $samlResponse  The SAML Response
     * @param string $relayState    The RelayState
     * @param string $signAlgorithm Signature algorithm method
     *
     * @return string A base64 encoded signature
     *
     * @throws Exception
     * @throws Error
     */
    public function buildResponseSignature($samlResponse, $relayState, $signAlgorithm = XMLSecurityKey::RSA_SHA256)
    {
        return $this->buildMessageSignature($samlResponse, $relayState, $signAlgorithm, "SAMLResponse");
    }

    /**
     * Generates the Signature for a SAML Message
     *
     * @param string $samlMessage   The SAML Message
     * @param string $relayState    The RelayState
     * @param string $signAlgorithm Signature algorithm method
     * @param string $type          "SAMLRequest" or "SAMLResponse"
     *
     * @return string A base64 encoded signature
     *
     * @throws Exception
     * @throws Error
     */
    private function buildMessageSignature($samlMessage, $relayState, $signAlgorithm = XMLSecurityKey::RSA_SHA256, $type = "SAMLRequest")
    {
        $key = $this->_settings->getSPkey();
        if (empty($key)) {
            if ($type == "SAMLRequest") {
                $errorMsg = "Trying to sign the SAML Request but can't load the SP private key";
            } else {
                $errorMsg = "Trying to sign the SAML Response but can't load the SP private key";
            }

            throw new Error($errorMsg, Error::PRIVATE_KEY_NOT_FOUND);
        }

        $objKey = new XMLSecurityKey($signAlgorithm, array('type' => 'private'));
        $objKey->loadKey($key, false);

        $security = $this->_settings->getSecurityData();
        if ($security['lowercaseUrlencoding']) {
            $msg = $type.'='.rawurlencode($samlMessage);
            if (isset($relayState)) {
                $msg .= '&RelayState='.rawurlencode($relayState);
            }
            $msg .= '&SigAlg=' . rawurlencode($signAlgorithm);
        } else {
            $msg = $type.'='.urlencode($samlMessage);
            if (isset($relayState)) {
                $msg .= '&RelayState='.urlencode($relayState);
            }
            $msg .= '&SigAlg=' . urlencode($signAlgorithm);
        }
        $signature = $objKey->signData($msg);
        return base64_encode($signature);
    }

    /**
     * @return string The ID of the last message processed
     */
    public function getLastMessageId()
    {
        return $this->_lastMessageId;
    }

    /**
     * @return string The ID of the last assertion processed
     */
    public function getLastAssertionId()
    {
        return $this->_lastAssertionId;
    }

    /**
     * @return int The NotOnOrAfter value of the valid
     *         SubjectConfirmationData node (if any)
     *         of the last assertion processed
     */
    public function getLastAssertionNotOnOrAfter()
    {
        return $this->_lastAssertionNotOnOrAfter;
    }

    /**
     * Returns the most recently-constructed/processed
     * XML SAML request (AuthNRequest, LogoutRequest)
     *
     * @return string|null The Request XML
     */
    public function getLastRequestXML()
    {
        return $this->_lastRequest;
    }

    /**
     * Returns the most recently-constructed/processed
     * XML SAML response (SAMLResponse, LogoutResponse).
     * If the SAMLResponse was encrypted, by default tries
     * to return the decrypted XML.
     *
     * @return string|null The Response XML
     */
    public function getLastResponseXML()
    {
        $response = null;
        if (isset($this->_lastResponse)) {
            if (is_string($this->_lastResponse)) {
                $response = $this->_lastResponse;
            } else {
                $response = $this->_lastResponse->saveXML();
            }
        }

        return $response;
    }
}
