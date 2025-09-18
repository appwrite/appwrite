import { Client, Project, ProjectUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const project = new Project(client);

const result = await project.getUsage(
    '', // startDate
    '', // endDate
    ProjectUsageRange.OneHour // period (optional)
);

console.log(result);
