## Getting Started

### Init your SDK
Initialize your SDK code with your project ID which can be found in your project settings page and your new API secret Key from project's API keys section.

```php
$client = new Client();

$client
    ->setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;
```

### Make Your First Request
Once your SDK object is set, create any of the Appwrite service project objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the API References section.

```php
$users = new Users($client);

$result = $users->create('email@example.com', 'password');
```

### Full Example
```php
use Appwrite\Client;
use Appwrite\Services\Users;

$client = new Client();

$client
    ->setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$users = new Users($client);

$result = $users->create('email@example.com', 'password');
```

### Learn more
You can use followng resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite PHP Playground](https://github.com/appwrite/playground-for-php)
