import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let account = Account(client)
    let account = try await account.create(
        userId: "[USER_ID]",
        email: "email@example.com",
        password: "password"
    )

    print(String(describing: account)
}
