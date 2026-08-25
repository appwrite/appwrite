// Minimal runnable Spring Boot project for the Java connect snippet; the
// workflow copies the snippet's src/main/java on top and runs `gradle
// bootRun`.
plugins {
  java
  id("org.springframework.boot") version "4.1.0"
}

repositories {
  mavenCentral()
}

dependencies {
  implementation("org.springframework.boot:spring-boot-starter-web:4.1.0")
  implementation("io.appwrite:sdk-for-kotlin:19.1.0")
}
