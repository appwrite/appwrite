import { Client, Projects, ApiService } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateServiceStatus({
    projectId: '<PROJECT_ID>',
    service: ApiService.Account,
    status: false
});

console.log(result);
