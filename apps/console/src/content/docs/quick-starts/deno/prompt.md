## Create a Deno server backend application powered by Appwrite that creates a todo database, adds sample data, and retrieves it

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

_Note: The dedicated Deno SDK has been deprecated. Use the Node.js SDK directly through npm specifiers, thanks to Deno's excellent Node.js compatibility._

## Step 1: Create project in Appwrite Console

- Guide the user to head to the **Appwrite Console** (https://cloud.appwrite.io/console).
- If this is their first time, have them create an account and create their first project.
- Under **Integrate with your server**, add an **API Key** with these scopes:
    - Database: `databases.write`, `tables.write`, `columns.write`, `rows.read`, `rows.write`
- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API Key** (from View API Keys button in Settings)
- If the user doesn't know, guide them to the **Appwrite Console** to copy these. Do not attempt to infer or access their project.

## Step 2: Create Deno project

- If you already have a Deno project open, stay in it and use it.
- Otherwise, create a new Deno CLI application:

```sh
mkdir my-app
cd my-app
echo "console.log('Hello, Deno!');" > mod.ts
```

## Step 3: Install Appwrite SDK

- Use npm specifiers at the top of your file to import the SDK:

```ts
// import all as sdk
import * as sdk from "npm:node-appwrite";

// import only what you need
import { Client, ... other imports } from "npm:node-appwrite";
```

## Step 4: Initialize Appwrite Client

- Open `mod.ts` and initialize the Appwrite Client with the user's credentials.
- Replace placeholders with the actual values provided by the user:

```ts
import { Client, ID, TablesDB, Models } from 'npm:node-appwrite';

const client: Client = new Client();

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');
```

## Step 5: Initialize database

- Create a function to configure a todo table:

```ts
const tablesDB: TablesDB = new TablesDB(client);

var todoDatabase: Models.Database;
var todoTable: Models.Table;

interface Todo {
    title: string;
    description: string;
    isComplete?: boolean;
}

async function prepareDatabase(): Promise<void> {
    todoDatabase = await tablesDB.create({
        databaseId: ID.unique(),
        name: 'TodosDB'
    });

    todoTable = await tablesDB.createTable({
        databaseId: todoDatabase.$id,
        tableId: ID.unique(),
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

## Step 6: Add rows

- Create a function to add mock data into the new table:

```ts
async function seedDatabase(): Promise<void> {
    const testTodo1: Todo = {
        title: 'Buy apples',
        description: 'At least 2KGs',
        isComplete: true
    };

    const testTodo2: Todo = {
        title: 'Wash the apples',
        isComplete: true
    };

    const testTodo3: Todo = {
        title: 'Cut the apples',
        description: "Don't forget to pack them in a box",
        isComplete: false
    };

    await tablesDB.createRow({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        rowId: ID.unique(),
        data: testTodo1
    });
    await tablesDB.createRow({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        rowId: ID.unique(),
        data: testTodo2
    });
    await tablesDB.createRow({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id,
        rowId: ID.unique(),
        data: testTodo3
    });
}
```

## Step 7: Retrieve rows

- Create a function to retrieve the mock todo data and a function to execute the requests in order:

```ts
async function getTodos(): Promise<void> {
    const todos = await tablesDB.listRows({
        databaseId: todoDatabase.$id,
        tableId: todoTable.$id
    });

    todos.rows.forEach((todo: Todo) => {
        console.log(
            `Title: ${todo.title}\nDescription: ${todo.description}\nIs Todo Complete: ${todo.isComplete}\n\n`
        );
    });
}

async function runAllTasks(): Promise<void> {
    await prepareDatabase();
    await seedDatabase();
    await getTodos();
}
runAllTasks();
```

## Step 8: Run and test

- Run the project: `deno mod.ts`
- View the response in your console.
- The output should display the three todo items with their titles, descriptions, and completion status.
- Surface any Appwrite errors (invalid project, endpoint, API key) and fix by guiding updates to `mod.ts` and Console settings.

## Deliverables

- A running Deno server application that creates a todo database, seeds it with sample data, and retrieves/displays the todos.
- Files created/updated: `mod.ts` (with Appwrite client initialization, database setup, seeding, and retrieval functions)
