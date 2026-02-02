import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Provider result = await messaging.createSMTPProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    host: '<HOST>',
    port: 1, // (optional)
    username: '<USERNAME>', // (optional)
    password: '<PASSWORD>', // (optional)
    encryption: enums.SmtpEncryption.none, // (optional)
    autoTLS: false, // (optional)
    mailer: '<MAILER>', // (optional)
    fromName: '<FROM_NAME>', // (optional)
    fromEmail: 'email@example.com', // (optional)
    replyToName: '<REPLY_TO_NAME>', // (optional)
    replyToEmail: 'email@example.com', // (optional)
    enabled: false, // (optional)
);
