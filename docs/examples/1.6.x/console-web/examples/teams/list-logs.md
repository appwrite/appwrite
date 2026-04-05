import { Client, Teams } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const teams = new Teams(client);

const result = await teams.listLogs(
    '<TEAM_ID>', // teamId
    [] // queries (optional)
);

console.log(result);
