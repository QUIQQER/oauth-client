<?php

namespace QUI\OAuth\Client;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use QUI;
use QUI\Cache\Manager as QUIQQERCacheManager;

use function array_merge;
use function class_exists;
use function http_build_query;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_validate;

/**
 * REST API Client for QUIQQER REST APIs
 */
class Client
{
    /**
     * REST API base url
     */
    protected string $baseUrl;
    protected ?AccessToken $Token = null;
    protected Provider $Provider;
    protected ClientSettings $settings;

    /**
     * Cache name prefix for data that is cached in the QUIQQER cache
     */
    protected string $quiqqerCachePrefix = 'quiqqer/oauth-client/cache/';

    /**
     * Flag that indicates if a request has been retried ONCE.
     */
    protected bool $failureRetry = false;

    /**
     * Globals parameters that are sent with every request.
     */
    protected array $globalRequestParams = [];

    /**
     * Client constructor.
     *
     * @param ClientConfiguration $configuration
     */
    public function __construct(private readonly ClientConfiguration $configuration)
    {
//        $this->settings = array_merge($defaultSettings, $settings);
        $this->settings = $this->configuration->settings;
        $this->baseUrl = rtrim($this->configuration->baseUrl, '/') . '/'; // ensure trailing slash

        $this->Provider = new Provider([
            'clientId' => $configuration->clientId,
            'clientSecret' => $configuration->clientSecret,
            'timeout' => $configuration->settings->timeout
        ]);

        $this->Provider->setBaseUrl($this->baseUrl);
    }

    /**
     * Perform a GET request
     *
     * @param string $path - Request path
     * @param array $params (optional) - GET parameters
     * @param bool $authentication (optional) - Perform OAuth 2.0 authentication; this is TRUE by default
     * but may be disabled if a REST API endpoint does not require authentication
     * @return string|array - Response data
     */
    public function getRequest(string $path, array $params = [], bool $authentication = true): string|array
    {
        return $this->request(
            "GET",
            $path,
            $params,
            null,
            $authentication
        );
    }

    /**
     * Perform a POST request
     *
     * @param string $path - Request path
     * @param string|array|null $data (optional) - Additional POST data
     * @param bool $authentication (optional) - Perform OAuth 2.0 authentication; this is TRUE by default
     * but may be disabled if a REST API endpoint does not require authentication
     * @return string|array - Response data
     */
    public function postRequest(string $path, string|array|null $data = null, bool $authentication = true): string|array
    {
        return $this->request(
            "POST",
            $path,
            null,
            $data,
            $authentication
        );
    }

    /**
     * Perform a request with specified method
     *
     * @param string $method - POST, GET, PUT, PATCH, DELETE
     * @param string $path - Request path
     * @param array|null $getParams (optional)
     * @param string|array|null $body (optional) - Additional POST data
     * @param bool $authentication (optional) - Perform OAuth 2.0 authentication; this is TRUE by default
     * but may be disabled if a REST API endpoint does not require authentication
     * @return string|array - Response data
     */
    public function request(
        string $method,
        string $path,
        ?array $getParams = null,
        string|array|null $body = null,
        bool $authentication = true
    ): string|array {
        $path = ltrim($path, '/');
        $requestUrl = $this->baseUrl . $path;

        if ($authentication) {
            try {
                $getParams['access_token'] = $this->getAccessToken()->getToken();
            } catch (\Exception $exception) {
                return $this->getExceptionResponse($exception);
            }
        }

        $queryParams = array_merge(
            $getParams ?: [],
            $this->globalRequestParams,
        );

        if (!empty($queryParams)) {
            $query = http_build_query($queryParams);
            $requestUrl .= '?' . $query;
        }

        $requestOptions = [];

        if (!is_null($body)) {
            $contentType = 'application/x-www-form-urlencoded';

            if (is_array($body)) {
                $body = json_encode($body);
                $contentType = 'application/json';
            } elseif (is_string($body) && $this->isJson($body)) {
                $contentType = 'application/json';
            }

            $requestOptions['headers'] = [
                'Content-Type' => $contentType
            ];

            $requestOptions['body'] = $body;
        }

        $Request = $this->Provider->getRequest(
            $method,
            $requestUrl,
            $requestOptions
        );

        try {
            $response = $this->Provider->getResponse($Request);

            if ($this->settings->retryOn503 && $response->getStatusCode() === 503 && !$this->failureRetry) {
                $this->failureRetry = true;
                sleep(1);
                return $this->request($path, $path, $getParams, $body, $authentication);
            }
        } catch (\GuzzleHttp\Exception\ClientException $exception) {
            if ($this->settings->retryOn503 && $exception->getCode() === 503 && !$this->failureRetry) {
                $this->failureRetry = true;
                sleep(1);
                return $this->request($path, $path, $getParams, $body, $authentication);
            }

            $this->failureRetry = false;

            return $this->getExceptionResponse(
                new \Exception(
                    $exception->getResponse()->getBody()->getContents(),
                    $exception->getCode()
                )
            );
        } catch (\Exception $exception) {
            $this->failureRetry = false;

            return $this->getExceptionResponse($exception);
        }

        $this->failureRetry = false;

        $responseBody = $response->getBody()->getContents();

        if (!$this->settings->jsonDecodeResponseBody || !json_validate($responseBody)) {
            return $responseBody;
        }

        return json_decode($responseBody, true);
    }

    /**
     * @param array $globalRequestParams
     */
    public function setGlobalRequestParams(array $globalRequestParams): void
    {
        $this->globalRequestParams = $globalRequestParams;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setGlobalRequestParam(string $key, $value): void
    {
        $this->globalRequestParams[$key] = $value;
    }

    /**
     * Get AccessToken
     *
     * @return AccessToken
     * @throws IdentityProviderException
     */
    protected function getAccessToken(): AccessToken
    {
        if (!is_null($this->Token) && !$this->Token->hasExpired()) {
            return $this->Token;
        }

        // check token cache
        $cacheName = 'access_token_' . $this->configuration->clientId;
        $tokenData = $this->readFromCache($cacheName);

        if (!empty($tokenData)) {
            $Token = new AccessToken(json_decode($tokenData, true));

            if (!$Token->hasExpired()) {
                $this->Token = $Token;
                return $this->Token;
            }
        }

        // create new access token
        $this->Token = $this->Provider->getAccessToken('client_credentials', $this->globalRequestParams);

        // cache token
        $this->writeToCache($cacheName, json_encode($this->Token->jsonSerialize()));

        return $this->Token;
    }

    /**
     * Write data to a cache file OR the QUIQQER cache (if QUIQQER is installed)
     *
     * @param string $name - Cache data identifier
     * @param string $data - Cache data
     * @return void
     */
    protected function writeToCache(string $name, string $data): void
    {
        // try to use filesystem
        $cachePath = $this->settings->cachePath;

        if (!empty($cachePath)) {
            $cacheFile = rtrim($cachePath, '/') . '/cache_' . $name;
            file_put_contents($cacheFile, $data);
            return;
        }

        // try to use QUIQQER cache manager
        if (class_exists('QUI\Cache\Manager')) {
            try {
                QUIQQERCacheManager::set($this->quiqqerCachePrefix . $name, $data);
            } catch (\Exception $Exception) {
                if (class_exists('QUI\System\Log')) {
                    QUI\System\Log::writeException($Exception);
                }
            }
        }
    }

    /**
     * Read data from a cache file or the QUIQQER cache
     *
     * @param string $name - Cache data identifier
     * @return  string|null - Cache data or false if not found/cached
     */
    protected function readFromCache(string $name): string|null
    {
        // try to use filesystem
        $cachePath = $this->settings->cachePath;

        if (!empty($cachePath)) {
            $cacheFile = rtrim($cachePath, '/') . '/cache_' . $name;

            if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
                return null;
            }

            return file_get_contents($cacheFile);
        }

        if (class_exists('QUI\Cache\Manager')) {
            try {
                return QUIQQERCacheManager::get($this->quiqqerCachePrefix . $name);
            } catch (\Exception $exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get a response string from an exception
     *
     * @param \Exception $Exception
     * @return string - JSON
     */
    protected function getExceptionResponse(\Exception $Exception): string
    {
        return json_encode([
            'error' => true,
            'error_description' => $Exception->getMessage(),
            'error_code' => $Exception->getCode()
        ]);
    }

    /**
     * Check if a string is in JSON format
     *
     * @param mixed $str
     * @return bool
     */
    protected function isJson(string $str): bool
    {
        return json_validate($str);
    }
}
