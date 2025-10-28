import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Users
import io.appwrite.enums.AuthenticatorProvider

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val users = Users(client)

val response = users.deleteAuthenticator(
    userId = "[USER_ID]",
    provider = AuthenticatorProvider.TOTP,
    otp = "[OTP]",
)