## Add Appwrite auth to a new React Native (Expo) app with a minimal login/register/logout UI

- Never assume project details. Ask the user for **Cloud Region**, **Project ID**, and package/bundle ID.
- Use explicit config (no hardcoding in code except reading constants/env the user sets).
- Respect the user's package manager and Expo workflow.

## Step 1: Scaffold or use existing Expo app

- If you already have an Expo project open, stay in it and use it.
- Otherwise, run: `npx create-expo-app my-app && cd my-app`

## Step 2: Install SDK and polyfills

- Run: `npx expo install react-native-appwrite react-native-url-polyfill`

## Step 3: Configure identifiers (ask user)

- Ask user for **Android package name** and **iOS bundle identifier**. Guide them to set these in `app.json`.
- Ask for **Cloud Region** and **Project ID** from Console -> Settings.

## Step 4: Client setup (key snippet)

- File: `app/lib/appwrite.ts` (or `.js`)

```ts
import 'react-native-url-polyfill/auto';
import { Client, Account, ID } from 'react-native-appwrite';

const endpoint = 'https://<REGION>.cloud.appwrite.io/v1'; // ask user for <REGION>
const project = '<PROJECT_ID>'; // ask user for ID
const platform = '<PACKAGE_OR_BUNDLE_ID>'; // ask user for this

const client = new Client().setEndpoint(endpoint).setProject(project).setPlatform(platform);
export const account = new Account(client);
export { ID };
```

## Step 5: UI wiring (idea + key snippets)

- If this is a fresh project, you can reuse the default entry screen (e.g., `app/(tabs)/index.tsx`).
- If you are adding to an existing project, create a new screen/route (e.g., `app/auth.tsx` or a new tab/stack screen) instead of overriding the current default route.
- Screen file example:

```tsx
import React, { useState } from 'react';
import { View, Text, TextInput, TouchableOpacity } from 'react-native';
import { account, ID } from '../lib/appwrite';

export default function AuthScreen() {
    const [user, setUser] = useState(null);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [name, setName] = useState('');

    async function login(e: string, p: string) {
        await account.createEmailPasswordSession({ email: e, password: p });
        setUser(await account.get());
    }

    async function register() {
        await account.create({ userId: ID.unique(), email, password, name });
        await login(email, password);
    }

    async function logout() {
        await account.deleteSession({ sessionId: 'current' });
        setUser(null);
    }

    return (
        <View>
            {user ? <Text>Logged in as {user.name}</Text> : null}
            <TextInput value={email} onChangeText={setEmail} placeholder="Email" />
            <TextInput
                value={password}
                onChangeText={setPassword}
                placeholder="Password"
                secureTextEntry
            />
            <TextInput value={name} onChangeText={setName} placeholder="Name" />
            <TouchableOpacity onPress={() => login(email, password)}>
                <Text>Login</Text>
            </TouchableOpacity>
            <TouchableOpacity onPress={register}>
                <Text>Register</Text>
            </TouchableOpacity>
            {user && (
                <TouchableOpacity onPress={logout}>
                    <Text>Logout</Text>
                </TouchableOpacity>
            )}
        </View>
    );
}
```

- Minimal JSX: display user name when logged in; inputs for email/password/name; buttons for **Login**/**Register**/**Logout**.

## Step 6: Verify platforms

- Ask user to add **Android** and/or **iOS** platform in Console. Use the configured package/bundle identifiers.

## Step 7: Run and test

- Run: `npx expo start`
- Test register -> auto login -> logout -> login.

## Deliverables

- `app/lib/appwrite.ts`, updated screen with minimal form and actions
