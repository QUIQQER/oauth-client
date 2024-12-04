<?php

namespace QUI\OAuth\Client;

use SensitiveParameter;

/**
 * Full configuration for an OAuth Client instance.
 */
class ClientConfiguration
{
    /**
     * @param string $baseUrl
     * @param string $clientId
     * @param string $clientSecret
     * @param ClientSettings|null $settings
     *
     * @throws ClientException
     */
    public function __construct(
        public readonly string $baseUrl,
        #[SensitiveParameter] public readonly string $clientId,
        #[SensitiveParameter] public readonly string $clientSecret,
        public ?ClientSettings $settings = null
    )
    {
        if (empty($this->baseUrl)) {
            throw new ClientException(
                'Please provide a valid REST API URL.'
            );
        }

        if (is_null($this->settings)) {
            $this->settings = new ClientSettings();
        }
    }
}
