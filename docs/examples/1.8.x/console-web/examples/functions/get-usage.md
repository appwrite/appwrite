import { Client, Functions, UsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.getUsage({
    functionId: '<FUNCTION_ID>',
    range: UsageRange.TwentyFourHours // optional
});

console.log(result);
