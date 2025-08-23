import { Client, Messaging, SmtpEncryption } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.updateSMTPProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    host: '<HOST>',
    port: 1,
    username: '<USERNAME>',
    password: '<PASSWORD>',
    encryption: SmtpEncryption.None,
    autoTLS: false,
    mailer: '<MAILER>',
    fromName: '<FROM_NAME>',
    fromEmail: 'email@example.com',
    replyToName: '<REPLY_TO_NAME>',
    replyToEmail: '<REPLY_TO_EMAIL>',
    enabled: false
});
