import { Client, Functions, FunctionUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.getFunctionUsage(
    '<FUNCTION_ID>', // functionId
    FunctionUsageRange.TwentyFourHours // range (optional)
);

console.log(result);
