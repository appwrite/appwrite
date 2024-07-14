import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let account = Account(client)

let target = try await account.updatePushTarget(
    targetId: "<TARGET_ID>",
    identifier: "<IDENTIFIER>"
)

