import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Avatars
import io.appwrite.enums.CreditCard

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val avatars = Avatars(client)

val result = avatars.getCreditCard(
    code = CreditCard.AMERICAN_EXPRESS,
    width = 0, // (optional)
    height = 0, // (optional)
    quality = 0, // (optional)
)