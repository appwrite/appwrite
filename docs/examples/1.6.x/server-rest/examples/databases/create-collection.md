POST /v1/databases/{databaseId}/collections HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.5.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "collectionId": "<COLLECTION_ID>",
  "name": "<NAME>",
  "permissions": ["read(\"any\")"],
  "documentSecurity": false,
  "enabled": false
}
