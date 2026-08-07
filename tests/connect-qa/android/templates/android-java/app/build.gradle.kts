plugins {
  id("com.android.application")
}

android {
  namespace = "io.appwrite.connectqa"
  compileSdk = 35

  defaultConfig {
    applicationId = "io.appwrite.connectqa"
    minSdk = 24
    targetSdk = 35
    versionCode = 1
    versionName = "1.0"

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

  buildFeatures {
    buildConfig = true
  }

  buildTypes {
    release {
      isMinifyEnabled = false
      signingConfig = signingConfigs.getByName("debug")
    }
  }

  compileOptions {
    sourceCompatibility = JavaVersion.VERSION_17
    targetCompatibility = JavaVersion.VERSION_17
  }
}

dependencies {
  implementation("io.appwrite:sdk-for-android:26.0.0")
}
