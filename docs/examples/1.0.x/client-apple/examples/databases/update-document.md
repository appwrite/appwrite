import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let databases = Databases(client)
    let document = try await databases.updateDocument(
        databaseId: "[DATABASE_ID]",
        collectionId: "[COLLECTION_ID]",
        documentId: "[DOCUMENT_ID]"
    )

    print(String(describing: document)
}
