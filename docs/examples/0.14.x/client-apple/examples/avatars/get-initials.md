import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let avatars = Avatars(client)
    let byteBuffer = try await avatars.getInitials()

    print(String(describing: byteBuffer)
}
