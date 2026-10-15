package main

import (
  "net/http"

  "github.com/appwrite/sdk-for-go/v6/appwrite"
  "github.com/labstack/echo/v4"
)

func main() {
  project := appwrite.NewProject(newClient())
  e := echo.New()

  e.PATCH("/v1/policies", func(c echo.Context) error {
    policy, err := project.UpdatePasswordStrengthPolicy(
      project.WithUpdatePasswordStrengthPolicyMin(8),
      project.WithUpdatePasswordStrengthPolicyUppercase(true),
      project.WithUpdatePasswordStrengthPolicyNumber(true),
      project.WithUpdatePasswordStrengthPolicySymbols(true),
    )
    if err != nil {
      return err
    }
    return c.JSON(http.StatusOK, policy)
  })

  e.GET("/v1/policies", func(c echo.Context) error {
    policies, err := project.ListPolicies()
    if err != nil {
      return err
    }
    return c.JSON(http.StatusOK, policies)
  })

  e.Logger.Fatal(e.Start(":3000"))
}
