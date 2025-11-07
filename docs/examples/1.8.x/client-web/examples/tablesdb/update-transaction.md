import { Client, TablesDB } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.updateTransaction({
    transactionId: '<TRANSACTION_ID>',
    commit: false, // optional
    rollback: false // optional
});

console.log(result);
