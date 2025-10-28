import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let avatars = Avatars(client)
    let byteBuffer = try await avatars.getImage(
        url: "https://example.com"
    )

    print(String(describing: byteBuffer)
}
