import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let users = Users(client)

let user = try await users.createScryptModifiedUser(
    userId: "<USER_ID>",
    email: "email@example.com",
    password: "password",
    passwordSalt: "<PASSWORD_SALT>",
    passwordSaltSeparator: "<PASSWORD_SALT_SEPARATOR>",
    passwordSignerKey: "<PASSWORD_SIGNER_KEY>",
    name: "<NAME>" // optional
)

