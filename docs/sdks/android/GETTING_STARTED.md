## Getting Started

### Init your SDK

<p>Initialize your SDK code with your project ID, which can be found in your project settings page.

```kotlin
import io.appwrite.AppwriteClient
import io.appwrite.services.AccountService

val client = AppwriteClient(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID
  .setSelfSigned(true) // Remove in production
```

Before starting to send any API calls to your new Appwrite instance, make sure your Android emulators has network access to the Appwrite server hostname or IP address.

When trying to connect to Appwrite from an emulator or a mobile device, localhost is the hostname for the device or emulator and not your local Appwrite instance. You should replace localhost with your private IP as the Appwrite endpoint's hostname. You can also use a service like [ngrok](https://ngrok.com/) to proxy the Appwrite API.

### Make Your First Request

<p>Once your SDK object is set, access any of the Appwrite services and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the API References section.

```kotlin
// Register User
val accountService = AccountService(client)
val user = accountService.create(
    "email@example.com", 
    "password"
)
```

### Full Example

```kotlin
import io.appwrite.AppwriteClient
import io.appwrite.services.AccountService

val client = AppwriteClient(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID
  .setSelfSigned(true) // Remove in production

val accountService = AccountService(client)
val user = accountService.create(
    "email@example.com", 
    "password"
)
```

### Learn more
You can use followng resources to learn more and get help
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)