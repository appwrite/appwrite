package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithSession("") // The user session to authenticate with
    )

    service := databases.New(client)
    response, error := service.IncrementDocumentAttribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "",
        databases.WithIncrementDocumentAttributeValue(0),
        databases.WithIncrementDocumentAttributeMax(0),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
