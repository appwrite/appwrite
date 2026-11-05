// Minimal runnable Quarkus project for the Java connect snippet; the workflow
// copies the snippet's src/main/java on top and runs `gradle quarkusDev`.
plugins {
  java
  id("io.quarkus") version "3.38.1"
}

repositories {
  mavenCentral()
}

dependencies {
  implementation(enforcedPlatform("io.quarkus.platform:quarkus-bom:3.38.1"))
  implementation("io.quarkus:quarkus-rest")
  implementation("io.quarkus:quarkus-rest-jackson")
  implementation("io.appwrite:sdk-for-kotlin:19.1.0")
}
