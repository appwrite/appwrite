import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Users

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val users = Users(client)

val response = users.createScryptUser(
    userId = "<USER_ID>",
    email = "email@example.com",
    password = "password",
    passwordSalt = "<PASSWORD_SALT>",
    passwordCpu = 0,
    passwordMemory = 0,
    passwordParallel = 0,
    passwordLength = 0,
    name = "<NAME>" // optional
)
