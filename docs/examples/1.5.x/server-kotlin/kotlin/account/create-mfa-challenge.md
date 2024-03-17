import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Account
import io.appwrite.enums.AuthenticationFactor

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val account = Account(client)

val response = account.createMfaChallenge(
    factor =  AuthenticationFactor.EMAIL
)
