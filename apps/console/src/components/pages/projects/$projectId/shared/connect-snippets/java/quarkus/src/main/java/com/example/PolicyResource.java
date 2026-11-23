package com.example;

import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.exceptions.AppwriteException;
import io.appwrite.services.Project;
import jakarta.inject.Inject;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.PATCH;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.core.MediaType;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.CompletionStage;

@Path("/v1/policies")
@Produces(MediaType.APPLICATION_JSON)
public class PolicyResource {
  @Inject
  Project project;

  @PATCH
  public CompletionStage<Map<String, Object>> updatePolicy()
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

  @GET
  public CompletionStage<Map<String, Object>> listPolicies()
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
