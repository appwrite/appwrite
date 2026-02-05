```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tablesDB = new sdk.TablesDB(client);

const result = await tablesDB.createOperations({
    transactionId: '<TRANSACTION_ID>',
    operations: [
	    {
	        "action": "create",
	        "databaseId": "<DATABASE_ID>",
	        "tableId": "<TABLE_ID>",
	        "rowId": "<ROW_ID>",
	        "data": {
	            "name": "Walter O'Brien"
	        }
	    }
	] // optional
});
```
