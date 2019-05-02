![QUIQQER OAuth Server](bin/images/Readme.jpg)

QUIQQER OAuth Server
========

Make requests to QUIQQER REST APIs that (may) require OAuth 2.0 authentication. 

Package Name:

    quiqqer/oauth-client


Features
--------
* Easily perform POST and GET requests to QUIQQER REST APIs
* No QUIQQER system required - use this package as a standalone mini-framework
* (Optional) OAuth 2.0 authentication (may be required by some QUIQQER REST APIs); currently supported grant type: `client_credentials`

Installation
------------
The Package Name is: quiqqer/oauth-client

Usage
------------
```php
<?php

$OAuthClient = new \QUI\OAuth\Client\Client([
    'https://my-quiqqer-api.com/api',
    'myQuiqqerOauthClientId',
    'myQuiqqerOauthClientSercet'
]);


$response = $OAuthClient->getRequest('users/info/', [
    'userId' => 123456    
]);

// $response is a string, containing the API answer (usually JSON);
// i.e. "{"username":"peat"}"

```

Contribute
----------
- Project: https://dev.quiqqer.com/quiqqer/oauth-client
- Issue Tracker: https://dev.quiqqer.com/quiqqer/oauth-client/issues
- Source Code: https://dev.quiqqer.com/quiqqer/oauth-client/tree/master

Support
-------
If you found any errors or have wishes or suggestions for improvement,
please contact us by email at support@pcsg.de.

We will transfer your message to the responsible developers.

License
-------
GPL-3.0+