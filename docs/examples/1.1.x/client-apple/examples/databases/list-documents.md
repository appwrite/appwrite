import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let databases = Databases(client)
    let documentList = try await databases.listDocuments(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]"
    )

    print(String(describing: documentList)
}
