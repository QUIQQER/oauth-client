<?php

namespace QUI\OAuth\Client;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Class ResourceOwner
 *
 * This is currently just used as a placeholder class
 *
 * @package QUI\OAuth\Client
 */
class ResourceOwner implements ResourceOwnerInterface
{
    /**
     * Returns the identifier of the authorized resource owner.
     *
     * @return mixed
     */
    public function getId()
    {
        return null;
    }

    /**
     * Return all of the owner details available as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [];
    }
}
