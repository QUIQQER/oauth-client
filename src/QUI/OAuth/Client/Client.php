<?php

namespace QUI\OAuth\Client;

use GuzzleHttp\Exception\ClientException as GuzzleHttpClientException;
use QUI;
use League\OAuth2\Client\Token\AccessToken;

/**
 * REST API Client for CleverReach
 */
class Client
{
    const TABLE_ACCESS_TOKENS = 'oauth_client_tokens';

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
     * Client constructor.
     *
     * @param string $baseUrl - The base URL for the REST API
     * @param string $clientId - OAuth Client-ID
     * @param string $clientSecret - OAuth Client secret
     */
    public function __construct($baseUrl, $clientId, $clientSecret)
    {
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
     */
    protected function getAccessToken()
    {
        if (!is_null($this->Token) && !$this->Token->hasExpired()) {
            return $this->Token;
        }

        // check if a token has been previously stored for the given baseUrl
        $result = QUI::getDataBase()->fetch([
            'select' => ['access_token'],
            'from'   => self::TABLE_ACCESS_TOKENS,
            'where'  => [
                'client_id' => $this->clientId
            ]
        ]);

        if (!empty($result)) {
            $Token = new AccessToken(json_decode(current($result)));

            if (!$Token->hasExpired()) {
                $this->Token = $Token;
                return $this->Token;
            }

            // delete expired token from database
            QUI::getDataBase()->delete(
                self::TABLE_ACCESS_TOKENS,
                [
                    'client_id' => $this->clientId
                ]
            );
        }

        // create new access token
        $this->Token = $this->Provider->getAccessToken('client_credentials');

        // save token to database
        QUI::getDataBase()->insert(
            self::TABLE_ACCESS_TOKENS,
            [
                'client_id'    => $this->clientId,
                'access_token' => json_encode($this->Token->jsonSerialize())
            ]
        );

        return $this->Token;
    }
}
