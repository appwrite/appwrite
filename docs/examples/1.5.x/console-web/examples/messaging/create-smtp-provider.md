import { Client, Messaging, SmtpEncryption } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createSmtpProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '<HOST>', // host
    1, // port (optional)
    '<USERNAME>', // username (optional)
    '<PASSWORD>', // password (optional)
    SmtpEncryption.None, // encryption (optional)
    false, // autoTLS (optional)
    '<MAILER>', // mailer (optional)
    '<FROM_NAME>', // fromName (optional)
    'email@example.com', // fromEmail (optional)
    '<REPLY_TO_NAME>', // replyToName (optional)
    'email@example.com', // replyToEmail (optional)
    false // enabled (optional)
);

console.log(response);
