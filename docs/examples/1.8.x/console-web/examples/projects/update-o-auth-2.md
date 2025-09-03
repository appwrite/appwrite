import { Client, Projects, OAuthProvider } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateOAuth2({
    projectId: '<PROJECT_ID>',
    provider: OAuthProvider.Amazon,
    appId: '<APP_ID>', // optional
    secret: '<SECRET>', // optional
    enabled: false // optional
});

console.log(result);
