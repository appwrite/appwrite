---
layout: article
title: Start with PHP
description: Dive into our step-by-step guide on integrating Appwrite with your PHP server backend application. Get your backend up and running quickly with this tutorial.
difficulty: beginner
readtime: 5
back: /docs/quick-starts
---
Learn how to setup your first PHP project powered by Appwrite.
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
![Create project screen](/images/docs/quick-starts/dark/integrate-server.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/integrate-server.avif)
{% /only_light %}

| Category  {% width=120 %} | Required scopes       | Purpose |
|-----------|-----------------------|---------|
| Database  | `databases.write`     | Allows API key to create, update, and delete [databases](/docs/products/databases/databases). |
|           | `tables.write`   | Allows API key to create, update, and delete [tables](/docs/products/databases/tables). |
|           | `columns.write`    | Allows API key to create, update, and delete [columns](/docs/products/databases/tables#columns). |
|           | `rows.read`      | Allows API key to read [rows](/docs/products/databases/rows). |
|           | `rows.write`     | Allows API key to create, update, and delete [rows](/docs/products/databases/rows). |

Other scopes are optional.

{% /section %}
{% section #step-2 step=2 title="Create PHP project" %}
Create a PHP CLI application.

```sh
mkdir my-app
cd my-app
composer init
```

{% /section %}
{% section #step-3 step=3 title="Install Appwrite" %}

Install the PHP Appwrite SDK.

```sh
composer require appwrite/appwrite:15.0.0
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

Create a new file `index.php` and initialize the Appwrite Client. Replace `<PROJECT_ID>` with your project ID and `<YOUR_API_KEY>` with your API key.

```php
<?php
require_once 'vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\TablesDB;
use Appwrite\ID;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');
```

{% /section %}
{% section #step-5 step=5 title="Initialize database" %}

Once the Appwrite Client is initialized, create a function to configure a todo table.

```php
$tablesDB = new TablesDB($client);

function prepareDatabase($tablesDB) {
    $todoDatabase = $tablesDB->create(
        databaseId: ID::unique(), 
        name: 'TodosDB'
    );

    $todoTable = $tablesDB->createTable(
        databaseId: $todoDatabase['$id'], 
        tableId: ID::unique(),
        name: 'Todos'
    );

    $tablesDB->createVarcharColumn(
        databaseId: $todoDatabase['$id'],
        tableId: $todoTable['$id'],
        key: 'title',
        size: 255,
        required: true
    );

    $tablesDB->createTextColumn(
        databaseId: $todoDatabase['$id'],
        tableId: $todoTable['$id'],
        key: 'description',
        required: false,
    );

    $tablesDB->createBooleanColumn(
        databaseId: $todoDatabase['$id'], 
        tableId: $todoTable['$id'], 
        key: 'isComplete', 
        required: true
    );

    return [$todoDatabase, $todoTable];
}
```

{% /section %}
{% section #step-6 step=6 title="Add rows" %}
Create a function to add some mock data into your new table.

```php
function seedDatabase($tablesDB, $todoDatabase, $todoTable) {
    $testTodo1 = [
        'title' => 'Buy apples',
        'description' => 'At least 2KGs',
        'isComplete' => true
    ];

    $testTodo2 = [
        'title' => 'Wash the apples',
        'isComplete' => true
    ];

    $testTodo3 = [
        'title' => 'Cut the apples',
        'description' => 'Don\'t forget to pack them in a box',
        'isComplete' => false
    ];

    $tablesDB->createRow(
        $todoDatabase['$id'], 
        $todoTable['$id'], 
        ID::unique(), 
        $testTodo1
    );
    
    $tablesDB->createRow(
        $todoDatabase['$id'], 
        $todoTable['$id'], 
        ID::unique(), 
        $testTodo2
    );
    
    $tablesDB->createRow(
        $todoDatabase['$id'], 
        $todoTable['$id'], 
        ID::unique(), 
        $testTodo3
    );
}
```

{% /section %}
{% section #step-7 step=7 title="Retrieve rows" %}

Create a function to retrieve the mock todo data and a function to execute the requests in order.
Run the functions to by calling `runAllTasks();`.

```php
use Appwrite\Query;

function getTodos($tablesDB, $todoDatabase, $todoTable) {
    // Retrieve rows (default limit is 25)
    $todos = $tablesDB->listRows(
        $todoDatabase['$id'], 
        $todoTable['$id']
    );

    echo "Todos:\n";
    foreach ($todos['rows'] as $todo) {
        echo "Title: {$todo['title']}\n" .
            "Description: {$todo['description']}\n" .
            "Is Todo Complete: {$todo['isComplete']}\n\n";
   }
}

function getCompletedTodos($tablesDB, $todoDatabase, $todoTable) {
    // Use queries to filter completed todos with pagination
    $todos = $tablesDB->listRows(
        $todoDatabase['$id'],
        $todoTable['$id'],
        [
            Query::equal('isComplete', true),
            Query::orderDesc('$createdAt'),
            Query::limit(5)
        ]
    );

    echo "Completed todos (limited to 5):\n";
    foreach ($todos['rows'] as $todo) {
        echo "Title: {$todo['title']}\n" .
            "Description: {$todo['description']}\n" .
            "Is Todo Complete: {$todo['isComplete']}\n\n";
   }
}

function getIncompleteTodos($tablesDB, $todoDatabase, $todoTable) {
    // Query for incomplete todos
    $todos = $tablesDB->listRows(
        $todoDatabase['$id'],
        $todoTable['$id'],
        [
            Query::equal('isComplete', false),
            Query::orderAsc('title')
        ]
    );

    echo "Incomplete todos (ordered by title):\n";
    foreach ($todos['rows'] as $todo) {
        echo "Title: {$todo['title']}\n" .
            "Description: {$todo['description']}\n" .
            "Is Todo Complete: {$todo['isComplete']}\n\n";
   }
}

function runAllTasks($tablesDB) {
    [$todoDatabase, $todoTable] = prepareDatabase($tablesDB);
    seedDatabase($tablesDB, $todoDatabase, $todoTable);
    getTodos($tablesDB, $todoDatabase, $todoTable);
    getCompletedTodos($tablesDB, $todoDatabase, $todoTable);
    getIncompleteTodos($tablesDB, $todoDatabase, $todoTable);
}

runAllTasks($tablesDB);
```

{% /section %}

{% section #step-8 step=8 title="All set" %}

Run your project with `php src/index.php` and view the response in your console.

{% /section %}