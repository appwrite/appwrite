import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setSession("") // The user session to authenticate with
    .setKey("") // 
    .setJWT("<YOUR_JWT>") // Your secret JSON Web Token

let tables = Tables(client)

let row = try await tables.createRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: "<ROW_ID>",
    data: [:],
    permissions: ["read("any")"] // optional
)

