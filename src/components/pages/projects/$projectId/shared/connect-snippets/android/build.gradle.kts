// Module-level build.gradle.kts - the two blocks Appwrite needs.
//
// Put the values in gradle.properties (keep that file out of version
// control) and Gradle bakes them into BuildConfig at build time.
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
