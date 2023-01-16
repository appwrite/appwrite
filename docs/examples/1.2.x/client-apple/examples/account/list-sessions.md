import Appwrite

let client = Client()
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let account = Account(client)

let sessionList = try await account.listSessions()

