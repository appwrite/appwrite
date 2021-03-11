## Getting Started

### Initialize & Make API Request
Once you add the dependencies, its extremely easy to get started with the SDK; All you need to do is import the package in your code, set your Appwrite credentials, and start making API calls. Below is a simple example:

```dart
import 'package:dart_appwrite/dart_appwrite.dart';

void main() async {
  Client client = Client();
    .setEndpoint('http://[HOSTNAME_OR_IP]/v1') // Make sure your endpoint is accessible
    .setProject('5ff3379a01d25') // Your project ID
    .setKey('cd868c7af8bdc893b4...93b7535db89')

  Users users = Users(client);

  try {
    final response = await users.create(email: ‘email@example.com’,password: ‘password’, name: ‘name’);
    print(response.data);
  } on AppwriteException catch(e) {
    print(e.message);
  }
}
```