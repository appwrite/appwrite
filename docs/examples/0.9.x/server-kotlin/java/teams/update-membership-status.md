import io.appwrite.Client
import io.appwrite.services.Teams

public void main() {
    Client client = Client(context)
        .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
        .setProject("5df5acd0d48c2") // Your project ID
        .setJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ..."); // Your secret JSON Web Token

    Teams teams = new Teams(client);
    teams.updateMembershipStatus(
        "[TEAM_ID]",
        "[MEMBERSHIP_ID]",
        "[USER_ID]",
        "[SECRET]"
        new Continuation<Object>() {
            @NotNull
            @Override
            public CoroutineContext getContext() {
                return EmptyCoroutineContext.INSTANCE;
            }

            @Override
            public void resumeWith(@NotNull Object o) {
                String json = "";
                try {
                    if (o instanceof Result.Failure) {
                        Result.Failure failure = (Result.Failure) o;
                        throw failure.exception;
                    } else {
                        Response response = (Response) o;
                    }
                } catch (Throwable th) {
                    Log.e("ERROR", th.toString());
                }
            }
        }
    );
}