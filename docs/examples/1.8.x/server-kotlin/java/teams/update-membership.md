```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Teams;
import io.appwrite.enums.Roles;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession(""); // The user session to authenticate with

Teams teams = new Teams(client);

teams.updateMembership(
    "<TEAM_ID>", // teamId
    "<MEMBERSHIP_ID>", // membershipId
    List.of(Roles.ADMIN), // roles
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

```
