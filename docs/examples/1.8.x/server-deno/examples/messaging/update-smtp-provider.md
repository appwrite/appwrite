import { Client, Messaging, SmtpEncryption } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.updateSMTPProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // optional
    host: '<HOST>', // optional
    port: 1, // optional
    username: '<USERNAME>', // optional
    password: '<PASSWORD>', // optional
    encryption: SmtpEncryption.None, // optional
    autoTLS: false, // optional
    mailer: '<MAILER>', // optional
    fromName: '<FROM_NAME>', // optional
    fromEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    replyToEmail: '<REPLY_TO_EMAIL>', // optional
    enabled: false // optional
});
