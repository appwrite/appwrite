POST /v1/databases/{databaseId}/collections/{collectionId}/documents HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-Key: <YOUR_API_KEY>
X-Appwrite-JWT: <YOUR_JWT>

{
  "documentId": "<DOCUMENT_ID>",
  "data": {},
  "permissions": ["read(\"any\")"]
}
