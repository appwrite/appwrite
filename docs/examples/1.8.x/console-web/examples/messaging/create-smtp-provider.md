import { Client, Messaging, SmtpEncryption } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createSMTPProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    host: '<HOST>',
    port: 1, // optional
    username: '<USERNAME>', // optional
    password: '<PASSWORD>', // optional
    encryption: SmtpEncryption.None, // optional
    autoTLS: false, // optional
    mailer: '<MAILER>', // optional
    fromName: '<FROM_NAME>', // optional
    fromEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    replyToEmail: 'email@example.com', // optional
    enabled: false // optional
});

console.log(result);
