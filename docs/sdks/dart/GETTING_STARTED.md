## Getting Started

### Initialize the client

Import the package and point the client at your project. Server code authenticates with an API key, which you create in the Appwrite Console with the scopes your code needs. Keep the key on the server and never ship it in a client app.

```dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');
```

> If you self-host Appwrite, use your instance's endpoint instead, for example `https://appwrite.example.com/v1`. Call `setSelfSigned()` only in development, when the instance uses a self-signed certificate.

To act on behalf of a user instead of with an API key, pass the user's JWT with `setJWT('<JWT>')`.

### Make a request

Every service takes the client in its constructor, and every method takes named parameters and returns a typed model from `package:dart_appwrite/models.dart`.

```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/models.dart' as models;

Users users = Users(client);

models.User user = await users.create(
  userId: ID.unique(),
  email: 'email@example.com',
  password: 'password',
  name: "Walter O'Brien",
);
```

### Error handling

The SDK throws an `AppwriteException` with `message`, `code`, `type` and `response` properties. Catch it to present the `message` to the user or to handle the error based on its `type`.

```dart
try {
  models.User user = await users.get(userId: '<USER_ID>');
} on AppwriteException catch (e) {
  print('${e.code} ${e.type}: ${e.message}');
}
```

### Learn more

- [Dart quick start](https://appwrite.io/docs/quick-starts/dart)
- [Server Dart API reference](https://appwrite.io/docs/references/cloud/server-dart/tablesDB)
- [Appwrite docs](https://appwrite.io/docs)
- [Discord community](https://appwrite.io/discord)
- [Appwrite Dart playground](https://github.com/appwrite/playground-for-dart)
