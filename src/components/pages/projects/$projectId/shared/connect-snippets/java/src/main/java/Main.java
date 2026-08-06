import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

public class Main {
  public static void main(String[] args) {
    Client client = new Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"));

    Project project = new Project(client);

    project.updatePasswordStrengthPolicy(
      8,    // min
      true, // uppercase
      true, // number
      true, // symbols
      new CoroutineCallback<>((policy, error) -> {
        System.out.println(policy);
      })
    );

    project.listPolicies(new CoroutineCallback<>((policies, error) -> {
      System.out.println(policies);
    }));
  }
}
