import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Users;
import io.appwrite.enums.PasswordHash;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Users users = new Users(client);

users.createSHAUser(
    "<USER_ID>", // userId
    "email@example.com", // email
    "password", // password
    PasswordHash.SHA1, // passwordVersion (optional)
    "<NAME>", // name (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

