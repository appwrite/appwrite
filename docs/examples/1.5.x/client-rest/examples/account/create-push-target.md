POST /v1/account/targets/push HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Session: 

{
  "targetId": "<TARGET_ID>",
  "identifier": "<IDENTIFIER>",
  "providerId": "<PROVIDER_ID>"
}
