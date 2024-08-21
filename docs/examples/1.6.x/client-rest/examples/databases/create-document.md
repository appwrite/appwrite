POST /v1/databases/{databaseId}/collections/{collectionId}/documents HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Session: 
X-Appwrite-JWT: &lt;YOUR_JWT&gt;

{
  "documentId": "<DOCUMENT_ID>",
  "data": {},
  "permissions": ["read(\"any\")"]
}
