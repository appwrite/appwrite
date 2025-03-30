import Appwrite

func main() async throws {
let client = Client()
.setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
.setProject("5df5acd0d48c2") // Your project ID
let account = Account(client)
let token = try await account.updatePhoneVerification(
userId: "[USER_ID]",
secret: "[SECRET]"
)

    print(String(describing: token))

}
