import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let database = Database(client)
    let document = try await database.updateDocument(
        collectionId: "[COLLECTION_ID]",
        documentId: "[DOCUMENT_ID]",
        data: 
    )

    print(String(describing: document)
}
