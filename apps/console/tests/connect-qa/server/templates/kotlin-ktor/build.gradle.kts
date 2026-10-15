// Minimal runnable Ktor project for the Kotlin connect snippet; the workflow
// copies the snippet's src/main/kotlin on top and runs `gradle run`.
plugins {
  kotlin("jvm") version "2.4.10"
  application
}

repositories {
  mavenCentral()
}

dependencies {
  implementation("io.ktor:ktor-server-netty:3.5.2")
  implementation("io.ktor:ktor-server-content-negotiation:3.5.2")
  implementation("io.ktor:ktor-serialization-gson:3.5.2")
  implementation("io.appwrite:sdk-for-kotlin:19.1.0")
}

application {
  mainClass.set("ApplicationKt")
}
