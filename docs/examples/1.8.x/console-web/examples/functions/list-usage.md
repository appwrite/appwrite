import { Client, Functions, FunctionUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.listUsage(
    FunctionUsageRange.TwentyFourHours // range (optional)
);

console.log(result);
