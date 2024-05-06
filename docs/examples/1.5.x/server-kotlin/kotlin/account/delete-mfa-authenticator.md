import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Account
import io.appwrite.enums.AuthenticatorType

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession("") // The user session to authenticate with

val account = Account(client)

val response = account.deleteMfaAuthenticator(
    type =  AuthenticatorType.TOTP,
    otp = "<OTP>"
)
