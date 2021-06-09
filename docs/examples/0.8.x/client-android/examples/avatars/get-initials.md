import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID

val avatarsService = Avatars(client)
val response = avatarsService.getInitials()
val json = response.body?.string()