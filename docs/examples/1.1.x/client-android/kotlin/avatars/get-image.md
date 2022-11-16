import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client(context)
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val avatars = Avatars(client)

val result = avatars.getImage(
    url = "https://example.com",
)
