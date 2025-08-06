POST /v1/databases/{databaseId}/grids/tables/{tableId}/columns/relationship HTTP/1.1
Host: cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "relatedTableId": "<RELATED_TABLE_ID>",
  "type": "oneToOne",
  "twoWay": false,
  "key": ,
  "twoWayKey": ,
  "onDelete": "cascade"
}
