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

Create a new user:

```dart
Users users = Users(client);

Response result = await users.create(
    email: 'email@example.com',
    password: 'password',
);
 
```

Fetch user profile:

```dart
Users users = Users(client);

Response profile = await users.get(
    userId: '[USER_ID]',
);
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