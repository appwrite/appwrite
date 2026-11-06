package main

import (
  "net/http"

  "github.com/appwrite/sdk-for-go/v6/appwrite"
  "github.com/gin-gonic/gin"
)

func main() {
  project := appwrite.NewProject(newClient())
  router := gin.Default()

  router.PATCH("/v1/policies", func(c *gin.Context) {
    policy, err := project.UpdatePasswordStrengthPolicy(
      project.WithUpdatePasswordStrengthPolicyMin(8),
      project.WithUpdatePasswordStrengthPolicyUppercase(true),
      project.WithUpdatePasswordStrengthPolicyNumber(true),
      project.WithUpdatePasswordStrengthPolicySymbols(true),
    )
    if err != nil {
      c.JSON(http.StatusBadGateway, gin.H{"error": err.Error()})
      return
    }
    c.JSON(http.StatusOK, policy)
  })

  router.GET("/v1/policies", func(c *gin.Context) {
    policies, err := project.ListPolicies()
    if err != nil {
      c.JSON(http.StatusBadGateway, gin.H{"error": err.Error()})
      return
    }
    c.JSON(http.StatusOK, policies)
  })

  router.Run(":3000")
}
