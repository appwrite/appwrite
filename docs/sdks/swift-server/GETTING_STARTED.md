## Getting Started

### Init your SDK

Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page and your new API secret Key project API keys section.

```swift
import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
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
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
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
