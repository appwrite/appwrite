import com.google.gson.Gson;
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.exceptions.AppwriteException;
import io.appwrite.services.Project;
import java.util.concurrent.CountDownLatch;

public class Main {
  public static void main(String[] args)
    throws AppwriteException, InterruptedException {
    Gson gson = new Gson();

    Client client = new Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"));

    Project project = new Project(client);

    // The SDK runs requests asynchronously; wait for both callbacks
    // before the process exits.
    CountDownLatch done = new CountDownLatch(2);

    project.updatePasswordStrengthPolicy(
      8L,   // min
      true, // uppercase
      null, // lowercase
      true, // number
      true, // symbols
      new CoroutineCallback<>((policy, error) -> {
        if (error != null) {
          System.out.println(error);
        } else {
          System.out.println(gson.toJson(policy.toMap()));
        }
        done.countDown();
      })
    );

    project.listPolicies(
      null, // queries
      null, // total
      new CoroutineCallback<>((policies, error) -> {
        if (error != null) {
          System.out.println(error);
        } else {
          System.out.println(gson.toJson(policies.toMap()));
        }
        done.countDown();
      })
    );

    done.await();
  }
}
