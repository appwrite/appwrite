import { Client, Functions, Runtimes, UseCases } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.listTemplates({
    runtimes: [Runtimes.Node145], // optional
    useCases: [UseCases.Starter], // optional
    limit: 1, // optional
    offset: 0, // optional
    total: false // optional
});

console.log(result);
