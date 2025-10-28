package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := databases.New(client)

response, error := service.DecrementDocumentAttribute(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<DOCUMENT_ID>",
    "",
    databases.WithDecrementDocumentAttributeValue(0),
    databases.WithDecrementDocumentAttributeMin(0),
    databases.WithDecrementDocumentAttributeTransactionId("<TRANSACTION_ID>"),
)
