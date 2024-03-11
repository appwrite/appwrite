import { Client, Projects, SMTPSecure } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateSmtp(
    '<PROJECT_ID>', // projectId
    false, // enabled
    '<SENDER_NAME>', // senderName (optional)
    'email@example.com', // senderEmail (optional)
    'email@example.com', // replyTo (optional)
    '', // host (optional)
    null, // port (optional)
    '<USERNAME>', // username (optional)
    '<PASSWORD>', // password (optional)
    SMTPSecure.Tls // secure (optional)
);

console.log(response);
