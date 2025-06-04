import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let storage = Storage(client)
    let file = try await storage.updateFile(
        bucketId: "[BUCKET_ID]",
        fileId: "[FILE_ID]"
    )

    print(String(describing: file)
}
