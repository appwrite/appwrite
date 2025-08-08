## Getting Started

### Init your SDK

Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page and your new API secret Key project API keys section.

```js
const sdk = require('node-appwrite');

let client = new sdk.Client();

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
    .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
;
```

### Make Your First Request

Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```js
let users = new sdk.Users(client);

let promise = users.create(sdk.ID.unique(), "email@example.com", "+123456789", "password", "Walter O'Brien");

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

### Full Example
```js
const sdk = require('node-appwrite');

let client = new sdk.Client();

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
    .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
;

let users = new sdk.Users(client);
let promise = users.create(sdk.ID.unique(), "email@example.com", "+123456789", "password", "Walter O'Brien");

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

### Type Safety with Models

The Appwrite Node SDK provides type safety when working with database documents through generic methods. Methods like `listDocuments`, `getDocument`, and others accept a generic type parameter that allows you to specify your custom model type for full type safety.

**TypeScript:**
```typescript
interface Book {
    name: string;
    author: string;
    releaseYear?: string;
    category?: string;
    genre?: string[];
    isCheckedOut: boolean;
}

const databases = new Databases(client);

try {
    const documents = await databases.listDocuments<Book>(
        'your-database-id',
        'your-collection-id'
    );
    
    documents.documents.forEach(book => {
        console.log(`Book: ${book.name} by ${book.author}`); // Now you have full type safety
    });
} catch (error) {
    console.error('Appwrite error:', error);
}
```

**JavaScript (with JSDoc for type hints):**
```javascript
/**
 * @typedef {Object} Book
 * @property {string} name
 * @property {string} author
 * @property {string} [releaseYear]
 * @property {string} [category]
 * @property {string[]} [genre]
 * @property {boolean} isCheckedOut
 */

const databases = new Databases(client);

try {
    /** @type {Models.DocumentList<Book>} */
    const documents = await databases.listDocuments(
        'your-database-id',
        'your-collection-id'
    );
    
    documents.documents.forEach(book => {
        console.log(`Book: ${book.name} by ${book.author}`); // Type hints available in IDE
    });
} catch (error) {
    console.error('Appwrite error:', error);
}
```

**Tip**: You can use the `appwrite types` command to automatically generate TypeScript interfaces based on your Appwrite database schema. Learn more about [type generation](https://appwrite.io/docs/products/databases/type-generation).

### Error Handling

The Appwrite Node SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```js
let users = new sdk.Users(client);

try {
    let res = await users.create(sdk.ID.unique(), "email@example.com", "+123456789", "password", "Walter O'Brien");
} catch(e) {
    console.log(e.message);
}
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Node Playground](https://github.com/appwrite/playground-for-node)
