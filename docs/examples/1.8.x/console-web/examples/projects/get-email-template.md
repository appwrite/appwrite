import { Client, Projects, EmailTemplateType, EmailTemplateLocale } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.getEmailTemplate({
    projectId: '<PROJECT_ID>',
    type: EmailTemplateType.Verification,
    locale: EmailTemplateLocale.Af
});

console.log(result);
