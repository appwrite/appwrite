package main

import (
  "encoding/json"
  "fmt"
  "os"

  "github.com/appwrite/sdk-for-go/v6/appwrite"
)

func main() {
  client := appwrite.NewClient(
    appwrite.WithEndpoint(os.Getenv("APPWRITE_ENDPOINT")),
    appwrite.WithProject(os.Getenv("APPWRITE_PROJECT_ID")),
    appwrite.WithKey(os.Getenv("APPWRITE_API_KEY")),
  )

  project := appwrite.NewProject(client)

  policy, err := project.UpdatePasswordStrengthPolicy(
    project.WithUpdatePasswordStrengthPolicyMin(8),
    project.WithUpdatePasswordStrengthPolicyUppercase(true),
    project.WithUpdatePasswordStrengthPolicyNumber(true),
    project.WithUpdatePasswordStrengthPolicySymbols(true),
  )
  if err != nil {
    panic(err)
  }

  out, _ := json.MarshalIndent(policy, "", "  ")
  fmt.Println(string(out))

  policies, err := project.ListPolicies()
  if err != nil {
    panic(err)
  }

  out, _ = json.MarshalIndent(policies, "", "  ")
  fmt.Println(string(out))
}
