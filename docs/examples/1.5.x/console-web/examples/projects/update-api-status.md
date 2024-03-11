import { Client, Projects,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateApiStatus(
    '<PROJECT_ID>', // projectId
    .Rest, // api
    false // status
);

console.log(response);
