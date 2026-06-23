import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let databases = Databases(client, "[DATABASE_ID]")
    let documentList = try await databases.listDocuments(
        collectionId: "[COLLECTION_ID]"
    )

    print(String(describing: documentList)
}
