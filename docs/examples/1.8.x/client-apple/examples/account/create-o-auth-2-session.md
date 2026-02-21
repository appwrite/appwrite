```swift
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let account = Account(client)

let success = try await account.createOAuth2Session(
    provider: .amazon,
    success: "https://example.com", // optional
    failure: "https://example.com", // optional
    scopes: [] // optional
)

```
