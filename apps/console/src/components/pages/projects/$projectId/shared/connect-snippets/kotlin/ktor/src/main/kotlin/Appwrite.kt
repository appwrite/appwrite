import io.appwrite.Client
import io.appwrite.services.Project

val appwrite = Project(
  Client()
    .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
    .setProject(System.getenv("APPWRITE_PROJECT_ID"))
    .setKey(System.getenv("APPWRITE_API_KEY"))
)
