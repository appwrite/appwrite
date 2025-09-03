import { Client, Teams } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const teams = new Teams(client);

const result = await teams.deleteMembership({
    teamId: '<TEAM_ID>',
    membershipId: '<MEMBERSHIP_ID>'
});

console.log(result);
