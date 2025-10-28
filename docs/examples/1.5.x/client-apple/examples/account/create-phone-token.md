import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let account = Account(client)

let token = try await account.createPhoneToken(
    userId: "<USER_ID>",
    phone: "+12065550100"
)

