## Getting Started

### Init your SDK
Initialize the SDK with your Appwrite endpoint, project ID, and an API key. Find the endpoint and project ID in your project's **Settings** page, and create an API key with the scopes your code needs under **Overview > Integrations > API keys**. API keys grant server-level access, so keep them out of client-side code.

```php
use Appwrite\Client;

$client = new Client()
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<API_KEY>');
```

If you self-host Appwrite, use your own endpoint, such as `https://appwrite.example.com/v1`. During local development with a self-signed certificate, call `->setSelfSigned()` to skip certificate verification. Never use it in production.

### Make your first request
Once the client is configured, create a service object and call any of its methods. Each method is documented in the [API references](https://appwrite.io/docs/references).

```php
use Appwrite\ID;
use Appwrite\Services\Users;

$users = new Users($client);

$user = $users->create(
    userId: ID::unique(),
    email: 'email@example.com',
    password: 'password',
    name: "Walter O'Brien",
);

echo $user->name;
```

### Full example
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\Users;

$client = new Client()
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<API_KEY>');

$users = new Users($client);

$user = $users->create(
    userId: ID::unique(),
    email: 'email@example.com',
    password: 'password',
    name: "Walter O'Brien",
);

echo $user->name;
```

### Error handling
The SDK throws `Appwrite\AppwriteException` when a request fails. Use `getMessage()`, `getCode()`, `getType()`, and `getResponse()` to inspect the error.

```php
use Appwrite\AppwriteException;

try {
    $user = $users->create(
        userId: ID::unique(),
        email: 'email@example.com',
        password: 'password',
        name: "Walter O'Brien",
    );
} catch (AppwriteException $error) {
    echo $error->getMessage();
}
```

### Learn more
You can use the following resources to learn more and get help:
- 🚀 [PHP Quick Start](https://appwrite.io/docs/quick-starts/php)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite PHP Playground](https://github.com/appwrite/playground-for-php)
