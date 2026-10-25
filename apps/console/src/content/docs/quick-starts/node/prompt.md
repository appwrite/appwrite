## Create a Node.js server backend application powered by Appwrite, with a working todo database that can create, seed, and query data

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all time. Don't use NPM if the user uses something else.

## Step 1: Create project in Appwrite Console

- Guide the user to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is their first time using Appwrite, they need to create an account and create their first project.
- Under **Integrate with your server**, add an **API Key** with the following scopes:

| Category | Required scopes   | Purpose                                                 |
| -------- | ----------------- | ------------------------------------------------------- |
| Database | `databases.write` | Allows API key to create, update, and delete databases. |
|          | `tables.write`    | Allows API key to create, update, and delete tables.    |
|          | `columns.write`   | Allows API key to create, update, and delete columns.   |
|          | `rows.read`       | Allows API key to read rows.                            |
|          | `rows.write`      | Allows API key to create, update, and delete rows.      |

- Other scopes are optional.

## Step 2: Create Node.js project

- Create a Node.js CLI application:

```sh
mkdir my-app
cd my-app
npm init
```

## Step 3: Install Appwrite SDK

- Run: `npm install node-appwrite`

## Step 4: Import Appwrite and initialize the client (ask user for details; never assume)

- Ask the user for:
    - Appwrite **Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from Console -> View API Keys)
- Create file: `app.js` with the client initialization:

```js
const sdk = require('node-appwrite');

const client = new sdk.Client();

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');
```

## Step 5: Initialize database

- Add a function to configure a todo table:

```js
const tablesDB = new sdk.TablesDB(client);

var todoDatabase;
var todoTable;

async function prepareDatabase() {
    todoDatabase = await tablesDB.create({
        databaseId: sdk.ID.unique(),
        name: 'TodosDB'
    });

    todoTable = await tablesDB.createTable({
        databaseId: todoDatabase.$id,
        tableId: sdk.ID.unique(),
        name: 'Todos'
    });

    await tablesDB.createVarcharColumn({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        key: 'title',
        size: 255,
        required: true
    });

    await tablesDB.createTextColumn({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        key: 'description',
        required: false,
        xdefault: 'This is a test description'
    });

    await tablesDB.createBooleanColumn({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        key: 'isComplete',
        required: true
    });
}
```

## Step 6: Add rows (seed database)

- Add a function to add mock data into the table:

```js
async function seedDatabase() {
    var testTodo1 = {
        title: 'Buy apples',
        description: 'At least 2KGs',
        isComplete: true
    };

    var testTodo2 = {
        title: 'Wash the apples',
        isComplete: true
    };

    var testTodo3 = {
        title: 'Cut the apples',
        description: "Don't forget to pack them in a box",
        isComplete: false
    };

    await tablesDB.createRow({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        rowId: sdk.ID.unique(),
        data: testTodo1
    });
    await tablesDB.createRow({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        rowId: sdk.ID.unique(),
        data: testTodo2
    });
    await tablesDB.createRow({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        rowId: sdk.ID.unique(),
        data: testTodo3
    });
}
```

## Step 7: Retrieve rows

- Add functions to retrieve the mock todo data with queries:

```js
const { Query } = require('node-appwrite');

async function getTodos() {
    // Retrieve rows (default limit is 25)
    var todos = await tablesDB.listRows({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id
    });

    console.log('Todos:');
    todos.rows.forEach((todo) => {
        console.log(
            `Title: ${todo.title}\nDescription: ${todo.description}\nIs Todo Complete: ${todo.isComplete}\n\n`
        );
    });
}

async function getCompletedTodos() {
    // Use queries to filter completed todos with pagination
    var todos = await tablesDB.listRows({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        queries: [Query.equal('isComplete', true), Query.orderDesc('$createdAt'), Query.limit(5)]
    });

    console.log('Completed todos (limited to 5):');
    todos.rows.forEach((todo) => {
        console.log(
            `Title: ${todo.title}\nDescription: ${todo.description}\nIs Todo Complete: ${todo.isComplete}\n\n`
        );
    });
}

async function getIncompleteTodos() {
    // Query for incomplete todos
    var todos = await tablesDB.listRows({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        queries: [Query.equal('isComplete', false), Query.orderAsc('title')]
    });

    console.log('Incomplete todos (ordered by title):');
    todos.rows.forEach((todo) => {
        console.log(
            `Title: ${todo.title}\nDescription: ${todo.description}\nIs Todo Complete: ${todo.isComplete}\n\n`
        );
    });
}

async function runAllTasks() {
    await prepareDatabase();
    await seedDatabase();
    await getTodos();
    await getCompletedTodos();
    await getIncompleteTodos();
}
runAllTasks();
```

## Step 8: Optional: Type safety with TypeScript

- For better type safety in TypeScript projects, define interfaces and use generics:

```ts
interface Todo {
    title: string;
    description: string;
    isComplete: boolean;
}

import { Client, TablesDB } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

// Type-safe database operations
async function getTodos() {
    const todos = await tablesDB.listRows<Todo>({
        databaseId: '<DATABASE_ID>',
        tableId: '<TABLE_ID>'
    });

    todos.rows.forEach((todo) => {
        console.log(`Title: ${todo.title} - Complete: ${todo.isComplete}`);
    });
}
```

- Optionally use the Appwrite CLI to generate TypeScript interfaces automatically: `appwrite types ./types`

## Step 9: Run and test

- Run: `node app.js`
- View the response in your console
- The output should show:
    - All todos
    - Completed todos (limited to 5)
    - Incomplete todos (ordered by title)
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to `app.js` and Console settings.

## Deliverables

- A running Node.js CLI app with working Appwrite database (create database, seed data, query rows)
- Files created/updated: `package.json` (deps), `app.js`
