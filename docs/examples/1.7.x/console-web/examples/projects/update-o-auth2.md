import { Client, Projects, OAuthProvider } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateOAuth2(
    '<PROJECT_ID>', // projectId
    OAuthProvider.Amazon, // provider
    '<APP_ID>', // appId (optional)
    '<SECRET>', // secret (optional)
    false // enabled (optional)
);

console.log(result);
