```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let databases = Databases(client)

let document = try await databases.updateDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    data: [
        "username": "walter.obrien",
        "email": "walter.obrien@example.com",
        "fullName": "Walter O'Brien",
        "age": 33,
        "isAdmin": false
    ], // optional
    permissions: [Permission.read(Role.any())], // optional
    transactionId: "<TRANSACTION_ID>" // optional
)

```
