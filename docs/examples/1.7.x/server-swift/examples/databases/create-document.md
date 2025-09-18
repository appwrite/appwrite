import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("<YOUR_API_KEY>") // Your secret API key
    .setJWT("<YOUR_JWT>") // Your secret JSON Web Token

let databases = Databases(client)

let document = try await databases.createDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    data: [:],
    permissions: ["read("any")"] // optional
)

