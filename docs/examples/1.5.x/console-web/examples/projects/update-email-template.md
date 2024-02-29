import { Client, Projects, EmailTemplateType, EmailTemplateLocale } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateEmailTemplate(
    '<PROJECT_ID>', // projectId
    EmailTemplateType.Verification, // type
    EmailTemplateLocale.Af, // locale
    '<SUBJECT>', // subject
    '<MESSAGE>', // message
    '<SENDER_NAME>', // senderName (optional)
    'email@example.com', // senderEmail (optional)
    'email@example.com' // replyTo (optional)
);

console.log(response);
