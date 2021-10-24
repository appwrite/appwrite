## Getting Started

### Add your Apple Platform
To initialize your SDK and start interacting with Appwrite services, you need to add a new Apple platform to your project. To add a new platform, go to your Appwrite console, select your project (create one if you haven't already), and click the 'Add Platform' button on the project Dashboard.

From the options, choose to add a new **iOS**, **macOS**, **watchOS** or **tvOS** platform and add your app credentials.

Add your app <u>name</u> and <u>bundle identifier</u>. Your bundle identifier can be found in your Xcode project file or your `Info.plist` file. By registering a new platform, you are allowing your app to communicate with the Appwrite API.

### Registering URL schemes

In order to capture the Appwrite OAuth callback url, the following URL scheme needs to be added to project. You can add this from Xcode by selecting your project file, then the target you wish to use OAuth with. From the `Info` tab, expand the `URL types` section and add your Appwrite instance domain for the `Identifier`, and `appwrite-callback-[PROJECT-ID]` for the `URL scheme`. Be sure to replace the **[PROJECT_ID]** string with your actual Appwrite project ID. You can find your Appwrite project ID in your project settings screen in the console. Alternatively, you can add the following block directly to your targets `Info.plist` file:

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
        <string>appwrite-callback-[PROJECT-ID]</string>
    </array>
</dict>
</array>
```

Next we need to add a hook to save cookies when our app is opened by its callback URL.

### Registering an OAuth handler view

> If you're using UIKit, you can skip this section.

In SwiftUI this is as simple as ensuring `.registerOAuthHanlder()` is called on the `View` you want to invoke an OAuth request from.

### Updating the SceneDelegate for UIKit

> If you're using SwiftUI, you can skip this section.

For UIKit, you need to add the following function to your `SceneDelegate.swift`. If you have already defined this function, you can just add the contents from below.

```swift
    func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
        guard let url = URLContexts.first?.url,
            url.absoluteString.contains("appwrite-callback") else {
            return
        }
        WebAuthComponent.handleIncomingCookie(from: url)
    }
```

### Init your SDK

Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page and your new API secret Key project API keys section.

```swift
import Appwrite

func main() {
    let client = Client()
      .setEndpoint("http://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
}
```

### Make Your First Request

Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```swift
let users = Users(client: client)
users.create(email: "email@example.com", password: "password") { result in
    switch result {
    case .failure(let error): print(error.message)
    case .success(let user): print(String(describing: user))
    }
}
```

### Full Example

```swift
import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setSelfSigned() // Use only on dev mode with a self-signed SSL cert

    let users = Users(client: client)
    users.create(email: "email@example.com", password: "password") { result in
        switch result {
        case .failure(let error): print(error.message)
        case .success(let user): print(String(describing: user))
        }
    }
}
```

### Error Handling

When an error occurs, the Appwrite Swift SDK responds with a result wrapping an `AppwriteError` object with `message` and `code` properties. You can handle any errors in the result's `.failure` case and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```swift
import Appwrite

func main() {
    let users = Users(client: client)
    
    users.create(email: "email@example.com", password: "password") { result in
        switch result {
        case .failure(let error): 
            print(error.message)
        case .success(var response):
            ...
        }
    }
}
```

### Learn more

You can use the following resources to learn more and get help

- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Swift Playground](https://github.com/appwrite/playground-for-swift-server)
