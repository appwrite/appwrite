import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Teams;

Client client = new Client(context)
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2"); // Your project ID

Teams teams = new Teams(client);

teams.updateMembershipStatus(
    "[TEAM_ID]",
    "[MEMBERSHIP_ID]",
    "[USER_ID]",
    "[SECRET]"
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
