import { Client, Projects, EmailTemplateType, EmailTemplateLocale } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateEmailTemplate({
    projectId: '<PROJECT_ID>',
    type: EmailTemplateType.Verification,
    locale: EmailTemplateLocale.Af,
    subject: '<SUBJECT>',
    message: '<MESSAGE>',
    senderName: '<SENDER_NAME>', // optional
    senderEmail: 'email@example.com', // optional
    replyTo: 'email@example.com' // optional
});

console.log(result);
