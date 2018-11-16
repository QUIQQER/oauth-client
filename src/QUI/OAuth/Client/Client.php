<?php

namespace QUI\OAuth\Client;

use GuzzleHttp\Exception\ClientException as GuzzleHttpClientException;
use League\OAuth2\Client\Token\AccessToken;
use QUI;
use QUI\Cache\Manager as QUIQQERCacheManager;

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
     * Client constructor.
     *
     * @param string $baseUrl - The base URL for the REST API
     * @param string $clientId - OAuth Client-ID
     * @param string $clientSecret - OAuth Client secret
     * @param array $settings (optional) - Additional client settings
     */
    public function __construct($baseUrl, $clientId, $clientSecret, $settings = [])
    {
        $defaultSettings = [
            /**
             * Writable cache path where access tokens are stored in a file
             *
             * If this is set to false, the client will check if QUIQQER is installed and cache
             * it via the QUIQQER cache manager.
             *
             * If in this case QUIQQER is not installed, access tokens are not cached and are
             * retrieved freshly for every REST request
             */
            'cachePath' => false
        ];

        $this->settings = array_merge($defaultSettings, $settings);
        $this->baseUrl  = rtrim($baseUrl, '/').'/'; // ensure trailing slash
        $this->clientId = $clientId;

        $this->Provider = new Provider([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret
        ]);

        $this->Provider->setBaseUrl($this->baseUrl);
    }

    /**
     * Perform a GET request
     *
     * @param string $path - Request path
     * @param array $params (optional) - GET parameters
     * @return string - Response data
     */
    public function getRequest(string $path, array $params = [])
    {
        try {
            $query = http_build_query(array_merge([
                'access_token' => $this->getAccessToken()->getToken()
            ], $params));
        } catch (\Exception $Exception) {
            return $this->getExceptionResponse($Exception);
        }

        $path = ltrim($path, '/');

        $Request = $this->Provider->getRequest(
            'GET',
            $this->baseUrl.$path.'?'.$query
        );

        try {
            $Response = $this->Provider->getResponse($Request);
        } catch (\Exception $Exception) {
            return $this->getExceptionResponse($Exception);
        }

        return $Response->getBody()->getContents();
    }

    /**
     * Perform a POST request
     *
     * @param string $path - Request path
     * @param string $data (optional) - Additional POST data
     * @return string - Response data
     */
    public function postRequest(string $path, string $data = null)
    {
        try {
            $query = http_build_query([
                'access_token' => $this->getAccessToken()->getToken()
            ]);
        } catch (\Exception $Exception) {
            return $this->getExceptionResponse($Exception);
        }

        $path = ltrim($path, '/');

        $Request = $this->Provider->getRequest(
            'POST',
            $this->baseUrl.$path.'?'.$query,
            [
                'body' => $data
            ]
        );

        try {
            $Response = $this->Provider->getResponse($Request);
        } catch (\Exception $Exception) {
            return $this->getExceptionResponse($Exception);
        }

        return $Response->getBody()->getContents();
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
        $this->Token = $this->Provider->getAccessToken('client_credentials');

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
}
