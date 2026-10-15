package com.example

import io.appwrite.services.Project
import kotlinx.coroutines.runBlocking
import org.springframework.web.bind.annotation.GetMapping
import org.springframework.web.bind.annotation.PatchMapping
import org.springframework.web.bind.annotation.RequestMapping
import org.springframework.web.bind.annotation.RestController

@RestController
@RequestMapping("/v1/policies")
class PolicyController(private val project: Project) {
  @PatchMapping
  fun updatePolicy(): Map<String, Any?> = runBlocking {
    project.updatePasswordStrengthPolicy(
      min = 8,
      uppercase = true,
      number = true,
      symbols = true
    ).toMap()
  }

  @GetMapping
  fun listPolicies(): Map<String, Any?> = runBlocking {
    project.listPolicies().toMap()
  }
}
