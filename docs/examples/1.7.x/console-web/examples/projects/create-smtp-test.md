import { Client, Projects, SMTPSecure } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.createSmtpTest(
    '<PROJECT_ID>', // projectId
    [], // emails
    '<SENDER_NAME>', // senderName
    'email@example.com', // senderEmail
    '', // host
    'email@example.com', // replyTo (optional)
    null, // port (optional)
    '<USERNAME>', // username (optional)
    '<PASSWORD>', // password (optional)
    SMTPSecure.Tls // secure (optional)
);

console.log(result);
