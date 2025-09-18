import { Client, Teams } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const teams = new Teams(client);

const response = await teams.createMembership(
    '<TEAM_ID>', // teamId
    [], // roles
    'email@example.com', // email (optional)
    '<USER_ID>', // userId (optional)
    '+12065550100', // phone (optional)
    'https://example.com', // url (optional)
    '<NAME>' // name (optional)
);
