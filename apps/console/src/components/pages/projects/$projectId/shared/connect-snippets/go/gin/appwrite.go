package main

import (
  "os"

  "github.com/appwrite/sdk-for-go/v6/appwrite"
  "github.com/appwrite/sdk-for-go/v6/client"
)

func newClient() client.Client {
  return appwrite.NewClient(
    appwrite.WithEndpoint(os.Getenv("APPWRITE_ENDPOINT")),
    appwrite.WithProject(os.Getenv("APPWRITE_PROJECT_ID")),
    appwrite.WithKey(os.Getenv("APPWRITE_API_KEY")),
  )
}
