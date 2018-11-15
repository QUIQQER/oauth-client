<?php

namespace QUI\OAuth\Client;

use GuzzleHttp\Exception\ClientException as GuzzleHttpClientException;
use League\OAuth2\Client\Token\AccessToken;
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
             * retrieved via request in every new PHP runtime
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
     * @return array - Response data
     * @throws ClientException
     */
    public function getRequest(string $path, array $params = [])
    {
        $query = http_build_query(array_merge([
            'token' => $this->getAccessToken()->getToken()
        ], $params));

        $Request = $this->Provider->getRequest(
            'GET',
            $this->baseUrl.$path.'?'.$query
        );

        try {
            $Response = $this->Provider->getResponse($Request);
        } catch (GuzzleHttpClientException $Exception) {
            $exBody = json_decode($Exception->getResponse()->getBody()->getContents(), true);

            throw new ClientException(
                $exBody['error']['message'],
                $exBody['error']['code']
            );
        }

        return json_decode($Response->getBody()->getContents(), true);
    }

    /**
     * Perform a POST request
     *
     * @param string $path - Request path
     * @param string $data (optional) - Additional POST data
     * @return array - Response data
     * @throws ClientException
     */
    public function postRequest(string $path, string $data = null)
    {
        $query = http_build_query([
            'token' => $this->getAccessToken()->getToken()
        ]);

        $Request = $this->Provider->getRequest(
            'POST',
            $this->baseUrl.$path.'?'.$query
        );

        if (is_string($data)) {
            $Request->getBody()->write($data);
        }

        try {
            $Response = $this->Provider->getResponse($Request);
        } catch (GuzzleHttpClientException $Exception) {
            $exBody = json_decode($Exception->getResponse()->getBody()->getContents(), true);

            throw new ClientException(
                $exBody['error']['message'],
                $exBody['error']['code']
            );
        }

        return json_decode($Response->getBody()->getContents(), true);
    }

    /**
     * Get AccessToken
     *
     * @return AccessToken
     * @throws ClientException
     */
    protected function getAccessToken()
    {
        if (!is_null($this->Token) && !$this->Token->hasExpired()) {
            return $this->Token;
        }

        // check if a token has been previously stored for the given client id
        $cacheName = 'access_token_'.$this->clientId;
        $tokenData = $this->readFromCache($cacheName);

        if (!empty($tokenData)) {
            $Token = new AccessToken(json_decode($tokenData));

            if (!$Token->hasExpired()) {
                $this->Token = $Token;
                return $this->Token;
            }
        }

        // create new access token
        $this->Token = $this->Provider->getAccessToken('client_credentials');

        // save token to database
        $this->writeToCache($cacheName, json_encode($this->Token->jsonSerialize()));

        return $this->Token;
    }

    /**
     * Write data to a cache file or the QUIQQER cache
     *
     * @param string $name - Cache data identifier
     * @param string $data - Cache data
     * @return void
     * @throws ClientException
     */
    protected function writeToCache(string $name, string $data)
    {
        // try to use QUIQQER cache manager
        if (class_exists(QUIQQERCacheManager::class)) {
            try {
                QUIQQERCacheManager::set($this->quiqqerCachePrefix.$name, $data);
            } catch (\Exception $Exception) {
                throw new ClientException(
                    $Exception->getMessage(),
                    $Exception->getCode()
                );
            }

            return;
        }

        // try to use filesystem
        $cachePath = $this->settings['cachePath'];

        if (empty($cachePath) || !is_dir($cachePath) || !is_writable($cachePath)) {
            return;
        }

        $cacheFile = rtrim($cachePath, '/').'/cache_'.$name;
        file_put_contents($cacheFile, $data);
    }

    /**
     * Read data from a cache file or the QUIQQER cache
     *
     * @param string $name - Cache data identifier
     * @return  string|false - Cache data or false if not found/cached
     */
    protected function readFromCache(string $name)
    {
        // try to use QUIQQER cache manager
        if (class_exists(QUIQQERCacheManager::class)) {
            try {
                return QUIQQERCacheManager::get($this->quiqqerCachePrefix.$name);
            } catch (\Exception $Exception) {
                return false;
            }
        }

        // try to use filesystem
        $cachePath = $this->settings['cachePath'];

        if (empty($cachePath) || !is_dir($cachePath) || !is_readable($cachePath)) {
            return false;
        }

        $cacheFile = rtrim($cachePath, '/').'/cache_'.$name;

        if (!file_exists($cacheFile) || is_readable($cacheFile)) {
            return false;
        }

        return file_get_contents($cacheFile);
    }
}
