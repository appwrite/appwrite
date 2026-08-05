package main

import (
  "os"
  "github.com/appwrite/sdk-for-go"
)

func main() {
  client := appwrite.NewClient()
  client.SetEndpoint(os.Getenv("APPWRITE_ENDPOINT"))
  client.SetProject(os.Getenv("APPWRITE_PROJECT_ID"))
  client.SetKey(os.Getenv("APPWRITE_API_KEY"))

  account := appwrite.NewAccount(client)
  user, _ := account.Get()
  println("Hello,", *user.Name)
}
