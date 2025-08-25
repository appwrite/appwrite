import { Client, Projects, SmsTemplateType, SmsTemplateLocale } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.deleteSMSTemplate({
    projectId: '<PROJECT_ID>',
    type: SmsTemplateType.Verification,
    locale: SmsTemplateLocale.Af
});

console.log(result);
