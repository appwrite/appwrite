import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let account = Account(client)
    let token = try await account.createPhoneSession(
        userId: "[USER_ID]",
        phone: "+12065550100"
    )

    print(String(describing: token)
}
