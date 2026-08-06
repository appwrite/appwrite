import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;
import java.util.concurrent.CountDownLatch;

public class Main {
  public static void main(String[] args) throws InterruptedException {
    Client client = new Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"));

    Project project = new Project(client);

    // The SDK runs requests asynchronously; wait for both callbacks
    // before the process exits.
    CountDownLatch done = new CountDownLatch(2);

    project.updatePasswordStrengthPolicy(
      8,    // min
      true, // uppercase
      true, // number
      true, // symbols
      new CoroutineCallback<>((policy, error) -> {
        System.out.println(error == null ? policy : error);
        done.countDown();
      })
    );

    project.listPolicies(new CoroutineCallback<>((policies, error) -> {
      System.out.println(error == null ? policies : error);
      done.countDown();
    }));

    done.await();
  }
}
