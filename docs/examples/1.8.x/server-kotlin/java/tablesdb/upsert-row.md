import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.Permission;
import io.appwrite.Role;
import io.appwrite.services.TablesDB;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession(""); // The user session to authenticate with

TablesDB tablesDB = new TablesDB(client);

tablesDB.upsertRow(
    "<DATABASE_ID>", // databaseId
    "<TABLE_ID>", // tableId
    "<ROW_ID>", // rowId
    Map.of(
        "username", "walter.obrien",
        "email", "walter.obrien@example.com",
        "fullName", "Walter O'Brien",
        "age", 33,
        "isAdmin", false
    ), // data (optional)
    List.of(Permission.read(Role.any())), // permissions (optional)
    "<TRANSACTION_ID>", // transactionId (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

