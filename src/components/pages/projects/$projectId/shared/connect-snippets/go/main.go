package main

import (
  "fmt"
  "os"

  "github.com/appwrite/sdk-for-go"
)

func main() {
  client := appwrite.NewClient()
  client.SetEndpoint(os.Getenv("APPWRITE_ENDPOINT"))
  client.SetProject(os.Getenv("APPWRITE_PROJECT_ID"))
  client.SetKey(os.Getenv("APPWRITE_API_KEY"))

  project := appwrite.NewProject(client)

  // min: 8, uppercase, number, symbols
  policy, err := project.UpdatePasswordStrengthPolicy(8, true, true, true)
  if err != nil {
    panic(err)
  }

  fmt.Println(policy)

  policies, err := project.ListPolicies()
  if err != nil {
    panic(err)
  }

  fmt.Println(policies)
}
