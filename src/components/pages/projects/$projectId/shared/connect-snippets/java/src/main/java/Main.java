import io.appwrite.Client;
import io.appwrite.services.Account;

public class Main {
  public static void main(String[] args) {
    Client client = new Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"));

    Account account = new Account(client);
    // Use the account service (and others) for API calls
  }
}
