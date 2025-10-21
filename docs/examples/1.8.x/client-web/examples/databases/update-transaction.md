import { Client, Databases } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.updateTransaction({
    transactionId: '<TRANSACTION_ID>',
    commit: false, // optional
    rollback: false // optional
});

console.log(result);
