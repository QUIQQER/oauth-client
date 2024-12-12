<?php

namespace QUI\OAuth\Client;

use function is_dir;
use function is_null;
use function is_readable;

/**
 * Specific settings for an OAuth client instance.
 */
readonly class ClientSettings
{
    /**
     * @param string|null $cachePath
     *  Writable cache path where access tokens are stored in a file.
     *
     *  If this is set to NULL, the client will check if QUIQQER is installed and cache
     *  it via the QUIQQER cache manager.
     *
     *  If this is set to NULL and QUIQQER is not installed, access tokens are not cached and are
     *  retrieved freshly for every REST request (if authentication is required)
     *
     * @param int $timeout - Default request timeout (seconds)
     * @param bool $retryOn503
     *  Retry POST/GET request ONCE if a 503 response is returned.
     *  Waits 1 second before retrying.
     *
     * @param bool $jsonDecodeResponseBody - Decode the response body as JSON (if valid JSON)
     *
     * @throws ClientException
     */
    public function __construct(
        public ?string $cachePath = null,
        public int $timeout = 60,
        public bool $retryOn503 = true,
        public bool $jsonDecodeResponseBody = false
    ) {
        if (!is_null($this->cachePath)) {
            if (!is_dir($this->cachePath) || !is_writable($this->cachePath) || !is_readable($this->cachePath)) {
                throw new ClientException(
                    "Invalid cache path :: " . $this->cachePath . " is not a writable/readable directory."
                );
            }
        }
    }
}
