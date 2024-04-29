import { Client, Teams } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const teams = new Teams(client);

const result = await teams.listMemberships(
    '<TEAM_ID>', // teamId
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);

console.log(response);
