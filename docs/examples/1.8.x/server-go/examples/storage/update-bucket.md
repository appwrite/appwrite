package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/storage"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := storage.New(client)

response, error := service.UpdateBucket(
    "<BUCKET_ID>",
    "<NAME>",
    storage.WithUpdateBucketPermissions(interface{}{"read("any")"}),
    storage.WithUpdateBucketFileSecurity(false),
    storage.WithUpdateBucketEnabled(false),
    storage.WithUpdateBucketMaximumFileSize(1),
    storage.WithUpdateBucketAllowedFileExtensions([]interface{}{}),
    storage.WithUpdateBucketCompression("none"),
    storage.WithUpdateBucketEncryption(false),
    storage.WithUpdateBucketAntivirus(false),
    storage.WithUpdateBucketTransformations(false),
)
