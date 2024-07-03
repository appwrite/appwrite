POST /v1/databases/{databaseId}/collections/{collectionId}/attributes/relationship HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.5.0
X-Appwrite-Project: &lt;YOUR_PROJECT_ID&gt;
X-Appwrite-Key: &lt;YOUR_API_KEY&gt;

{
  "relatedCollectionId": "<RELATED_COLLECTION_ID>",
  "type": "oneToOne",
  "twoWay": false,
  "key": ,
  "twoWayKey": ,
  "onDelete": "cascade"
}
