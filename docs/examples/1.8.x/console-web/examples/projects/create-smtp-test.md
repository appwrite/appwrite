import { Client, Projects, SMTPSecure } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.createSmtpTest({
    projectId: '<PROJECT_ID>',
    emails: [],
    senderName: '<SENDER_NAME>',
    senderEmail: 'email@example.com',
    host: '',
    replyTo: 'email@example.com',
    port: null,
    username: '<USERNAME>',
    password: '<PASSWORD>',
    secure: SMTPSecure.Tls
});

console.log(result);
