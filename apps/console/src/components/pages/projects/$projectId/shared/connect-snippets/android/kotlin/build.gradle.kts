android {
  buildFeatures {
    buildConfig = true
  }

  defaultConfig {
    buildConfigField(
      "String",
      "APPWRITE_ENDPOINT",
      "\"${project.property("APPWRITE_ENDPOINT")}\"",
    )
    buildConfigField(
      "String",
      "APPWRITE_PROJECT_ID",
      "\"${project.property("APPWRITE_PROJECT_ID")}\"",
    )
  }
}

dependencies {
  implementation("io.appwrite:sdk-for-android:26.0.0")
}
