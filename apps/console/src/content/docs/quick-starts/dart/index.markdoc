---
layout: article
title: Start with Dart
description: Build Flutter apps with Appwrite and learn how to use our powerful backend to add authentication, user management, file storage, and more.
---
Learn how to setup your first Dart project powered by Appwrite.
{% section #step-1 step=1 title="Create project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console).

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

If this is your first time using Appwrite, create an account and create your first project.


Then, under **Integrate with your server**, add an **API Key** with the following scopes.

| Category  {% width=120 %} | Required scopes       | Purpose |
|-----------|-----------------------|---------|
| Database  | `databases.write`     | Allows API key to create, update, and delete [databases](/docs/products/databases/databases). |
|           | `tables.write`   | Allows API key to create, update, and delete [tables](/docs/products/databases/tables). |
|           | `columns.write`    | Allows API key to create, update, and delete [columns](/docs/products/databases/tables#columns). |
|           | `rows.read`      | Allows API key to read [rows](/docs/products/databases/rows). |
|           | `rows.write`     | Allows API key to create, update, and delete [rows](/docs/products/databases/rows). |

Other scopes are optional.

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/integrate-server.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/integrate-server.avif)
{% /only_light %}

{% /section %}
{% section #step-2 step=2 title="Create Dart project" %}
Create a Dart CLI application.

```sh
dart create -t console my_app
cd my_app
```

After entering the project directory, remove the `lib/` and `test/` directories.

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the Dart Appwrite SDK.

```sh
dart pub add dart_appwrite:16.0.0
```
{% /section %}
{% section #step-4 step=4 title="Import Appwrite" %}

Find your project ID in the **Settings** page.

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

 Also, click on the **View API Keys** button to find the API key that was created earlier. 

Open `bin/my_app.dart` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

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

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

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

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.
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

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data.
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

Finally, revisit the `main()` function and call the functions created in previous steps.
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

{% /section %}
{% section #step-8 step=8 title="All set" %}

Run your project with `dart run bin/my_app.dart` and view the response in your console.

{% /section %}
