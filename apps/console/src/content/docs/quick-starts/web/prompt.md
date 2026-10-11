## Set up the Appwrite Web SDK in a vanilla JavaScript or TypeScript web app and initialize the client

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

- Respect user's package manager at all times. Don't use NPM if the user uses something else.
- All files must be created in the current working directory (`.`). Never create a subdirectory for the project.

## Step 1: Create project in Appwrite Console

- Guide the user to the Appwrite Console: https://cloud.appwrite.io/console
- If this is their first time using Appwrite, have them create an account and create their first project.
- Under **Add a platform**, add a **Web** app.
- The **Hostname** should be `localhost` or the domain on which they're hosting their web app.
- Optional steps can be skipped.

## Step 2: Install Appwrite SDK

- **Option A** (npm): Run `npm install appwrite`
- **Option B** (CDN): Add a script tag to the HTML file:

```html
<script src="https://cdn.jsdelivr.net/npm/appwrite@17.0.0"></script>
```

## Step 3: Initialize Appwrite client (ask user for details; never assume)

- Before creating files, check the current working directory contents. If the directory contains files that appear unrelated to a development workspace (e.g., personal documents, downloads, random files, photos, or other non-code files), ask the user: 'The current directory contains files that don't appear to be part of a development project. Would you like to proceed here anyway, or create a subdirectory for the project?' If the directory is empty OR contains an existing project (e.g., `package.json`, `src/`, `node_modules`, config files, or other typical project structure), proceed with integration without asking.
- Ask the user for:
    - Appwrite Cloud Region (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Create files in the current working directory (`.`) - do NOT use `cd` to switch directories.
- If using npm, create an `appwrite.js` (or `appwrite.ts`) file with:

```js
import { Client, Account } from 'appwrite';

export const client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account = new Account(client);
export { ID } from 'appwrite';
```

- If using CDN, use the global Appwrite object:

```js
const client = new Appwrite.Client();

client.setEndpoint('https://cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

const account = new Appwrite.Account(client);
const tablesDB = new Appwrite.TablesDB(client);
```

## Step 4: TypeScript setup (optional, if user prefers TypeScript)

- Import TypeScript models from the Appwrite SDK:

```ts
// appwrite.ts

import { Client, TablesDB, Account } from 'appwrite';
// Import type models for Appwrite
import { type Models } from 'appwrite';

const client: Client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account: Account = new Account(client);
export const tablesDB: TablesDB = new TablesDB(client);

// You then use the imported type definitions like this
const authUser: Models.Session = await account.createEmailPasswordSession({
    email,
    password
});
```

## Step 5: Extending TypeScript models (optional)

- When fetching rows from a table, define the expected structure:

```ts
interface Idea extends Models.Row {
    title: string;
    description: string;
    userId: string;
}
```

- Use this interface when fetching rows:

```ts
const response = await tablesDB.listRows({
    databaseId: ideasDatabaseId,
    tableId: ideasTableId,
    queries: [Query.orderDesc('$createdAt'), Query.limit(queryLimit)]
});
const ideas = response.rows as Idea[];
```

## Step 6: Type-safe database operations (optional)

- For better type safety, define interfaces and use generics:

```ts
interface User {
    name: string;
    email: string;
    isVerified: boolean;
}

import { Client, TablesDB } from 'appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const databases = new TablesDB(client);

// Type-safe database operations
try {
    const users = await databases.listRows<User>({
        databaseId: '[DATABASE_ID]',
        tableId: '[TABLE_ID]'
    });

    users.rows.forEach((user) => {
        console.log(`User: ${user.name} (${user.email})`);
    });
} catch (error) {
    console.log(error);
}
```

- _Tip: Use the Appwrite CLI to generate TypeScript interfaces automatically:_ `appwrite types ./types`

## Step 7: Next steps

- The Appwrite SDK works with popular Web frameworks. Guide the user to framework-specific quickstarts:
    - **Next.js**: `/docs/quick-starts/nextjs`
    - **React**: `/docs/quick-starts/react`
    - **Vue.js**: `/docs/quick-starts/vue`
    - **Nuxt**: `/docs/quick-starts/nuxt`
    - **SvelteKit**: `/docs/quick-starts/sveltekit`
    - **Angular**: `/docs/quick-starts/angular`
- Or guide them to tutorials for building an idea tracker app:
    - **React**: `/docs/tutorials/react`
    - **Vue.js**: `/docs/tutorials/vue`
    - **Nuxt**: `/docs/tutorials/nuxt`
    - **SvelteKit**: `/docs/tutorials/sveltekit`

## Deliverables

- Appwrite Web SDK installed (via npm or CDN)
- Appwrite client initialized with endpoint and **Project ID**
- Files created/updated: `appwrite.js` (or `appwrite.ts`), `package.json` (if using npm)
