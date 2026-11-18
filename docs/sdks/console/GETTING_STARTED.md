## Getting started

### Add a Web platform

In the Appwrite Console, open your project and under **Add a platform** choose **Web app**. Enter the hostname your app runs on: `localhost` for local development, and your production domain once you deploy. Appwrite only accepts browser requests from hostnames registered as platforms, so a missing hostname shows up as a CORS error.

### Initialize the client

Copy your endpoint and project ID from your project's **Settings** page.

```js
import { Client } from '@appwrite.io/console';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');
```

For a self-hosted Appwrite, use `https://<HOSTNAME>/v1` as the endpoint.

### Make your first request

Create a service from the client and call any of its methods. Every method takes a single object of named parameters and returns a promise. See the [API reference](https://appwrite.io/docs/references) for every service and method.

```js
import { Account, ID } from '@appwrite.io/console';

const account = new Account(client);

const user = await account.create({
    userId: ID.unique(),
    email: 'email@example.com',
    password: 'password',
    name: "Walter O'Brien",
});

console.log(user);
```

### Sign in with OAuth

Enable the provider in the Console under **Auth > Settings**, then redirect the user to it. The `success` and `failure` URLs must be on a hostname you registered as a platform.

```js
import { Account, OAuthProvider } from '@appwrite.io/console';

const account = new Account(client);

account.createOAuth2Session({
    provider: OAuthProvider.Github,
    success: 'https://example.com/success',
    failure: 'https://example.com/failure',
});
```

### Type safety with models

Methods that return rows, such as `listRows` and `getRow`, accept a type parameter for your own row shape:

```ts
import { TablesDB, type Models } from '@appwrite.io/console';

interface Book extends Models.Row {
    name: string;
    author: string;
    releaseYear?: string;
    genre?: string[];
    isCheckedOut: boolean;
}

const tablesDB = new TablesDB(client);

const result = await tablesDB.listRows<Book>({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
});

for (const book of result.rows) {
    console.log(`${book.name} by ${book.author}`);
}
```

In JavaScript, the same types are available through JSDoc:

```js
import { TablesDB } from '@appwrite.io/console';

const tablesDB = new TablesDB(client);

/**
 * @typedef {import('@appwrite.io/console').Models.Row & {
 *     name: string,
 *     author: string,
 *     isCheckedOut: boolean,
 * }} Book
 */

/** @type {import('@appwrite.io/console').Models.RowList<Book>} */
const result = await tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
});
```

The [Appwrite CLI](https://github.com/appwrite/sdk-for-cli) can generate these interfaces from your database schema with `appwrite types`. See [type generation](https://appwrite.io/docs/products/databases/tablesdb/type-generation).

### Error handling

Failed requests throw an `AppwriteException` with `message`, `code`, `type` and `response` properties.

```js
import { AppwriteException } from '@appwrite.io/console';

try {
    const user = await account.get();
    console.log(user);
} catch (error) {
    if (error instanceof AppwriteException) {
        console.error(error.code, error.type, error.message);
    }
}
```

The [response codes](https://appwrite.io/docs/apis/response-codes) page lists every error type.

### Learn more

- [Web quick start](https://appwrite.io/docs/quick-starts/web)
- [Appwrite docs](https://appwrite.io/docs)
- [Discord community](https://appwrite.io/discord)
