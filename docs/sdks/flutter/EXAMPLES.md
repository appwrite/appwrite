# Examples

Initialize the client:

```dart
import 'package:appwrite/appwrite.dart';
import 'package:appwrite/models.dart' as models;

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>');
```

Create a user and sign in:

```dart
Account account = Account(client);

models.User user = await account.create(
  userId: ID.unique(),
  email: 'email@example.com',
  password: 'password',
  name: "Walter O'Brien",
);

models.Session session = await account.createEmailPasswordSession(
  email: 'email@example.com',
  password: 'password',
);
```

Fetch the signed-in user:

```dart
models.User profile = await account.get();
```

List rows:

```dart
TablesDB tablesDB = TablesDB(client);

models.RowList rows = await tablesDB.listRows(
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  queries: [
    Query.equal('published', true),
    Query.limit(10),
  ],
);
```

Upload a file. On the web there is no file system path, so upload the picked file's bytes instead:

```dart
import 'package:flutter/foundation.dart';

Storage storage = Storage(client);

InputFile input = kIsWeb
    ? InputFile.fromBytes(bytes: pickedFile.bytes!, filename: 'image.jpg')
    : InputFile.fromPath(path: './path-to-file/image.jpg');

models.File file = await storage.createFile(
  bucketId: '<BUCKET_ID>',
  fileId: ID.unique(),
  file: input,
  permissions: [
    Permission.read(Role.any()),
  ],
);
```

Subscribe to realtime events:

```dart
Realtime realtime = Realtime(client);

RealtimeSubscription subscription = realtime.subscribe(['account']);

subscription.stream.listen((event) {
  print(event.payload);
});
```

Keep the subscription while you need events, and call `subscription.close()` when you stop listening, for example in your widget's `dispose()`.

Handle errors:

```dart
try {
  models.User user = await account.get();
} on AppwriteException catch (e) {
  print('${e.code} ${e.type}: ${e.message}');
}
```

All examples and API features are available in the [official Appwrite docs](https://appwrite.io/docs).
