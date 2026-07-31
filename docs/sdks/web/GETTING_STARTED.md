## Getting Started

### Add your Web Platform

For you to init your SDK and interact with Appwrite services you need to add a web platform to your project. To add a new platform, go to your Appwrite console, choose the project you created in the step before and click the 'Add Platform' button.

From the options, choose to add a **Web** platform and add your client app hostname. By adding your hostname to your project platform you are allowing cross-domain communication between your project and the Appwrite API.

### Init your SDK

Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page.

```js
// Init your Web SDK
const client = new Client();

client
    .setEndpoint('http://localhost/v1') // Your Appwrite Endpoint
    .setProject('455x34dfkj') // Your project ID
;
```

### Make Your First Request

Once your SDK object is set, access any of the Appwrite services and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```js
const account = new Account(client);

// Register User
account.create(ID.unique(), "email@example.com", "password", "Walter O'Brien")
    .then(function (response) {
        console.log(response);
    }, function (error) {
        console.log(error);
    });

```

### Full Example

```js
// Init your Web SDK
const client = new Client();

client
    .setEndpoint('http://localhost/v1') // Your Appwrite Endpoint
    .setProject('455x34dfkj')
;

const account = new Account(client);

// Register User
account.create(ID.unique(), "email@example.com", "password", "Walter O'Brien")
    .then(function (response) {
        console.log(response);
    }, function (error) {
        console.log(error);
    });
```

### Type Safety with Models

The Appwrite Web SDK provides type safety when working with database documents through generic methods. Methods like `listDocuments`, `getDocument`, and others accept a generic type parameter that allows you to specify your custom model type for full type safety.

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

The Appwrite Web SDK raises an `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching the exception and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```javascript
try {
    const user = await account.create(ID.unique(), "email@example.com", "password", "Walter O'Brien");
    console.log('User created:', user);
} catch (error) {
    console.error('Appwrite error:', error.message);
}
```

### Learn more

You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-web)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Web Playground](https://github.com/appwrite/playground-for-web)
