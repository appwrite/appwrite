package main

import (
  "log"

  "github.com/appwrite/sdk-for-go/v6/appwrite"
  "github.com/gofiber/fiber/v3"
)

func main() {
  project := appwrite.NewProject(newClient())
  app := fiber.New()

  app.Patch("/v1/policies", func(c fiber.Ctx) error {
    policy, err := project.UpdatePasswordStrengthPolicy(
      project.WithUpdatePasswordStrengthPolicyMin(8),
      project.WithUpdatePasswordStrengthPolicyUppercase(true),
      project.WithUpdatePasswordStrengthPolicyNumber(true),
      project.WithUpdatePasswordStrengthPolicySymbols(true),
    )
    if err != nil {
      return err
    }
    return c.JSON(policy)
  })

  app.Get("/v1/policies", func(c fiber.Ctx) error {
    policies, err := project.ListPolicies()
    if err != nil {
      return err
    }
    return c.JSON(policies)
  })

  log.Fatal(app.Listen(":3000"))
}
