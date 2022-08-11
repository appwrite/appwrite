import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
    let databases = Databases(client, "[DATABASE_ID]")
    let index = try await databases.createIndex(
        collectionId: "[COLLECTION_ID]",
        key: "",
        type: "key",
        attributes: []
    )

    print(String(describing: index)
}
