import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setKey("") // 

let databases = Databases(client)

let documentList = try await databases.createDocuments(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documents: []
)

