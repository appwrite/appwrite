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
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := databases.New(client)
    response, error := service.DecrementDocumentAttribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        "",
        databases.WithDecrementDocumentAttributeValue(0),
        databases.WithDecrementDocumentAttributeMin(0),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
