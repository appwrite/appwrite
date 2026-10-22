## Getting Started

### Add your Apple platform

Before your app can talk to Appwrite, register it as a platform in your project. In the Appwrite Console, open your project (create one if you haven't already), click **Add platform** on the project overview and choose **Apple**. Select **iOS**, **macOS**, **watchOS** or **tvOS**, then enter your app's name and bundle identifier. You can find the bundle identifier in the **General** tab of your app target in Xcode.

### Init your SDK

Initialize the SDK with your Appwrite API endpoint and project ID, which you can find on your project's **Settings** page in the Appwrite Console.

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API endpoint
    .setProject("<PROJECT_ID>") // Your project ID
```

If you are self-hosting Appwrite, set the endpoint to `https://<HOSTNAME_OR_IP>/v1`. Call `.setSelfSigned()` only during development, when your server uses a self-signed SSL certificate.

### Make your first request

Once your client is initialized, create a service object and call any of its methods. Every method is `async` and `throws`. Full documentation for each method is in the [API references](https://appwrite.io/docs/references).

```swift
let account = Account(client)

let user = try await account.create(
    userId: ID.unique(),
    email: "email@example.com",
    password: "password",
    name: "Walter O'Brien"
)

let session = try await account.createEmailPasswordSession(
    email: "email@example.com",
    password: "password"
)

print(user.name, session.userId)
```

### OAuth

To sign users in with an OAuth provider, register the `appwrite-callback-<PROJECT_ID>` URL scheme so the OAuth flow can return to your app. In Xcode, select your app target, open the **Info** tab, expand **URL Types** and add a new type with `io.appwrite` as the **Identifier** and `appwrite-callback-<PROJECT_ID>` as the **URL Schemes**, replacing `<PROJECT_ID>` with your project ID. Alternatively, add the following to your target's `Info.plist`:

```xml
<key>CFBundleURLTypes</key>
<array>
    <dict>
        <key>CFBundleTypeRole</key>
        <string>Editor</string>
        <key>CFBundleURLName</key>
        <string>io.appwrite</string>
        <key>CFBundleURLSchemes</key>
        <array>
            <string>appwrite-callback-<PROJECT_ID></string>
        </array>
    </dict>
</array>
```

Next, add a handler that saves the session cookie when your app is opened from the callback URL.

In SwiftUI, call `registerOAuthHandler()` on the view that starts the OAuth flow:

```swift
import Appwrite
import SwiftUI

struct ContentView: View {
    var body: some View {
        LoginView()
            .registerOAuthHandler()
    }
}
```

In UIKit, handle the callback URL in your `SceneDelegate.swift`. If you already implement this method, add its contents to your implementation:

```swift
func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
    guard let url = URLContexts.first?.url,
        url.absoluteString.contains("appwrite-callback")
    else {
        return
    }
    WebAuthComponent.handleIncomingCookie(from: url)
}
```

Then start the OAuth flow:

```swift
try await account.createOAuth2Session(provider: .github)
```

Learn more about [OAuth 2 login](https://appwrite.io/docs/products/auth/oauth2).

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
let user = try await account.get()

print(user.toMap())

let copy = User<[String: AnyCodable]>.from(map: user.toMap())

let json = try JSONEncoder().encode(user)
print(String(decoding: json, as: UTF8.self))
```

### Error handling

When Appwrite returns an error, the SDK throws an `AppwriteError` with `message`, `code`, `type` and `response` properties. Network failures, such as a timeout or a lost connection, are thrown as other errors, so add a general `catch` as well.

```swift
do {
    let user = try await account.get()
    print(user.email)
} catch let error as AppwriteError {
    print(error.message)
    print(error.code ?? 0)
    print(error.type ?? "")
} catch {
    print(error.localizedDescription)
}
```

### Learn more

- [Apple quick start](https://appwrite.io/docs/quick-starts/apple)
- [Apple API references](https://appwrite.io/docs/references/cloud/client-apple/account)
- [Appwrite documentation](https://appwrite.io/docs)
- [SwiftUI playground](https://github.com/appwrite/playground-for-apple-swiftui)
- [UIKit playground](https://github.com/appwrite/playground-for-apple-uikit)
- [Discord community](https://appwrite.io/discord)
