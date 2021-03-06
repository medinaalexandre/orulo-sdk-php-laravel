<?php

namespace Jetimob\Orulo;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Jetimob\Orulo\Exception\ConfigurationException;
use Jetimob\Orulo\Exception\EmptyResponseClassException;
use Jetimob\Orulo\Exception\OruloException;
use Jetimob\Orulo\Lib\Http\Api\ErrorResponse;
use Jetimob\Orulo\Lib\Http\Auth\AuthType;
use Jetimob\Orulo\Lib\Http\Auth\TokenRequest;
use Jetimob\Orulo\Lib\Http\Auth\TokenResponse;
use Jetimob\Orulo\Lib\Http\Exception\AuthorizationException;
use Jetimob\Orulo\Lib\Http\Exception\MissingCodeException;
use Jetimob\Orulo\Lib\Http\Exception\RequiresEndUserInteraction;
use Jetimob\Orulo\Lib\Http\Exception\WrongAuthTypeException;
use Jetimob\Orulo\Lib\Http\Exception\WrongRequestTypeException;
use Jetimob\Orulo\Lib\Http\Exception\WrongResponseTypeException;
use Jetimob\Orulo\Lib\Http\Request;
use Jetimob\Orulo\Lib\Http\Response;
use Jetimob\Orulo\Util\Log;

class Orulo
{
    private const CONFIG_FILE_REQUIRED_KEYS = [
        'client_id',
        'client_secret',
    ];

    private array $config;

    private array $guzzleOptions;

    private ?TokenResponse $authorizationToken = null;

    private Client $apiClient;

    /**
     * Orulo constructor.
     * @param array $config
     * @throws ConfigurationException
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->guzzleOptions = $this->getConfig('guzzle', []);

        foreach (self::CONFIG_FILE_REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $config) || empty($config[$key])) {
                throw new ConfigurationException(
                    sprintf(
                        <<<MSG
Missing required keys in orulo's configuration file.
Please make sure to have all [%s] these keys in orulo.php file
MSG,
                        implode(', ', self::CONFIG_FILE_REQUIRED_KEYS),
                    ),
                );
            }
        }

        $this->apiClient = new Client($this->guzzleOptions);
        Log::$enabled = $this->getConfig('logging', false);
    }

    /**
     * @param Request|string $request
     * @param string $clientId
     * @param string $clientSecret
     * @return Response
     * @throws AuthorizationException
     * @throws EmptyResponseClassException
     * @throws Lib\Http\Exception\MissingPropertyBodySchemaException
     * @throws MissingCodeException
     * @throws OruloException
     * @throws RequiresEndUserInteraction
     * @throws WrongAuthTypeException
     * @throws WrongRequestTypeException
     * @throws WrongResponseTypeException
     */
    public function request($request, string $clientId, string $clientSecret)
    {
        if (is_string($request)) {
            $request = new $request();
        }

        if (is_null($request) || !($request instanceof Request)) {
            throw new WrongRequestTypeException();
        }

        $oruloResponse = null;
        $builtBody = $request->build();
        $requestOptions = [];

        if (count($builtBody) > 0) {
            $requestOptions[$request->getBodyType()] = $builtBody;
        }

        if (!is_null($request->authType()) && !is_null($clientId)) {
            $requestOptions[RequestOptions::HEADERS] = [
                'Authorization' => "Bearer {$this->getAccessToken($clientId, $clientSecret, $request)}",
            ];
        }

        try {
            $response = $this->apiClient->request($request->getMethod(), $request->getUrn(), $requestOptions);
            $responseClassName = $request->getResponseClass();

            if (empty($responseClassName)) {
                throw new EmptyResponseClassException();
            }

            if (!method_exists($responseClassName, 'deserialize')) {
                throw new WrongResponseTypeException();
            }

            /** @var Response $oruloResponse */
            $oruloResponse = $responseClassName::deserialize($response->getBody()->getContents());
            $oruloResponse->setStatusCode($response->getStatusCode());
        } catch (ClientException | ServerException $e) {
            $errorResponse = $e->getResponse();
            $oruloResponse = ErrorResponse::deserialize($errorResponse->getBody()->getContents());
            $oruloResponse->setStatusCode($errorResponse->getStatusCode());
        } catch (Exception $e) {
            throw new OruloException($e->getMessage(), 0, $e);
        }

        $oruloResponse->initComplexObjects();
        return $oruloResponse;
    }

    /**
     * Returns an URL that the end user must be redirect to.
     *
     * This URL will ask for the user's permission (OAuth) and if he/she authorizes the usage, he/she will be redirected
     * to the 'redirect_uri' set in the configuration file. This URI must be registered through integracao@orulo.com.br.
     *
     * When the end user is redirected to the redirect_uri, there will be a query param named 'code' that will be used
     * to obtain an access_code. This can be made manually or through the 'handleAuthorizationResponse' method.
     *
     * @return string
     * @see http://api.orulo.com.br.s3-website-us-east-1.amazonaws.com/#section/Autenticacao-e-Autorizacao/oruloEndUserAuth
     */
    public function makeAuthorizationUrl(): string
    {
        return sprintf(
            'https://www.orulo.com.br/oauth/authorize?client_id=%s&redirect_uri=%s&response_type=code',
            $this->getConfig('client_id'),
            $this->getConfig('redirect_uri'),
        );
    }

    /**
     * If there is a query param named 'code' in the request parameter, this function tries to exchange it to an access
     * token.
     *
     * If succeeded, the access token will be stored and attributed into the authorizationToken property.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $clientId
     * @param string $clientSecret
     * @return Response
     * @throws AuthorizationException
     * @throws EmptyResponseClassException
     * @throws Lib\Http\Exception\MissingPropertyBodySchemaException
     * @throws MissingCodeException
     * @throws OruloException
     * @throws RequiresEndUserInteraction
     * @throws WrongAuthTypeException
     * @throws WrongRequestTypeException
     * @throws WrongResponseTypeException
     */
    public function handleAuthorizationResponse(
        \Illuminate\Http\Request $request,
        string $clientId,
        string $clientSecret): Response
    {
        if (!$request->has('code')) {
            throw new AuthorizationException('missing required \'code\' param from request');
        }

        $response = $this->requestAndStoreToken(
            AuthType::ORULO_END_USER_AUTH,
            $clientId,
            $clientSecret,
            $request->get('code'),
        );

        if ($response->failed()) {
            return $response;
        }

        /** @var TokenResponse $response */
        $this->authorizationToken = $response;

        return $response;
    }

    /**
     * Returns the cache key used to retrieve a stored authorization response
     *
     * @param string $clientId
     * @return string
     */
    public function getCacheKey(string $clientId): string
    {
        return sprintf('ORULO:CID:%s', $clientId);
    }

    /**
     * Handles the lifecycle of an Orulo's access token.
     * The token is retrieved from the cache if there is one. If there is none, a new one will be requested to Orulo's
     * token generation endpoint.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param Request $request
     * @return Response
     * @throws AuthorizationException
     * @throws EmptyResponseClassException
     * @throws Lib\Http\Exception\MissingPropertyBodySchemaException
     * @throws MissingCodeException
     * @throws OruloException
     * @throws RequiresEndUserInteraction
     * @throws WrongAuthTypeException
     * @throws WrongRequestTypeException
     * @throws WrongResponseTypeException
     */
    private function retrieveAccessToken(string $clientId, string $clientSecret, Request $request): Response
    {
        $cached = Cache::get($this->getCacheKey($clientId));

        if (!is_null($cached)) {
            /** @var TokenResponse $cached */
            if ($cached->getAuthType() !== $request->authType()) {
                throw new WrongAuthTypeException();
            }

            return $cached;
        }

        if ($request->authType() & AuthType::ORULO_END_USER_AUTH && !($request instanceof TokenRequest)) {
            throw new RequiresEndUserInteraction();
        }

        return $this->requestAndStoreToken($request->authType(), $clientId, $clientSecret);
    }

    /**
     * @param int $authType
     * @param string $clientId
     * @param string $clientSecret
     * @param string|null $code
     * @return Response
     * @throws AuthorizationException
     * @throws EmptyResponseClassException
     * @throws Lib\Http\Exception\MissingPropertyBodySchemaException
     * @throws OruloException
     * @throws RequiresEndUserInteraction
     * @throws WrongAuthTypeException
     * @throws WrongRequestTypeException
     * @throws WrongResponseTypeException
     * @throws MissingCodeException
     */
    private function requestAndStoreToken(
        int $authType,
        string $clientId,
        string $clientSecret,
        ?string $code = null
    ): Response
    {
        if ($authType & AuthType::ORULO_END_USER_AUTH && is_null($code)) {
            throw new MissingCodeException();
        }

        /** @var TokenResponse $response */
        $response = $this->request(new TokenRequest($clientId, $clientSecret, $code), $clientId, $clientSecret);

        if ($response->failed()) {
            return $response;
        }

        $response->setAuthType($authType);
        Cache::put($this->getCacheKey($clientId), $response, now()->addSeconds($response->getExpiresIn()));
        return $response;
    }

    /**
     * @param string $clientId
     * @param string $clientSecret
     * @param Request $request
     * @return string
     * @throws AuthorizationException
     * @throws EmptyResponseClassException
     * @throws Lib\Http\Exception\MissingPropertyBodySchemaException
     * @throws MissingCodeException
     * @throws OruloException
     * @throws RequiresEndUserInteraction
     * @throws WrongAuthTypeException
     * @throws WrongRequestTypeException
     * @throws WrongResponseTypeException
     */
    private function getAccessToken(string $clientId, string $clientSecret, Request $request): string
    {
        if (
            !is_null($this->authorizationToken)
            && $this->authorizationToken->getAuthType() === $request->authType()
        ) {
            return $this->authorizationToken->getAccessToken();
        }

        /** @var TokenResponse $token */
        $token = $this->retrieveAccessToken($clientId, $clientSecret, $request);

        if ($token->failed()) {
            throw new AuthorizationException('token retrieval failed');
        }

        $this->authorizationToken = $token;

        if ($this->isAccessTokenValid()) {
            Cache::forget($this->getCacheKey($clientId));
            return $this->getAccessToken($clientId, $clientSecret, $request);
        }

        return $this->authorizationToken->getAccessToken();
    }

    /**
     * Returns true if the current access token is valid, false otherwise
     *
     * @return bool
     */
    private function isAccessTokenValid(): bool
    {
        if (is_null($this->authorizationToken) || $this->authorizationToken->failed()) {
            return false;
        }

        return ($this->authorizationToken->getTimestamp() + $this->authorizationToken->getExpiresIn()) > time();
    }

    /**
     * Returns the configuration value from a given key. Default value is returned if the key is not found.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed|null
     */
    public function getConfig(string $key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    public function useAuthorizationToken(TokenResponse $authorizationToken): self
    {
        $this->authorizationToken = $authorizationToken;
        return $this;
    }
}