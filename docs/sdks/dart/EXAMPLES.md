@@ -1,62 +0,0 @@
# Examples

Init your Appwrite client:

```dart
Client client = Client();

client
  .setProject('<YOUR_PROJECT_ID>')
  .setKey('<YOUR_API_KEY>');
```

Create a new user:

```dart
Users users = Users(client);

User result = await users.create(
  userId: ID.unique(),
  email: "email@example.com",
  phone: "+123456789",
  password: "password",
  name: "Walter O'Brien"
);
```

Get user:

```dart
Users users = Users(client);

User user = await users.get(
  userId: '[USER_ID]',
);
```

Upload File:

```dart
Storage storage = Storage(client);

InputFile input = InputFile(
  path: './path-to-file/image.jpg',
  filename: 'image.jpg',
);

File file = await storage.createFile(
  bucketId: '<YOUR_BUCKET_ID>',
  fileId: ID.unique(),
  file: input,
  permissions: [
    Permission.read(Role.any()),
  ],
);
```

All examples and API features are available at the [official Appwrite docs](https://appwrite.io/docs)
