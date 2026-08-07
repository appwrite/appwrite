// Minimal runnable project for the Java connect snippet; the workflow copies
// the snippet's src/main/java/Main.java on top and runs `gradle run`.
plugins {
  java
  application
}

repositories {
  mavenCentral()
}

dependencies {
  implementation("io.appwrite:sdk-for-kotlin:19.1.0")
  // The SDK ships gson at runtime only, so declare it to print models as JSON.
  implementation("com.google.code.gson:gson:2.14.0")
}

application {
  mainClass.set("Main")
}
