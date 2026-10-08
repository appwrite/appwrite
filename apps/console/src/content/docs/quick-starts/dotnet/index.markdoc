---
layout: article
title: Start with .NET
description: Learn to get started with server integrations with Appwrite .NET SDK.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first .NET project powered by Appwrite.
{% section #step-1 step=1 title="Create project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console).

If this is your first time using Appwrite, create an account and create your first project.

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

Then, under **Integrate with your server**, add an **API Key** with the following scopes.

{% only_dark %}
![Server integrations](/images/docs/quick-starts/dark/integrate-server.avif)
{% /only_dark %}
{% only_light %}
![Server integrations](/images/docs/quick-starts/integrate-server.avif)
{% /only_light %}
| Category  {% width=120 %} | Required scopes       | Purpose |
|-----------|-----------------------|---------|
| Database  | `databases.write`     | Allows API key to create, update, and delete [databases](/docs/products/databases/databases). |
|           | `tables.write`   | Allows API key to create, update, and delete [tables](/docs/products/databases/tables). |
|           | `columns.write`    | Allows API key to create, update, and delete [columns](/docs/products/databases/tables#columns). |
|           | `rows.read`      | Allows API key to read [rows](/docs/products/databases/rows). |
|           | `rows.write`     | Allows API key to create, update, and delete [rows](/docs/products/databases/rows). |

Other scopes are optional.

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

{% /section %}
{% section #step-2 step=2 title="Create .NET project" %}
Create a .NET CLI application.

```sh
dotnet new console -o MyApp
cd MyApp
```

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the .NET Appwrite SDK.

```sh
dotnet add package Appwrite --version 0.13.0
```
{% /section %}
{% section #step-4 step=4 title="Import Appwrite" %}

Find your project ID in the **Settings** page. Also, click on the **View API Keys** button to find the API key that was created earlier. 

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Open the file `Program.cs` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

```csharp
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

var client = new Client();

client
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");
```

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

```csharp
var tablesDB = new TablesDB(client);

Database todoDatabase;
Table todoTable;

todoDatabase = await tablesDB.Create(
    databaseId: ID.Unique(),
    name: "TodosDB"
);

todoTable = await tablesDB.CreateTable(
    databaseId: todoDatabase.Id,
    tableId: ID.Unique(),
    name: "Todos"
);

await tablesDB.CreateVarcharColumn(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    key: "title",
    size: 255,
    required: true
);

await tablesDB.CreateTextColumn(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    key: "description",
    required: false,
    xdefault: "This is a test description"
);

await tablesDB.CreateBooleanColumn(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    key: "isComplete",
    required: true
);
```

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.
```csharp
var testTodo1 = new Dictionary<string, object>()
{
    {"title", "Buy apples"},
    {"description", "At least 2KGs"},
    {"isComplete", true}
};

var testTodo2 = new Dictionary<string, object>()
{
    {"title", "Wash the apples"},
    {"isComplete", true}
};

var testTodo3 = new Dictionary<string, object>()
{
    {"title", "Cut the apples"},
    {"description", "Don't forget to pack them in a box"},
    {"isComplete", false}
};

await tablesDB.CreateRow(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    rowId: ID.Unique(),
    data: testTodo1
);

await tablesDB.CreateRow(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    rowId: ID.Unique(),
    data: testTodo2
);

await tablesDB.CreateRow(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id,
    rowId: ID.Unique(),
    data: testTodo3
);
```

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data.

```csharp
var todos = await tablesDB.ListRows(
    databaseId: todoDatabase.Id,
    tableId: todoTable.Id
);

foreach (var todo in todos.Rows)
{
    Console.WriteLine($"Title: {todo.Data["title"]}\nDescription: {todo.Data["description"]}\nIs Todo Complete: {todo.Data["isComplete"]}\n\n");
}
```

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with `dotnet run` and view the response in your console.

{% /section %}