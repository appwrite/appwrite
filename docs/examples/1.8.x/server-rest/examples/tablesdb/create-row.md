POST /v1/tablesdb/{databaseId}/tables/{tableId}/rows HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-Key: <YOUR_API_KEY>
X-Appwrite-JWT: <YOUR_JWT>

{
  "rowId": "<ROW_ID>",
  "data": {
    "username": "walter.obrien",
    "email": "walter.obrien@example.com",
    "fullName": "Walter O'Brien",
    "age": 30,
    "isAdmin": false
  },
  "permissions": ["read(\"any\")"],
  "transactionId": "<TRANSACTION_ID>"
}
