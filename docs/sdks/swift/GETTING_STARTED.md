## Getting Started

### Init your SDK

Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page and your new API secret Key project API keys section.

```swift
import Appwrite

func main() {
    let client = Client()
        .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
        .setProject("5df5acd0d48c2") // Your project ID
        .setKey("919c2d184...a2ae413dad2") // Your secret API key
        .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
}
```

### Make Your First Request

Once your SDK object is initialized, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```swift
let users = Users(client)

do {
    let user = try await users.create(
        userId: ID.unique(),
        email: "email@example.com",
        phone: "+123456789",
        password: "password",
        name: "Walter O'Brien"
    )
    print(String(describing: user.toMap()))
} catch {
    print(error.localizedDescription)
}
```

### Full Example

```swift
import Appwrite

func main() {
    let client = Client()
        .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
        .setProject("5df5acd0d48c2") // Your project ID
        .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
        .setSelfSigned() // Use only on dev mode with a self-signed SSL cert

    let users = Users(client)
    
    do {
        let user = try await users.create(
            userId: ID.unique(),
            email: "email@example.com",
            phone: "+123456789",
            password: "password",
            name: "Walter O'Brien"
        )
        print(String(describing: user.toMap()))
    } catch {
        print(error.localizedDescription)
    }
}
```

### Type Safety with Models

The Appwrite Swift SDK provides type safety when working with database documents through generic methods. Methods like `listDocuments`, `getDocument`, and others accept a `nestedType` parameter that allows you to specify your custom model type for full type safety.

```swift
struct Book: Codable {
    let name: String
    let author: String
    let releaseYear: String?
    let category: String?
    let genre: [String]?
    let isCheckedOut: Bool
}

let databases = Databases(client)

do {
    let documents = try await databases.listDocuments(
        databaseId: "your-database-id",
        collectionId: "your-collection-id",
        nestedType: Book.self // Pass in your custom model type
    )
    
    for book in documents.documents {
        print("Book: \(book.name) by \(book.author)") // Now you have full type safety
    }
} catch {
    print(error.localizedDescription)
}
```

**Tip**: You can use the `appwrite types` command to automatically generate model definitions based on your Appwrite database schema. Learn more about [type generation](https://appwrite.io/docs/products/databases/type-generation).

### Working with Model Methods

All Appwrite models come with built-in methods for data conversion and manipulation:

**`toMap()`** - Converts a model instance to a dictionary format, useful for debugging or manual data manipulation:
```swift
let user = try await account.get()
let userMap = user.toMap()
print(userMap) // Prints all user properties as a dictionary
```

**`from(map:)`** - Creates a model instance from a dictionary, useful when working with raw data:
```swift
let userData: [String: Any] = ["$id": "123", "name": "John", "email": "john@example.com"]
let user = User.from(map: userData)
```

**`encode(to:)`** - Encodes the model to JSON format (part of Swift's Codable protocol), useful for serialization:
```swift
let user = try await account.get()
let jsonData = try JSONEncoder().encode(user)
let jsonString = String(data: jsonData, encoding: .utf8)
```

### Error Handling

When an error occurs, the Appwrite Swift SDK throws an `AppwriteError` object with `message` and `code` properties. You can handle any errors in a catch block and present the `message` or `localizedDescription` to the user or handle it yourself based on the provided error information. Below is an example.

```swift
import Appwrite

func main() {
    let users = Users(client)
    
    do {
        let users = try await users.list()
        print(String(describing: users.toMap()))
    } catch {
        print(error.localizedDescription)
    }
}
```

### Learn more

You can use the following resources to learn more and get help

- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Swift Playground](https://github.com/appwrite/playground-for-swift-server)
