import { Client, Teams } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const teams = new Teams(client);

const result = await teams.updateMembershipStatus(
    '<TEAM_ID>', // teamId
    '<MEMBERSHIP_ID>', // membershipId
    '<USER_ID>', // userId
    '<SECRET>' // secret
);

console.log(result);
