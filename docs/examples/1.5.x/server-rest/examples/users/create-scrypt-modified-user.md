POST /v1/users/scrypt-modified HTTP/1.1
Host: &lt;REGION&gt;.cloud.appwrite.io
Content-Type: application/json
X-Appwrite-Response-Format: 1.6.0
X-Appwrite-Project: <YOUR_PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "userId": "<USER_ID>",
  "email": "email@example.com",
  "password": "password",
  "passwordSalt": "<PASSWORD_SALT>",
  "passwordSaltSeparator": "<PASSWORD_SALT_SEPARATOR>",
  "passwordSignerKey": "<PASSWORD_SIGNER_KEY>",
  "name": "<NAME>"
}
