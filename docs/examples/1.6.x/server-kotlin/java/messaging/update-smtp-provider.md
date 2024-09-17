import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.updateSmtpProvider(
    "<PROVIDER_ID>", // providerId
    "<NAME>", // name (optional)
    "<HOST>", // host (optional)
    1, // port (optional)
    "<USERNAME>", // username (optional)
    "<PASSWORD>", // password (optional)
    SmtpEncryption.NONE, // encryption (optional)
    false, // autoTLS (optional)
    "<MAILER>", // mailer (optional)
    "<FROM_NAME>", // fromName (optional)
    "email@example.com", // fromEmail (optional)
    "<REPLY_TO_NAME>", // replyToName (optional)
    "<REPLY_TO_EMAIL>", // replyToEmail (optional)
    false, // enabled (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

