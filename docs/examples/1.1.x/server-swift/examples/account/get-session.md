import Appwrite

func main() async throws {
let client = Client()
.setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
.setProject("5df5acd0d48c2") // Your project ID
.setJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...") // Your secret JSON Web Token
let account = Account(client)
let session = try await account.getSession(
sessionId: "[SESSION_ID]"
)

    print(String(describing: session))

}
