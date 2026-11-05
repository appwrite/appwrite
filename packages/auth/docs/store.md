# Data Store

`Store` is a simple key/value container that can be base64-encoded to a string
and decoded back — useful for serializing authentication state.

```php
<?php

use Utopia\Auth\Store;

// Create a new store
$store = new Store();

// Set various types of data
$store->set('userId', '12345')
      ->set('name', 'John Doe')
      ->set('isActive', true)
      ->set('preferences', ['theme' => 'dark', 'notifications' => true]);

// Get values with optional defaults
$userId = $store->get('userId');
$missing = $store->get('missing', 'default value');

// Encode store data to a base64 string
$encoded = $store->encode();

// Later, decode the string back into a store
$newStore = new Store();
$newStore->decode($encoded);

// Access the decoded data
echo $newStore->get('name'); // Outputs: John Doe
```
