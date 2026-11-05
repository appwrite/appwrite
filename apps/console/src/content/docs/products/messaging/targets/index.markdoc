---
layout: article
title: Targets 
description: Manage avenues of communication by targetting user's device, email, or phone number in your notification and messages.
---
Targets are different ways a user can be reached.
For example, a user might have two emails, a phone number as well as a phone and a tablet with your app installed.
This means, the user has five different targets that you can deliver messages to.

{% only_dark %}
![Target overview](/images/docs/messaging/targets/dark/target-overview.avif)
{% /only_dark %}
{% only_light %}
![Target overview](/images/docs/messaging/targets/target-overview.avif)
{% /only_light %}

# Topics and targets {% #topics-and-targets %}
A user can have multiple targets, such as emails, phone numbers, and devices with your app installed. 
These targets can subscribe to a topic, so when messages are published to a topic, all subscribed targets receive the message.

{% arrow_link href="/docs/products/messaging/topics" %}
Learn more about topics
{% /arrow_link %}

# Types of targets {% #types-of-targets %}
There are three types of targets you can use to reach your targets.
{% table %}
* Target Type
* Description
---
* **Email**
* Allows you to send emails to the user's email.
---
* **SMS**
* Allows you to send SMS messages to the user's phone.
---
* **Push notification**
* Allows you to send push notifications to the user's device.
{% /table %}


# Add a target {% #add-a-target %}
Before you can send messages, make sure you have the appropriate targets added for your user.
## Add email target {% #add-email-target%}
Verified emails for users that signed up with [email password](/docs/products/auth/email-password), 
[magic URL](/docs/products/auth/magic-url), [email OTP](/docs/products/auth/email-otp), and [OAuth2](https://appwrite.io/docs/products/auth/oauth2) login will already have an email target.

## Add SMS target {% #add-sms-target%}
Verified phone numbers for users that signed up with [Phone OTP](/docs/products/auth/phone-sms) login will already have a phone target.

## Add push notification target {% #add-push-notification-target%}
Push notifications require configuration on both the Appwrite platform and your client app's code.


{% tabs %}
{% tabsitem #fcm-ios title="iOS with FCM" %}
1. In your Firebase console, navigate to **Settings** > **General** > **Your apps** > add an **iOS** app.
1. Register and download your `google-services.json` config file.
1. Head to **Apple Developer Member Center** > **Program resources** > **Certificates, Identifiers & Profiles** > **Keys**. The key needs **Apple Push Notification Service** enabled.
1. Create a new key, note down the key ID and download your key.
1. In Firebase console, go to *Settings** > **Cloud Messaging** > **APNs authentication key** > click **Upload**. Upload your key here.
1. Add push notification capability to your app by clicking your root-level app in XCode > **Signing & Capabilities** > {% icon icon="plus" size="m" /%} Capabilities > Search for **Push Notifications**.
1. If using SwiftUI, disable swizzling by setting `FirebaseAppDelegateProxyEnabled` to `NO` in your `Info.plist`.

{% only_dark %}![Enable Push Notification in XCode](/images/docs/messaging/targets/dark/xcode-enable-pn.avif){% /only_dark %}{% only_light %}![Enable Push Notification in XCode](/images/docs/messaging/targets/xcode-enable-pn.avif){% /only_light %}
{% /tabsitem %}

{% tabsitem #fcm-android title="Android with FCM" %}
1. In your Firebase console, navigate to **Settings** > **General** > **Your apps** > add an **Android** app.
1. Register and download your `google-services.json` config file.
1. Add `google-services.json` at the root of your project.
1. Add Google Services class path to your app-level Gradle dependencies block `"com.google.gms:google-services:4.4.0"`.
1. Add Google Services plugin to your app-level Gradle in the plugins block as `"com.google.gms.google-services"`.
1. Add notification handler service to `AndroidManifest.xml` inside the application tag, alongside other activities. Find an example of this service in the [Send push notification](/docs/products/messaging/send-push-notifications#add-targets) guide.
```xml
<service android:name="<YOUR_NOTIFICATION_HANDLER_SERVICE>" android:exported="false">
    <intent-filter>
        <action android:name="com.google.firebase.MESSAGING_EVENT" />
    </intent-filter>
</service>
```
{% /tabsitem %}

{% tabsitem #APNs-ios title="iOS with APNs" %}
1. Head to **Apple Developer Member Center** > **Program resources** > **Certificates, Identifiers & Profiles** > **Keys**. The key needs **Apple Push Notification Service** enabled.
1. Create a new key, note down the key ID and download your key.
1. Add push notification capability to your app by clicking your root-level app in XCode > **Signing & Capabilities** > {% icon icon="plus" size="m" /%} Capabilities > Search for **Push Notifications**.
{% only_dark %}
![Enable Push Notification in XCode](/images/docs/messaging/targets/dark/xcode-enable-pn.avif)
{% /only_dark %}
{% only_light %}
![Enable Push Notification in XCode](/images/docs/messaging/targets/xcode-enable-pn.avif)
{% /only_light %}

{% /tabsitem %}
{% /tabs %}