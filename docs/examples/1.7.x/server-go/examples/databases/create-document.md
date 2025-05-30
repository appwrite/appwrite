package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetSession("") // The user session to authenticate with
    client.SetKey("<YOUR_API_KEY>") // Your secret API key
    client.SetJWT("<YOUR_JWT>") // Your secret JSON Web Token

    service := databases.NewDatabases(client)
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
