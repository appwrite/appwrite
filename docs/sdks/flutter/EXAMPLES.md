# Examples

Init your Appwrite client:

```dart
Client client = Client();

client
    .setEndpoint('https://localhost:9521/v1') // Your Appwrite Endpoint
    .setProject('5e8cf4f46b5e8') // Your project ID
    .setSelfSigned() // Remove in production
;
```

Create a new user and session:

```dart
Account account = Account(client);

final user = await account.create(userId: ID.unique(), email: "email@example.com", password: "password", name: "Walter O'Brien");
 
final session = await account.createEmailPasswordSession(email: 'me@appwrite.io', password: 'password');

```

Fetch user profile:

```dart
Account account = Account(client);

final profile = await account.get();
```

Upload File:

```dart
Storage storage = Storage(client);

late InputFile file;

if(kIsWeb) {
    file = InputFile.fromBytes(bytes: pickedFile.bytes!, filename: 'image.jpg');
} else {
    file = InputFile.fromPath(path: './path-to-file/image.jpg', filename: 'image.jpg');
}

storage.createFile(
    bucketId: '[BUCKET_ID]',
    fileId: ID.unique(),
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
