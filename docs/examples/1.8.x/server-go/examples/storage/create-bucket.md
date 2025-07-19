package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/storage"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := storage.New(client)
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
