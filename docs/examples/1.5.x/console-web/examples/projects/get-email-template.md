import { Client, EmailTemplateType,EmailTemplateLocale, Projects } from "@appwrite.io/console";

const client = new Client();

const projects = new Projects(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = projects.getEmailTemplate('[PROJECT_ID]', EmailTemplateType.Verification, EmailTemplateLocale.Af);

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});