# Examples

Init your Appwrite client:

```dart
  Client client = Client();

  client
      .setEndpoint('https://localhost/v1') // Your Appwrite Endpoint
      .setProject('5e8cf4f46b5e8') // Your project ID
      .setSelfSigned() // Remove in production
  ;

```

Create a new user and session:

```dart
Account account = Account(client);

Response user = await account.create(email: 'me@appwrite.io', password: 'password', name: 'My Name');
 
Response session = await account.createSession(email: 'me@appwrite.io', password: 'password');

```

Fetch user profile:

```dart
Account account = Account(client);

Response profile = await account.get();
```

Upload File:

```dart
Storage storage = Storage(client);

MultipartFile file = MultipartFile.fromFile('./path-to-file/image.jpg', filename: 'image.jpg');

storage.createFile(
    file: file,
    read: ['role:all'],
    write: []
)
.then((response) {
    print(response); // File uploaded!
})
.catchError((error) {
    print(error.response);
});
```

All examples and API features are available at the [official Appwrite docs](https://appwrite.io/docs)