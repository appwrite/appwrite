import { Client, TablesDB, Permission, Role } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.createTable({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    name: '<NAME>',
    permissions: [Permission.read(Role.any())], // optional
    rowSecurity: false, // optional
    enabled: false, // optional
    columns: [], // optional
    indexes: [] // optional
});

console.log(result);
