import Appwrite

func main() async throws {
let client = Client()
.setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
.setProject("5df5acd0d48c2") // Your project ID
let account = Account(client)
let jwt = try await account.createJWT()

    print(String(describing: jwt))

}
