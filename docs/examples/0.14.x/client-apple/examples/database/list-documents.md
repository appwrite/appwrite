import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let database = Database(client)
    let documentList = try await database.listDocuments(
        collectionId: "[COLLECTION_ID]"
    )

    print(String(describing: documentList)
}
