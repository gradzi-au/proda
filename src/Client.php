<?php

namespace GradziAu\Proda;

use Carbon\Carbon;
use GradziAu\Proda\Exceptions\AccessTokenDeviceErrorException;
use GradziAu\Proda\Exceptions\AccessTokenMappingErrorException;
use GradziAu\Proda\Exceptions\DeviceInInvalidStateException;
use GradziAu\Proda\Exceptions\DeviceNotFoundException;
use GradziAu\Proda\Exceptions\InvalidActivationCodeException;
use GradziAu\Proda\Exceptions\JwkInvalidAlgorithmException;
use GradziAu\Proda\Exceptions\JwkInvalidKeyUseException;
use GradziAu\Proda\Exceptions\JwkKeyInHistoryException;
use GradziAu\Proda\Exceptions\JwkParseException;
use GradziAu\Proda\Exceptions\OrganisationNotActiveException;
use GradziAu\Proda\Exceptions\OrganisationNotFoundException;
use GradziAu\Proda\Exceptions\ProdaAccessTokenException;
use GradziAu\Proda\Exceptions\ProdaDeviceActivationException;
use GradziAu\Proda\Exceptions\ProdaInputValidationErrorException;
use Illuminate\Support\Str;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Zttp\Zttp;
use Zttp\ZttpResponse;

class Client
{

    const API_VERSION = 'v1';

    const JWK_GRANT_TYPE = 'urn:ietf:params:oauth:grant_type:jwt_bearer';

    const JSON_WEB_TOKEN_EXPIRY_TIME_IN_SECONDS = 3600;

    public $deviceName;

    public $publicKeyModulus;

    public $clientId;

    public $organisationId;

    public $oneTimeActivationCode;

    public $accessToken;

    public $jsonWebKey;

    public $privateKey;

    public $algorithm;

    /**
     * An array of valid Device Activation Exceptions, keyed to the device error codes from PRODA
     *
     * @var array
     */
    protected $deviceActivationExceptions = [
        'DE.2' => OrganisationNotFoundException::class,
        'DE.4' => DeviceNotFoundException::class,
        'DE.5' => DeviceInInvalidStateException::class,
        'DE.7' => InvalidActivationCodeException::class,
        'DE.9' => OrganisationNotActiveException::class,
        'JWK.1' => JwkParseException::class,
        'JWK.2' => JwkInvalidAlgorithmException::class,
        'JWK.8' => JwkInvalidKeyUseException::class,
        'JWK.9' => JwkKeyInHistoryException::class,
        '111' => ProdaInputValidationErrorException::class,
    ];

    /**
     * An array of valid Access Token exceptions, keyed to the access token error codes from PRODA
     *
     * @var array
     */
    protected $accessTokenExceptions = [
        'mapping_error' => AccessTokenMappingErrorException::class,
        'device_error' => AccessTokenDeviceErrorException::class,
    ];

    /**
     * @param $deviceName
     * @return $this
     */
    public function forDeviceName($deviceName)
    {
        $this->deviceName = $deviceName;
        return $this;
    }

    /**
     * @param $publicKeyModulus
     * @return $this
     */
    public function usingPublicKeyModulus($publicKeyModulus)
    {
        $this->publicKeyModulus = $publicKeyModulus;
        return $this;
    }

    /**
     * @param $clientId
     * @return $this
     */
    public function withClientId($clientId)
    {
        $this->clientId = $clientId;
        return $this;
    }

    /**
     * @param $organisationId
     * @return $this
     */
    public function withOrganisationId($organisationId)
    {
        $this->organisationId = $organisationId;
        return $this;
    }

    /**
     * @param $oneTimeActivationCode
     * @return $this
     */
    public function withOneTimeActivationCode($oneTimeActivationCode)
    {
        $this->oneTimeActivationCode = $oneTimeActivationCode;
        return $this;
    }

    /**
     * @param $accessToken
     * @return $this
     */
    public function usingAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * @param $jsonWebKey
     * @return $this
     */
    public function usingJsonWebKey($jsonWebKey)
    {
        $this->jsonWebKey = $jsonWebKey;
        return $this;
    }

    /**
     * @param $privateKey
     * @return $this
     */
    public function usingPrivateKey($privateKey)
    {
        $this->privateKey = $privateKey;
        return $this;
    }

    /**
     * @param $algorithm
     * @return $this
     */
    public function withAlgorithm($algorithm)
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    /**
     * @return array
     * @throws ProdaDeviceActivationException
     */
    public function activateDevice()
    {
        $response = Zttp::withHeaders($this->getDeviceActivationHeaders())
            ->put($this->getActivateDeviceUrl(), $this->getDeviceActivationBody());

        return $this->handleDeviceActivationResponse($response);
    }

    /**
     * @return array
     * @throws ProdaDeviceActivationException
     */
    public function refreshDevice()
    {
        $response = Zttp::withHeaders($this->getDeviceActivationHeaders())
            ->put($this->getRefreshDeviceUrl(), $this->jsonWebKey);

        return $this->handleDeviceActivationResponse($response);
    }

    /**
     * @return array
     */
    protected function getDeviceActivationHeaders()
    {
        $headers = [
            'Accept-Encoding' => 'gzip,deflate',
            'Content-Type' => 'application/json',
            'dhs-auditId' => $this->organisationId,
            'dhs-auditIdType' => 'http://humanservices.gov.au/PRODA/org',
            'dhs-subjectId' => $this->deviceName,
            'dhs-subjectIdType' => 'http://humanservices.gov.au/PRODA/org',
            'dhs-productId' => $this->clientId,
            'dhs-messageId' => 'urn:uuid:' . (string)Str::uuid(),
            'dhs-correlationId' => 'urn:uuid:' . (string)Str::uuid(),
        ];

        if ($this->accessToken) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        return $headers;
    }

    /**
     * @return string
     */
    protected function getActivateDeviceUrl()
    {
        return sprintf(config('proda.urls.activate_device'),
            static::API_VERSION, $this->deviceName);
    }

    /**
     * @return string
     */
    protected function getRefreshDeviceUrl()
    {
        return sprintf(config('proda.urls.refresh_device_key'),
            static::API_VERSION, $this->organisationId, $this->deviceName);
    }

    /**
     * @return array
     */
    protected function getDeviceActivationBody()
    {
        return [
            'orgId' => $this->organisationId,
            'otac' => $this->oneTimeActivationCode,
            'key' => $this->jsonWebKey,
        ];
    }

    /**
     * @param ZttpResponse $response
     * @return array
     * @throws ProdaDeviceActivationException
     */
    protected function handleDeviceActivationResponse(ZttpResponse $response)
    {
        $responseData = $response->json();

        if (!$response->isOk()) {
            $this->handleDeviceActivationError($responseData);
        }

        return $responseData;
    }

    /**
     * @param array $responseData
     * @throws ProdaDeviceActivationException
     */
    protected function handleDeviceActivationError(array $responseData)
    {
        if ($this->responseHasValidDeviceActivationError($responseData)) {
            $errorCode = $responseData['errors']['code'];
            throw new $this->deviceActivationExceptions[$errorCode]($responseData);
        }

        throw new ProdaDeviceActivationException($responseData);
    }

    /**
     * @param array $responseData
     * @return bool
     */
    protected function responseHasValidDeviceActivationError(array $responseData)
    {
        return (is_array($responseData) &&
            array_key_exists('errors', $responseData) &&
            (count($responseData['errors']) > 0) &&
            array_key_exists($responseData['errors']['code'], $this->deviceActivationExceptions));
    }

    /**
     * @return array
     * @throws ProdaAccessTokenException
     */
    public function getAccessToken()
    {
        $response = Zttp::asFormParams()
            ->post($this->getAuthorisationServiceRequestUrl(), $this->getAccessTokenPostParameters());

        return $this->handleAccessTokenResponse($response);
    }

    /**
     * @return string
     */
    protected function getAuthorisationServiceRequestUrl()
    {
        return config('proda.urls.authorisation_service_request');
    }

    /**
     * @return array
     */
    protected function getAccessTokenPostParameters()
    {
        return [
            'grant_type' => static::JWK_GRANT_TYPE,
            'assertion' => $this->getJsonWebToken(),
            'client_id' => $this->clientId,
        ];
    }

    /**
     * Creates and returns a JSON Web Token
     *
     * Claims:
     *  iss         = organisation id (issuedBy)
     *  sub         = device name (relatedTo)
     *  aud         = 'https://proda.humanservices.gov.au' (permittedFor)
     *  token.aud   = relaying party's audience string (e.g. TCSI?)
     *  iat         = issued at timestamp
     *  exp         = expiry timestamp (i.e. issued at + 3600 seconds)
     *
     * Headers:
     *  alg         = 'RS256' or 'RS384' or 'RS512'
     *  kid         = device name
     *
     * @return string
     */
    protected function getJsonWebToken()
    {
        $time = Carbon::now()->timestamp;
        $signer = new Sha256();
        $privateKey = new Key($this->privateKey);

        $token = (new Builder)->issuedBy($this->organisationId)
            ->relatedTo($this->deviceName)
            ->permittedFor('https://proda.humanservices.gov.au')
            ->withClaim('token.aud', 'tcsi.test.audience.string')
            ->issuedAt($time)
            ->expiresAt($time + static::JSON_WEB_TOKEN_EXPIRY_TIME_IN_SECONDS)
            ->withHeader('alg', $this->algorithm)
            ->withHeader('kid', $this->deviceName)
            ->getToken($signer, $privateKey);

        return (string)$token;
    }

    /**
     * @param ZttpResponse $response
     * @return array
     * @throws ProdaAccessTokenException
     */
    protected function handleAccessTokenResponse(ZttpResponse $response)
    {
        $responseData = $response->json();

        if (!$response->isOk()) {
            $this->handleAccessTokenError($responseData);
        }

        return $responseData;
    }

    /**
     * @param array $responseData
     * @throws ProdaAccessTokenException
     */
    protected function handleAccessTokenError(array $responseData)
    {
        if ($this->responseHasValidAccessTokenError($responseData)) {
            $errorCode = $responseData['error'];
            throw new $this->accessTokenExceptions[$errorCode]($responseData);
        }

        throw new ProdaAccessTokenException($responseData);
    }

    /**
     * @param array $responseData
     * @return bool
     */
    protected function responseHasValidAccessTokenError(array $responseData)
    {
        return (is_array($responseData) &&
            array_key_exists('error', $responseData) &&
            array_key_exists($responseData['error'], $this->accessTokenExceptions));
    }

}