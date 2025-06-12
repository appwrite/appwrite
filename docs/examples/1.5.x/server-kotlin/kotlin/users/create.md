import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Users

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val users = Users(client)

val response = users.create(
    userId = "<USER_ID>",
    email = "email@example.com", // optional
    phone = "+12065550100", // optional
    password = "", // optional
    name = "<NAME>" // optional
)
