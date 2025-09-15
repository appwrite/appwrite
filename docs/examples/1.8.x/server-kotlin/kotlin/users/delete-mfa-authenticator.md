import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Users
import io.appwrite.enums.AuthenticatorType

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val users = Users(client)

val response = users.deleteMFAAuthenticator(
    userId = "<USER_ID>",
    type =  AuthenticatorType.TOTP
)
