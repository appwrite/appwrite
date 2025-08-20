import { Client, Teams } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const teams = new Teams(client);

const result = await teams.createMembership({
    teamId: '<TEAM_ID>',
    roles: [],
    email: 'email@example.com',
    userId: '<USER_ID>',
    phone: '+12065550100',
    url: 'https://example.com',
    name: '<NAME>'
});

console.log(result);
