package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/storage"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := storage.NewStorage(client)
    response, error := service.CreateBucket(
        "<BUCKET_ID>",
        "<NAME>",
        storage.WithCreateBucketPermissions(interface{}{"read("any")"}),
        storage.WithCreateBucketFileSecurity(false),
        storage.WithCreateBucketEnabled(false),
        storage.WithCreateBucketMaximumFileSize(1),
        storage.WithCreateBucketAllowedFileExtensions([]interface{}{}),
        storage.WithCreateBucketCompression("none"),
        storage.WithCreateBucketEncryption(false),
        storage.WithCreateBucketAntivirus(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
