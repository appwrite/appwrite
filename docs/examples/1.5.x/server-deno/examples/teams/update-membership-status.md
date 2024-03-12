import { Client, Teams } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

const teams = new Teams(client);

const response = await teams.updateMembershipStatus(
    '<TEAM_ID>', // teamId
    '<MEMBERSHIP_ID>', // membershipId
    '<USER_ID>', // userId
    '<SECRET>' // secret
);
