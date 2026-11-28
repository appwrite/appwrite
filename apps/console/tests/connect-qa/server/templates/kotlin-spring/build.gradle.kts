// Minimal runnable Spring Boot project for the Kotlin connect snippet; the
// workflow copies the snippet's src/main/kotlin on top and runs `gradle
// bootRun`.
plugins {
  kotlin("jvm") version "2.4.21"
  kotlin("plugin.spring") version "2.4.21"
  id("org.springframework.boot") version "4.2.0"
}

repositories {
  mavenCentral()
}

dependencies {
  implementation("org.springframework.boot:spring-boot-starter-web:4.2.0")
  implementation("io.appwrite:sdk-for-kotlin:19.1.0")
}
