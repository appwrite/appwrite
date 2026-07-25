## Getting Started

### Initialize & Make API Request
Once you add the dependencies, its extremely easy to get started with the SDK; All you need to do is import the package in your code, set your Appwrite credentials, and start making API calls. Below is a simple example:

```dart
Client client = Client()
  .setProject('<YOUR_PROJECT_ID>')
  .setKey('<YOUR_API_KEY>');

Users users = Users(client);

User user = await users.create(
  userId: ID.unique(),
  email: 'email@example.com',
  phone: '+123456789',
  password: 'password',
  name: 'Walter O'Brien'
);
```

### Error handling
The Appwrite Dart SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```dart
try {
  User user = await users.create(...);
} on AppwriteException catch(e) {
  // Handle the error
}
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Dart Playground](https://github.com/appwrite/playground-for-dart)
