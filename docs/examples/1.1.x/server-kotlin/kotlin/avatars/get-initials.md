import io.appwrite.Client
import io.appwrite.services.Avatars

suspend fun main() {
    val client = Client(context)
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

    val avatars = Avatars(client)
    val result = avatars.getInitials(
    )
    println(result); // Resource URL
}