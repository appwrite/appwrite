// Minimal runnable project for the Kotlin connect snippet; the workflow
// copies the snippet's Main.kt into src/main/kotlin and runs `gradle run`.
plugins {
  kotlin("jvm") version "2.4.10"
  application
}

repositories {
  mavenCentral()
}

dependencies {
  implementation("io.appwrite:sdk-for-kotlin:19.1.0")
}

application {
  mainClass.set("MainKt")
}
