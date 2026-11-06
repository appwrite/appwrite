package com.example;

import io.appwrite.Client;
import io.appwrite.services.Project;
import jakarta.enterprise.inject.Produces;
import jakarta.inject.Singleton;

public class AppwriteProducer {
  @Produces
  @Singleton
  public Project project() {
    Client client = new Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"));

    return new Project(client);
  }
}
