POST /v1/databases/{databaseId}/collections/{collectionId}/documents HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 
X-Appwrite-JWT: <YOUR_JWT>

{
  "documentId": "<DOCUMENT_ID>",
  "data": {},
  "permissions": ["read(\"any\")"]
}
