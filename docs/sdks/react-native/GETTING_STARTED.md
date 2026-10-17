## Getting started

### Add your platform

In the Appwrite Console, open your project and under **Add a platform** add an **Android app**, an **Apple app**, or both.

- **Apple:** enter your app's name and bundle identifier. In Expo projects it is `expo.ios.bundleIdentifier` in `app.json`; otherwise find it in the **General** tab of your app target in Xcode.
- **Android:** enter your app's name and package name. In Expo projects it is `expo.android.package` in `app.json`; otherwise it is the `applicationId` in your app-level `build.gradle`.

### Load the URL polyfill

Import `react-native-url-polyfill` once in your app's entry file (for example `index.js` or your root `_layout.tsx`), before any SDK code runs:

```js
import 'react-native-url-polyfill/auto';
```

### Initialize the client

Copy your endpoint and project ID from your project's **Settings** page, and pass the bundle identifier or package name you registered to `setPlatform`.

```js
import { Client } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setPlatform('com.example.myapp');
```

For a self-hosted Appwrite, use `https://<HOSTNAME>/v1` as the endpoint.

### Make your first request

Create a service from the client and call any of its methods. Every method takes a single object of named parameters and returns a promise. See the [API reference](https://appwrite.io/docs/references) for every service and method.

```js
import { Account, ID } from 'react-native-appwrite';

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

Enable the provider in the Console under **Auth > Settings**. In Expo, set the app's URL scheme to `appwrite-callback-<PROJECT_ID>` in `app.json`:

```json
{
    "expo": {
        "scheme": "appwrite-callback-<PROJECT_ID>"
    }
}
```

Then create a login URL with `createOAuth2Token`, open it with `expo-web-browser`, and exchange the returned secret for a session:

```js
import { makeRedirectUri } from 'expo-auth-session';
import * as WebBrowser from 'expo-web-browser';
import { OAuthProvider } from 'react-native-appwrite';

const deepLink = new URL(makeRedirectUri({ preferLocalhost: true }));
const scheme = `${deepLink.protocol}//`;

const loginUrl = account.createOAuth2Token({
    provider: OAuthProvider.Github,
    success: `${deepLink}`,
    failure: `${deepLink}`,
});

const result = await WebBrowser.openAuthSessionAsync(`${loginUrl}`, scheme);

if (result.type === 'success') {
    const url = new URL(result.url);
    const userId = url.searchParams.get('userId');
    const secret = url.searchParams.get('secret');

    if (userId && secret) {
        await account.createSession({ userId, secret });
    }
}
```

### Type safety with models

Methods that return rows, such as `listRows` and `getRow`, accept a type parameter for your own row shape:

```ts
import { TablesDB, type Models } from 'react-native-appwrite';

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

The [Appwrite CLI](https://github.com/appwrite/sdk-for-cli) can generate these interfaces from your database schema with `appwrite types`. See [type generation](https://appwrite.io/docs/products/databases/tablesdb/type-generation).

### Error handling

Failed requests throw an `AppwriteException` with `message`, `code`, `type` and `response` properties.

```js
import { AppwriteException } from 'react-native-appwrite';

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
