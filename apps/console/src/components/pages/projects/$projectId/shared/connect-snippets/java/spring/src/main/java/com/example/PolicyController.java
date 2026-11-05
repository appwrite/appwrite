package com.example;

import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.exceptions.AppwriteException;
import io.appwrite.services.Project;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PatchMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/v1/policies")
public class PolicyController {
  private final Project project;

  public PolicyController(Project project) {
    this.project = project;
  }

  @PatchMapping
  public CompletableFuture<Map<String, Object>> updatePolicy()
    throws AppwriteException {
    CompletableFuture<Map<String, Object>> done = new CompletableFuture<>();

    project.updatePasswordStrengthPolicy(
      8L,   // min
      true, // uppercase
      null, // lowercase
      true, // number
      true, // symbols
      new CoroutineCallback<>((policy, error) -> {
        if (error != null) {
          done.completeExceptionally(error);
        } else {
          done.complete(policy.toMap());
        }
      })
    );

    return done;
  }

  @GetMapping
  public CompletableFuture<Map<String, Object>> listPolicies()
    throws AppwriteException {
    CompletableFuture<Map<String, Object>> done = new CompletableFuture<>();

    project.listPolicies(
      null, // queries
      null, // total
      new CoroutineCallback<>((policies, error) -> {
        if (error != null) {
          done.completeExceptionally(error);
        } else {
          done.complete(policies.toMap());
        }
      })
    );

    return done;
  }
}
