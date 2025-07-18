import { Client, Console, ConsoleResourceType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const console = new Console(client);

const result = await console.getResource(
    '<VALUE>', // value
    ConsoleResourceType.Rules // type
);

console.log(result);
