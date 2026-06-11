---
layout: tutorial
title: Create app
description: Create a Android app project using Appwrite.
step: 2
---

## Create Android project {% #create-android-project %}

Create a Android app with the Android Studio **New Project** wizard. Select **Empty Activity** as the template.

## Add dependencies {% #add-dependencies %}

Install the Android Appwrite SDK.

Add the following to your dependencies in the `app/build.gradle` file:

```groovy
implementation("io.appwrite:sdk-for-android:8.1.0")
```

In case you need to create OAuth 2 sessions in the future, the following activity needs to be added inside the `<application>` tag, along side the existing `<activity>` tags in your [AndroidManifest.xml](https://developer.android.com/guide/topics/manifest/manifest-intro). 
Be sure to replace the **<PROJECT_ID>** string with your actual Appwrite project ID. 
You can find your Appwrite project ID in you project settings screen in your Appwrite Console.

```xml
<manifest ...>
  ...
  <application ...>
    ...
    <!-- Add this inside the `<application>` tag, along side the existing `<activity>` tags -->
    <activity android:name="io.appwrite.views.CallbackActivity" android:exported="true">
      <intent-filter android:label="android_web_auth">
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="appwrite-callback-<PROJECT_ID>" />
      </intent-filter>
    </activity>
  </application>
</manifest>
```

## Using the emulator {% #using-the-emulator %}

Run your app on the [Android emulator](https://developer.android.com/studio/run/emulator).
 You can use the emulator to test your app on different versions of the Android platform and different screen sizes without the need to use physical devices.
For now, you should see a blank screen.
