POST /v1/databases/{databaseId}/collections/{collectionId}/attributes/relationship HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "relatedCollectionId": "<RELATED_COLLECTION_ID>",
  "type": "oneToOne",
  "twoWay": false,
  "key": ,
  "twoWayKey": ,
  "onDelete": "cascade"
}
