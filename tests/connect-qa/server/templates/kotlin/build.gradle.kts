// Minimal runnable project for the Kotlin connect snippet; the workflow
// copies the snippet's Main.kt into src/main/kotlin and runs `gradle run`.
plugins {
  kotlin("jvm") version "2.0.21"
  application
}

repositories {
  mavenCentral()
}

dependencies {
  implementation("io.appwrite:sdk-for-kotlin:12.0.0")
}

application {
  mainClass.set("MainKt")
}
