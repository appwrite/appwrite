---
layout: article
title: Start with React Native
description: Discover how to leverage Appwrite's powerful backend to help you build React Native apps for iOS, Android and other native platforms.
difficulty: beginner
readtime: 10
back: /docs/quick-starts
---

Learn how to setup your first React Native project powered by Appwrite.
The React Native SDK is still in `beta`. Proceed with caution if you plan to use this SDK in production.

{% info title="React for web" %}
Looking to start with React for web?
Follow the [React quickstart](/docs/quick-starts/react) and [React tutorial](/docs/tutorials/react/step-1) flows.
{% /info %}

{% section #step-1 step=1 title="Create React Native project" %}
Create a React Native project using [npx](https://www.npmjs.com/package/npx).

```sh
npx create-expo-app my-app
cd my-app
```
{% /section %}

{% section #step-2 step=2 title="Create project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console).

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}

{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

If this is your first time using Appwrite, create an account and create your first project.

Then, under **Add a platform**, add a **Android app** or a **Apple app**.

{% tabs %}
{% tabsitem #ios title="iOS" %}
Add your app **name** and **Bundle ID**. You can find your **Bundle Identifier** in the **General** tab for your app's primary target in XCode.

**Note**: If you've followed the commands above, you have created an Expo project. This means you need to define the Bundle Identifier in the `app.json` configuration. [More info](https://docs.expo.dev/versions/latest/config/app/#bundleidentifier)

{% only_dark %}
![Add a platform](/images/docs/quick-starts/dark/add-platform.avif)
{% /only_dark %}

{% only_light %}
![Add a platform](/images/docs/quick-starts/add-platform.avif)
{% /only_light %}
{% /tabsitem %}

{% tabsitem #android title="Android" %}
Add your app's **name** and **package name**, Your package name is generally the `applicationId` in your app-level [build.gradle](https://github.com/appwrite/playground-for-flutter/blob/master/android/app/build.gradle#L41) file.

**Note**: If you've followed the commands above, you have created an Expo project. This means you need to define the package name in the `app.json` configuration. [More info](https://docs.expo.dev/versions/latest/config/app/#package)

{% arrow_link
   href="https://developer.android.com/build/configure-app-module" %}
Learn more about Android app module
{% /arrow_link %}
{% /tabsitem %}
{% /tabs %}

You can skip optional steps.
{% /section %}

{% section #step-3 step=3 title="Install Appwrite" %}
Install the Appwrite SDK for React Native and required dependencies.

```sh
npx expo install react-native-appwrite react-native-url-polyfill
```
{% /section %}

{% section #step-4 step=4 title="Implement Appwrite" %}
Find your project's ID in the **Settings** page.

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}

{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Open `app/(tabs)/index.tsx` and add the following code to it, replace `<PROJECT_ID>` with your project ID and `<YOUR_PLATFORM>` with your application id or package name.

This imports and initializes Appwrite and defines some basic authentication methods.

```client-react-native
import { StatusBar } from 'expo-status-bar';
import { StyleSheet, Text, View, TextInput, TouchableOpacity } from 'react-native';
import { Client, Account, ID, Models } from 'react-native-appwrite';
import React, { useState } from 'react';

let client: Client;
let account: Account;

client = new Client();
client
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('66e943139f030e2feaf8')   // Your Project ID
  .setPlatform('com.example.my-app');   // Your package name / bundle identifier

account = new Account(client);
export default function App() {
  const [loggedInUser, setLoggedInUser] = useState<Models.User<Models.Preferences> | null>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');

  async function login(email: string, password: string) {
    await account.createEmailPasswordSession({
        email,
        password
    });
    setLoggedInUser(await account.get());
  }

  async function register(email: string, password: string, name: string) {
    await account.create({
        userId: ID.unique(),
        email,
        password,
        name
    });
    await login(email, password);
    setLoggedInUser(await account.get());
  }
  return (
    // ... Implement your UI here
  );
}

const styles = StyleSheet.create({
    // ... define some styles
});

```
{% /section %}

{% section #step-5 step=5 title="Create a login form" %}
With `Client` and `Account` service initialized, you can now use them to make your first requests to Appwrite.

Add the following components to your `App.js` file to create a simple login form.

```client-react-native
<View style={styles.root}>
    <Text>
    {loggedInUser ? `Logged in as ${loggedInUser.name}` : 'Not logged in'}
    </Text>
    <View>
    <TextInput
        style={styles.input}
        placeholder="Email"
        value={email}
        onChangeText={(text) => setEmail(text)}
    />
    <TextInput
        style={styles.input}
        placeholder="Password"
        value={password}
        onChangeText={(text) => setPassword(text)}
        secureTextEntry
    />
    <TextInput
        style={styles.input}
        placeholder="Name"
        value={name}
        onChangeText={(text) => setName(text)}
    />

    <TouchableOpacity
        style={styles.button}
        onPress={() => login(email, password)}
    >
        <Text>Login</Text>
    </TouchableOpacity>

    <TouchableOpacity
        style={styles.button}
        onPress={()=> register(email, password, name)}
    >
        <Text>Register</Text>
    </TouchableOpacity>

    <TouchableOpacity
        style={styles.button}
        onPress={async () => {
        await account.deleteSession({ sessionId: 'current' });
        setLoggedInUser(null);
        }}
    >
        <Text>Logout</Text>
    </TouchableOpacity>
    </View>
</View>
```

You can also add some simple styling to your app by adding the following styles to your `App.js` file.

```client-react-native
const styles = StyleSheet.create({
  root: {
    marginTop: 40,
    marginBottom: 40
  },
  input: {
    height: 40,
    borderColor: 'gray',
    borderWidth: 1,
    marginBottom: 10,
    paddingHorizontal: 10,
  },
  button: {
    backgroundColor: 'gray',
    padding: 10,
    marginBottom: 10,
    alignItems: 'center',
  },
});
```
{% /section %}

{% section #step-6 step=6 title="All set" %}
Run your project with `npx expo start`.

{% arrow_link
   href="https://github.com/appwrite/playground-for-react-native" %}
Explore the React Native playground
{% /arrow_link %}
{% /section %}
