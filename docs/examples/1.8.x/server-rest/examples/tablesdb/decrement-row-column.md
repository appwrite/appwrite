PATCH /v1/tablesdb/{databaseId}/tables/{tableId}/rows/{rowId}/{column}/decrement HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-JWT: <YOUR_JWT>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "value": 0,
  "min": 0,
  "transactionId": "<TRANSACTION_ID>"
}
