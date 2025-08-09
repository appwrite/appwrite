POST /v1/databases/{databaseId}/grids/tables HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "tableId": "<TABLE_ID>",
  "name": "<NAME>",
  "permissions": ["read(\"any\")"],
  "rowSecurity": false,
  "enabled": false
}
