plugins {
  id("com.android.application")
  id("org.jetbrains.kotlin.android")
  id("org.jetbrains.kotlin.plugin.compose")
}

android {
  namespace = "io.appwrite.connectqa"
  // sdk-for-android 26.x publishes AAR metadata requiring compileSdk 37+.
  compileSdk = 37

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
    compose = true
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

kotlin {
  compilerOptions {
    jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
  }
}

dependencies {
  implementation("io.appwrite:sdk-for-android:26.0.0")
  implementation("androidx.activity:activity-compose:1.9.3")
  implementation(platform("androidx.compose:compose-bom:2024.10.01"))
  implementation("androidx.compose.material3:material3")
  implementation("androidx.compose.ui:ui")
}
