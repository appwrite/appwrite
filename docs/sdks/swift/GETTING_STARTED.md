## Getting Started

### Init your SDK

Initialize the SDK with your Appwrite API endpoint, project ID and an API key. You can find your project ID on your project's **Settings** page in the Appwrite Console. Create an [API key](https://appwrite.io/docs/advanced/platform/api-keys) in the Console with the scopes your code needs. API keys grant access to your project's data, so only use this SDK in server-side code and never ship a key in a client app.

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API endpoint
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<API_KEY>") // Your secret API key
```

If you are self-hosting Appwrite, set the endpoint to `https://<HOSTNAME_OR_IP>/v1`. Call `.setSelfSigned()` only during development, when your server uses a self-signed SSL certificate.

### Make your first request

Once your client is initialized, create a service object and call any of its methods. Every method is `async` and `throws`. Full documentation for each method is in the [API references](https://appwrite.io/docs/references).

```swift
let users = Users(client)

let user = try await users.create(
    userId: ID.unique(),
    email: "email@example.com",
    password: "password",
    name: "Walter O'Brien"
)

print(user.name)
```

### Type safety with models

Methods that return rows, such as `listRows` and `getRow`, accept a `nestedType` parameter so you can decode each row's data into your own `Codable` type.

```swift
struct Book: Codable {
    let name: String
    let author: String
    let releaseYear: Int?
    let genre: [String]?
    let isCheckedOut: Bool
}

let tablesDB = TablesDB(client)

let result = try await tablesDB.listRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    nestedType: Book.self
)

for row in result.rows {
    let book = row.data
    print("\(book.name) by \(book.author)")
}
```

You can use the `appwrite types` command of the [Appwrite CLI](https://appwrite.io/docs/tooling/command-line/installation) to generate these models from your database schema. Learn more about [type generation](https://appwrite.io/docs/products/databases/type-generation).

### Working with model methods

All models provide helpers for converting data:

- `toMap()` converts a model to a dictionary, which is useful for debugging or manual data manipulation.
- `from(map:)` creates a model from a dictionary.
- Models conform to `Codable`, so you can encode them with `JSONEncoder`.

```swift
let user = try await users.get(userId: "<USER_ID>")

print(user.toMap())

let copy = User<[String: AnyCodable]>.from(map: user.toMap())

let json = try JSONEncoder().encode(user)
print(String(decoding: json, as: UTF8.self))
```

### Error handling

When Appwrite returns an error, the SDK throws an `AppwriteError` with `message`, `code`, `type` and `response` properties. Network failures, such as a timeout or a lost connection, are thrown as other errors, so add a general `catch` as well.

```swift
do {
    let list = try await users.list()
    print(list.total)
} catch let error as AppwriteError {
    print(error.message)
    print(error.code ?? 0)
    print(error.type ?? "")
} catch {
    print(error.localizedDescription)
}
```

### Learn more

- [Swift quick start](https://appwrite.io/docs/quick-starts/swift)
- [Swift API references](https://appwrite.io/docs/references/cloud/server-swift/users)
- [Appwrite documentation](https://appwrite.io/docs)
- [Swift playground](https://github.com/appwrite/playground-for-swift)
- [Discord community](https://appwrite.io/discord)
