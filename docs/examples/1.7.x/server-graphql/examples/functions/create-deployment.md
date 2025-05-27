POST /v1/functions/{functionId}/deployments HTTP/1.1
Host: cloud.appwrite.io
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.7.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="operations"

{ "query": "mutation { functionsCreateDeployment(functionId: $functionId, code: $code, activate: $activate, entrypoint: $entrypoint, commands: $commands) { id }" }, "variables": { "functionId": "<FUNCTION_ID>", "code": null, "activate": false, "entrypoint": "<ENTRYPOINT>", "commands": "<COMMANDS>" } }

--cec8e8123c05ba25
Content-Disposition: form-data; name="map"

{ "0": ["variables.code"],  }

--cec8e8123c05ba25
Content-Disposition: form-data; name="0"; filename="code.ext"

File contents

--cec8e8123c05ba25--
