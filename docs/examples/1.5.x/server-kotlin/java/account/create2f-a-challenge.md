import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Account;
import io.appwrite.enums.AuthenticationFactor;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2"); // Your project ID

Account account = new Account(client);

account.create2FAChallenge(
    AuthenticationFactor.TOTP, // factor
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

