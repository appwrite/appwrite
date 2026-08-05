import io.appwrite.Client

object AppwriteClient {
  val client = Client()
    .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
    .setProject(System.getenv("APPWRITE_PROJECT_ID"))
}
