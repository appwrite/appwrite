package com.example

import io.appwrite.Client
import io.appwrite.services.Project
import org.springframework.context.annotation.Bean
import org.springframework.context.annotation.Configuration

@Configuration
class AppwriteConfig {
  @Bean
  fun project(): Project {
    val client = Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"))

    return Project(client)
  }
}
