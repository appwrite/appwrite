## Create a PHP CLI application that connects to Appwrite and performs database operations

Create a PHP CLI application that connects to Appwrite and performs database operations (create database, table, columns, and rows).

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

## Step 1: Create project in Appwrite Console

- Guide the user to the **Appwrite Console** (https://cloud.appwrite.io/console).
- If this is their first time, have them create an account and project.
- Under **Integrate with your server**, add an **API Key** with the following scopes:

| Category | Required scopes   | Purpose                                                 |
| -------- | ----------------- | ------------------------------------------------------- |
| Database | `databases.write` | Allows API key to create, update, and delete databases. |
|          | `tables.write`    | Allows API key to create, update, and delete tables.    |
|          | `columns.write`   | Allows API key to create, update, and delete columns.   |
|          | `rows.read`       | Allows API key to read rows.                            |
|          | `rows.write`      | Allows API key to create, update, and delete rows.      |

## Step 2: Create PHP project

- Create a PHP CLI application:

```sh
mkdir my-app
cd my-app
composer init
```

## Step 3: Install Appwrite SDK

- Run: `composer require appwrite/appwrite:15.0.0`

## Step 4: Import Appwrite and initialize client (ask user for details; never assume)

- Ask the user for:
    - **Appwrite Cloud Region** (e.g., `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from View API Keys button in Settings)
- Create file: `index.php` with key snippet:

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

## Step 5: Initialize database

- Add a function to configure a todo table:

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

## Step 6: Add rows

- Add a function to insert mock todo data:

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

## Step 7: Retrieve rows

- Add functions to retrieve and display todos with queries:

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

## Step 8: Run and test

- Run: `php src/index.php`
- View the response in the console showing all todos, completed todos, and incomplete todos.
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to `index.php` and Console settings.

## Deliverables

- A running PHP CLI application with working Appwrite database operations
- Files created/updated: `composer.json` (deps), `index.php`
