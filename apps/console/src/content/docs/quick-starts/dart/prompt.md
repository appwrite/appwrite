## Create a Dart server-side CLI application with Appwrite

Create a Dart server-side CLI application with Appwrite that demonstrates database operations (create database, table, columns, and CRUD operations on rows).

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

## Step 1: Create Dart project

- Run: `dart create -t console my_app`
- Change dir: `cd my_app`
- Remove the `lib/` and `test/` directories after entering the project directory.

## Step 2: Install Appwrite SDK

- Run: `dart pub add dart_appwrite:16.0.0`

## Step 3: Create Appwrite project and API key

Ask the user for details; never assume.

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from Console -> View API Keys)
- The API key must have the following scopes:
    - Database: `databases.write`, `tables.write`, `columns.write`, `rows.read`, `rows.write`
- If the user doesn't know, guide them to Appwrite Console to create a project and API key with the required scopes.

## Step 4: Initialize Appwrite Client

- Open `bin/my_app.dart` and initialize the Appwrite Client.
- Replace `<REGION>`, `<PROJECT_ID>`, and `<YOUR_API_KEY>` with the user's values if provided, else leave placeholders and ask the user to provide them.
- Create/update file `bin/my_app.dart` with key snippet:

```dart
import 'package:dart_appwrite/dart_appwrite.dart';

var client = Client();

Future<void> main() async {
  client
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");
}
```

## Step 5: Initialize database

- Add a function to configure a todo table with columns:

```dart
var tablesDB;
var todoDatabase;
var todoTable;

Future<void> prepareDatabase() async {
  tablesDB = TablesDB(client);

  todoDatabase = await tablesDB.create(
    databaseId: ID.unique(),
    name: 'TodosDB'
  );

  todoTable = await tablesDB.createTable(
    databaseId: todoDatabase.$id,
    tableId: ID.unique(),
    name: 'Todos'
  );

  await tablesDB.createVarcharColumn(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id,
    key: 'title',
    size: 255,
    xrequired: true
  );

  await tablesDB.createTextColumn(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id,
    key: 'description',
    xrequired: false,
    xdefault: 'This is a test description'
  );

  await tablesDB.createBooleanColumn(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id,
    key: 'isComplete',
    xrequired: true
  );
}
```

## Step 6: Add rows

- Create a function to add mock todo data:

```dart
Future<void> seedDatabase() async {
  var testTodo1 = {
    'title': 'Buy apples',
    'description': 'At least 2KGs',
    'isComplete': true
  };

  var testTodo2 = {
    'title': 'Wash the apples',
    'isComplete': true
  };

  var testTodo3 = {
    'title': 'Cut the apples',
    'description': 'Don\'t forget to pack them in a box',
    'isComplete': false
  };

  await tablesDB.createRow(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id,
    rowId: ID.unique(),
    data: testTodo1
  );

  await tablesDB.createRow(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id,
    rowId: ID.unique(),
    data: testTodo2
  );

  await tablesDB.createRow(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id,
    rowId: ID.unique(),
    data: testTodo3
  );
}
```

## Step 7: Retrieve rows

- Create a function to retrieve and display the todo data:

```dart
Future<void> getTodos() async {
  var todos = await tablesDB.listRows(
    databaseId: todoDatabase.$id,
    tableId: todoTable.$id
  );

  todos.rows.forEach((todo) {
    print('Title: ${todo.data['title']}\nDescription: ${todo.data['description']}\nIs Todo Complete: ${todo.data['isComplete']}\n\n');
  });
}
```

## Step 8: Update main function

- Update the `main()` function to call all the functions:

```dart
Future<void> main() async {
  client
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

    await prepareDatabase();
    await Future.delayed(const Duration(seconds: 1));
    await seedDatabase();
    await getTodos();
}
```

## Step 9: Run and test

- Run: `dart run bin/my_app.dart`
- View the response in your console showing the todo items.
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to the configuration.

## Deliverables

- A running Dart CLI application with working Appwrite database operations
- Files created/updated: `pubspec.yaml` (deps), `bin/my_app.dart`
