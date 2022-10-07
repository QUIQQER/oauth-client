<?php

namespace QUI\OAuth\Client;

use League\OAuth2\Client\Token\AccessToken;
use QUI;
use QUI\Cache\Manager as QUIQQERCacheManager;
use function is_array;
use function is_string;
use function json_decode;
use function json_last_error;
use const JSON_ERROR_NONE;

/**
 * REST API Client for CleverReach
 */
class Client
{
    /**
     * REST API base url
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * OAuth Client ID
     *
     * @var string
     */
    protected $clientId;

    /**
     * @var AccessToken
     */
    protected $Token = null;

    /**
     * @var Provider
     */
    protected $Provider;

    /**
     * Cache name prefix for data that is cached in the QUIQQER cache
     *
     * @var string
     */
    protected $quiqqerCachePrefix = 'quiqqer/oauth-client/cache/';

    /**
     * Client settings
     *
     * @var array
     */
    protected $settings = [];

    /**
     * Flag that indicates if a request has been retried ONCE.
     *
     * @var bool
     */
    protected $failureRetry = false;

    /**
     * Globals parameters that are sent with every request.
     *
     * @var array
     */
    protected $globalRequestParams = [];

    /**
     * Client constructor.
     *
     * @param string $baseUrl - The base URL for the REST API
     * @param string $clientId (optional) - OAuth Client-ID
     * @param string $clientSecret (optional) - OAuth Client secret
     * @param array $settings (optional) - Additional client settings
     * @throws ClientException
     */
    public function __construct($baseUrl, $clientId = null, $clientSecret = null, $settings = [])
    {
        if (empty($baseUrl)) {
            throw new ClientException(
                'Please provide a valid REST API URL.'
            );
        }

        $defaultSettings = [
            /**
             * Writable cache path where access tokens are stored in a file
             *
             * If this is set to false, the client will check if QUIQQER is installed and cache
             * it via the QUIQQER cache manager.
             *
             * If in this case QUIQQER is not installed, access tokens are not cached and are
             * retrieved freshly for every REST request (if authentication is required)
             */
            'cachePath'  => false,

            /**
             * Default request timeout in seconds
             */
            'timeout'    => 60,

            /**
             * Retry POST/GET request ONCE if a 503 response is returned.
             *
             * Waits 1 second before retrying.
             */
            'retryOn503' => true
        ];

        $this->settings = array_merge($defaultSettings, $settings);
        $this->baseUrl  = rtrim($baseUrl, '/').'/'; // ensure trailing slash
        $this->clientId = $clientId;

        $this->Provider = new Provider([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'timeout'      => (int)$this->settings['timeout']
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
     * @return string - Response data
     */
    public function getRequest(string $path, array $params = [], $authentication = true)
    {
        $path       = ltrim($path, '/');
        $requestUrl = $this->baseUrl.$path;

        if ($authentication) {
            try {
                $params['access_token'] = $this->getAccessToken()->getToken();
            } catch (\Exception $Exception) {
                return $this->getExceptionResponse($Exception);
            }
        }

        $requestUrl .= '?'.http_build_query($params);

        $Request = $this->Provider->getRequest(
            'GET',
            $requestUrl
        );

        try {
            $Response = $this->Provider->getResponse($Request);

            if ($this->settings['retryOn503'] && $Response->getStatusCode() === 503 && !$this->failureRetry) {
                $this->failureRetry = true;
                sleep(1);
                return $this->getRequest($path, $params, $authentication);
            }
        } catch (\Exception $Exception) {
            if ($Exception instanceof \GuzzleHttp\Exception\ClientException) {
                if ($this->settings['retryOn503'] && $Exception->getCode() === 503 && !$this->failureRetry) {
                    $this->failureRetry = true;
                    sleep(1);
                    return $this->getRequest($path, $params, $authentication);
                }

                $this->failureRetry = false;

                return $this->getExceptionResponse(new \Exception(
                    $Exception->getResponse()->getBody()->getContents(),
                    $Exception->getCode()
                ));
            } else {
                $this->failureRetry = false;

                return $this->getExceptionResponse($Exception);
            }
        }

        $this->failureRetry = false;

        return $Response->getBody()->getContents();
    }

    /**
     * Perform a POST request
     *
     * @param string $path - Request path
     * @param string|array $data (optional) - Additional POST data
     * @param bool $authentication (optional) - Perform OAuth 2.0 authentication; this is TRUE by default
     * but may be disabled if a REST API endpoint does not require authentication
     * @return string - Response data
     */
    public function postRequest(string $path, $data = null, $authentication = true)
    {
        $path       = ltrim($path, '/');
        $requestUrl = $this->baseUrl.$path;

        if ($authentication) {
            try {
                $query = http_build_query([
                    'access_token' => $this->getAccessToken()->getToken()
                ]);

                $requestUrl .= '?'.$query;
            } catch (\Exception $Exception) {
                return $this->getExceptionResponse($Exception);
            }
        }

        if (is_array($data)) {
            $data = json_encode($data);
        }

        if ($this->isJson($data)) {
            $contentType = 'application/json';
        } else {
            $contentType = 'application/x-www-form-urlencoded';
        }

        $Request = $this->Provider->getRequest(
            'POST',
            $requestUrl,
            [
                'headers' => [
                    'Content-Type' => $contentType
                ],
                'body'    => $data
            ]
        );

        try {
            $Response = $this->Provider->getResponse($Request);

            if ($this->settings['retryOn503'] && $Response->getStatusCode() === 503 && !$this->failureRetry) {
                $this->failureRetry = true;
                sleep(1);
                return $this->postRequest($path, $data, $authentication);
            }
        } catch (\Exception $Exception) {
            if ($Exception instanceof \GuzzleHttp\Exception\ClientException) {
                if ($this->settings['retryOn503'] && $Exception->getCode() === 503 && !$this->failureRetry) {
                    $this->failureRetry = true;
                    sleep(1);
                    return $this->postRequest($path, $data, $authentication);
                }

                $this->failureRetry = false;

                return $this->getExceptionResponse(new \Exception(
                    $Exception->getResponse()->getBody()->getContents(),
                    $Exception->getCode()
                ));
            } else {
                $this->failureRetry = false;

                return $this->getExceptionResponse($Exception);
            }
        }

        $this->failureRetry = false;

        return $Response->getBody()->getContents();
    }

    /**
     * Perform a request with specified method
     *
     * @param string $method - POST, GET, PUT, PATCH, DELETE
     * @param string $path - Request path
     * @param string|array $data (optional) - Additional POST data
     * @param bool $authentication (optional) - Perform OAuth 2.0 authentication; this is TRUE by default
     * but may be disabled if a REST API endpoint does not require authentication
     * @return string - Response data
     */
    public function request(string $method, string $path, $data = null, bool $authentication = true): string
    {
        $path       = ltrim($path, '/');
        $requestUrl = $this->baseUrl.$path;

        if ($authentication) {
            try {
                $query = http_build_query(
                    \array_merge(
                        $this->globalRequestParams,
                        [
                            'access_token' => $this->getAccessToken()->getToken()
                        ]
                    )
                );

                $requestUrl .= '?'.$query;
            } catch (\Exception $Exception) {
                return $this->getExceptionResponse($Exception);
            }
        }

        if (is_array($data)) {
            $data = json_encode($data);
        }

        if ($this->isJson($data)) {
            $contentType = 'application/json';
        } else {
            $contentType = 'application/x-www-form-urlencoded';
        }

        $Request = $this->Provider->getRequest(
            $method,
            $requestUrl,
            \array_merge([
                'headers' => [
                    'Content-Type' => $contentType
                ],
                'body'    => $data,
            ])
        );

        try {
            $Response = $this->Provider->getResponse($Request);

            if ($this->settings['retryOn503'] && $Response->getStatusCode() === 503 && !$this->failureRetry) {
                $this->failureRetry = true;
                sleep(1);
                return $this->postRequest($path, $data, $authentication);
            }
        } catch (\Exception $Exception) {
            if ($Exception instanceof \GuzzleHttp\Exception\ClientException) {
                if ($this->settings['retryOn503'] && $Exception->getCode() === 503 && !$this->failureRetry) {
                    $this->failureRetry = true;
                    sleep(1);
                    return $this->postRequest($path, $data, $authentication);
                }

                $this->failureRetry = false;

                return $this->getExceptionResponse(new \Exception(
                    $Exception->getResponse()->getBody()->getContents(),
                    $Exception->getCode()
                ));
            } else {
                $this->failureRetry = false;

                return $this->getExceptionResponse($Exception);
            }
        }

        $this->failureRetry = false;

        return $Response->getBody()->getContents();
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
     */
    protected function getAccessToken()
    {
        if (!is_null($this->Token) && !$this->Token->hasExpired()) {
            return $this->Token;
        }

        // check token cache
        $cacheName = 'access_token_'.$this->clientId;
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
     * Write data to a cache file or the QUIQQER cache
     *
     * @param string $name - Cache data identifier
     * @param string $data - Cache data
     * @return void
     */
    protected function writeToCache(string $name, string $data)
    {
        // try to use filesystem
        $cachePath = $this->settings['cachePath'];

        if (!empty($cachePath) && is_dir($cachePath) && is_writable($cachePath)) {
            $cacheFile = rtrim($cachePath, '/').'/cache_'.$name;
            file_put_contents($cacheFile, $data);
            return;
        }

        // try to use QUIQQER cache manager
        if (class_exists(QUIQQERCacheManager::class)) {
            try {
                QUIQQERCacheManager::set($this->quiqqerCachePrefix.$name, $data);
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * Read data from a cache file or the QUIQQER cache
     *
     * @param string $name - Cache data identifier
     * @return  string|false - Cache data or false if not found/cached
     */
    protected function readFromCache(string $name)
    {
        // try to use filesystem
        $cachePath = $this->settings['cachePath'];

        if (!empty($cachePath) && is_dir($cachePath) && is_readable($cachePath)) {
            $cacheFile = rtrim($cachePath, '/').'/cache_'.$name;

            if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
                return false;
            }

            return file_get_contents($cacheFile);
        }

        // try to use QUIQQER cache manager
        if (class_exists(QUIQQERCacheManager::class)) {
            try {
                return QUIQQERCacheManager::get($this->quiqqerCachePrefix.$name);
            } catch (\Exception $Exception) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get a response from an exception
     *
     * @param \Exception $Exception
     * @return string - JSON
     */
    protected function getExceptionResponse(\Exception $Exception)
    {
        return json_encode([
            'error'             => true,
            'error_description' => $Exception->getMessage(),
            'error_code'        => $Exception->getCode()
        ]);
    }

    /**
     * Check if a string is in JSON format
     *
     * @param mixed $str
     * @return bool
     */
    protected function isJson($str): bool
    {
        if (!is_string($str)) {
            return false;
        }

        $str = json_decode($str, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($str);
    }
}
