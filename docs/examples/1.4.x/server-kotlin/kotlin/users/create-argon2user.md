import io.appwrite.Client
import io.appwrite.services.Users

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val users = Users(client)

val response = users.createArgon2User(
    userId = "[USER_ID]",
    email = "email@example.com",
    password = "password",
)
