POST /v1/sites/{siteId}/deployments HTTP/1.1
Host: cloud.appwrite.io
Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
X-Appwrite-Response-Format: 1.8.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
Content-Length: *Length of your entity body in bytes*

--cec8e8123c05ba25
Content-Disposition: form-data; name="operations"

{ "query": "mutation { sitesCreateDeployment(siteId: $siteId, code: $code, activate: $activate, installCommand: $installCommand, buildCommand: $buildCommand, outputDirectory: $outputDirectory) { id }" }, "variables": { "siteId": "<SITE_ID>", "code": null, "activate": false, "installCommand": "<INSTALL_COMMAND>", "buildCommand": "<BUILD_COMMAND>", "outputDirectory": "<OUTPUT_DIRECTORY>" } }

--cec8e8123c05ba25
Content-Disposition: form-data; name="map"

{ "0": ["variables.code"],  }

--cec8e8123c05ba25
Content-Disposition: form-data; name="0"; filename="code.ext"

File contents

--cec8e8123c05ba25--
