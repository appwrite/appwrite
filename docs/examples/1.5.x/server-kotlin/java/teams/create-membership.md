import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Teams;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setSession(""); // The user session to authenticate with

Teams teams = new Teams(client);

teams.createMembership(
    "<TEAM_ID>", // teamId
    listOf(), // roles
    "email@example.com", // email (optional)
    "<USER_ID>", // userId (optional)
    "+12065550100", // phone (optional)
    "https://example.com", // url (optional)
    "<NAME>", // name (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

