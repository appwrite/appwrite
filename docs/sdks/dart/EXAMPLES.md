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

User result = await users.create(
    userId: '[USER_ID]',
    email: 'email@example.com',
    password: 'password',
);
```

Fetch user profile:

```dart
Users users = Users(client);

User profile = await users.get(
    userId: '[USER_ID]',
);
```

Upload File:

```dart
Storage storage = Storage(client);

InputFile file = InputFile(path: './path-to-file/image.jpg', filename: 'image.jpg');

storage.createFile(
    bucketId: '[BUCKET_ID]',
    fileId: '[FILE_ID]', // use 'unique()' to automatically generate a unique ID
    file: file,
    permissions: [
      Permission.read(Role.any()),
    ],
)
.then((response) {
    print(response); // File uploaded!
})
.catchError((error) {
    print(error.response);
});
```

All examples and API features are available at the [official Appwrite docs](https://appwrite.io/docs)