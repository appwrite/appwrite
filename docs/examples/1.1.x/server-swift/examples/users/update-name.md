import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
    let users = Users(client)
    let user = try await users.updateName(
        userId: "[USER_ID]",
        name: "[NAME]"
    )

    print(String(describing: user)
}
