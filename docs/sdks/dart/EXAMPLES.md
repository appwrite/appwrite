# Examples

Initialize the client:

```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/models.dart' as models;

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');
```

Create a user:

```dart
Users users = Users(client);

models.User user = await users.create(
  userId: ID.unique(),
  email: 'email@example.com',
  password: 'password',
  name: "Walter O'Brien",
);
```

Create a row:

```dart
TablesDB tablesDB = TablesDB(client);

models.Row row = await tablesDB.createRow(
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  rowId: ID.unique(),
  data: {
    'title': 'Hello, world',
    'published': true,
  },
  permissions: [
    Permission.read(Role.any()),
  ],
);
```

List rows:

```dart
models.RowList rows = await tablesDB.listRows(
  databaseId: '<DATABASE_ID>',
  tableId: '<TABLE_ID>',
  queries: [
    Query.equal('published', true),
    Query.orderDesc('\$createdAt'),
    Query.limit(10),
  ],
);
```

Upload a file:

```dart
Storage storage = Storage(client);

models.File file = await storage.createFile(
  bucketId: '<BUCKET_ID>',
  fileId: ID.unique(),
  file: InputFile.fromPath(path: './path-to-file/image.jpg'),
  permissions: [
    Permission.read(Role.any()),
  ],
);
```

Handle errors:

```dart
try {
  models.User user = await users.get(userId: '<USER_ID>');
} on AppwriteException catch (e) {
  print('${e.code} ${e.type}: ${e.message}');
}
```

All examples and API features are available in the [official Appwrite docs](https://appwrite.io/docs).
