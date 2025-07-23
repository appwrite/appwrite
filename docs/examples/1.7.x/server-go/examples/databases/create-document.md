package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithAdmin("") // 
        client.WithSession("") // The user session to authenticate with
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
        client.WithJWT("<YOUR_JWT>") // Your secret JSON Web Token
    )

    service := databases.New(client)
    response, error := service.CreateDocument(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "<DOCUMENT_ID>",
        map[string]interface{}{},
        databases.WithCreateDocumentPermissions(interface{}{"read("any")"}),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
