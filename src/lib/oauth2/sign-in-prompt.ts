import {
  getOAuth2ProviderDisplayName,
  getOAuth2ProviderIconPath,
} from '@/lib/oauth2/provider-display'

export async function fetchOAuth2ProviderIconSvg(
  providerId: string,
): Promise<string> {
  const path = getOAuth2ProviderIconPath(providerId)
  const response = await fetch(path)
  if (!response.ok) {
    throw new Error(`Failed to load provider icon (${response.status})`)
  }
  const text = (await response.text()).trim()
  if (!text.includes('<svg')) {
    throw new Error('Provider icon is not a valid SVG')
  }
  return text
}

/** Rasterize SVG markup to a PNG blob for download (client-side). */
export async function svgMarkupToPngBlob(
  svg: string,
  size = 512,
): Promise<Blob> {
  if (typeof window === 'undefined') {
    throw new Error('PNG export requires a browser environment')
  }

  const blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(blob)

  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const image = new Image()
      image.onload = () => resolve(image)
      image.onerror = () => reject(new Error('Failed to render provider icon'))
      image.src = url
    })

    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size
    const ctx = canvas.getContext('2d')
    if (!ctx) {
      throw new Error('Could not create canvas')
    }
    ctx.drawImage(img, 0, 0, size, size)

    const pngBlob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob(resolve, 'image/png')
    })
    if (!pngBlob) {
      throw new Error('Could not encode PNG')
    }
    return pngBlob
  } finally {
    URL.revokeObjectURL(url)
  }
}

/** Client SDKs that get a dedicated OAuth sign-in prompt. */
export const OAUTH2_SIGN_IN_PROMPT_SDKS = [
  { id: 'web', label: 'Web' },
  { id: 'flutter', label: 'Flutter' },
  { id: 'react-native', label: 'React Native' },
  { id: 'apple', label: 'Apple' },
  { id: 'android', label: 'Android' },
] as const

export type OAuth2SignInPromptSdkId =
  (typeof OAUTH2_SIGN_IN_PROMPT_SDKS)[number]['id']

export const DEFAULT_OAUTH2_SIGN_IN_PROMPT_SDK: OAuth2SignInPromptSdkId = 'web'

type PromptBuildContext = {
  projectId: string
  endpoint: string
  providerId: string
  providerName: string
  providerEnum: string
  iconSvg?: string
}

/** Best-effort SDK enum member name from provider id (e.g. github -> Github). */
function toOAuthProviderEnumMember(providerId: string): string {
  if (!providerId) return providerId
  return providerId.charAt(0).toUpperCase() + providerId.slice(1)
}

function appendIcon(lines: string[], iconSvg: string | undefined) {
  if (iconSvg?.trim()) {
    lines.push(
      '',
      'Use this SVG markup for the button icon:',
      '```svg',
      iconSvg.trim(),
      '```',
    )
  }
  return lines.join('\n')
}

function sharedProjectDetails(ctx: PromptBuildContext, sdkEnumNote: string): string[] {
  return [
    'Docs: https://appwrite.io/docs/products/auth/oauth2',
    '',
    'Project details:',
    `- Project ID: ${ctx.projectId}`,
    `- API endpoint: ${ctx.endpoint}`,
    `- OAuth provider ID: ${ctx.providerId}`,
    `- Provider display name: ${ctx.providerName}`,
    `- SDK provider enum: ${sdkEnumNote}`,
  ]
}

function sharedTokenRules(): string[] {
  return [
    'Use the OAuth2 token flow only: Account.createOAuth2Token, then Account.createSession.',
    'Create the session in the app after redirect (first-party / deep link). Do not use createOAuth2Session.',
  ]
}

function buildWebPrompt(ctx: PromptBuildContext): string {
  const { providerName, providerEnum, endpoint, projectId, providerId } = ctx
  const lines = [
    `Add "Sign in with ${providerName}" using Appwrite Auth OAuth2, plus a small signed-in dashboard and route guards.`,
    '',
    ...sharedProjectDetails(ctx, `OAuthProvider.${providerEnum} (Web/JS)`),
    '',
    'Scaffold with Vite if needed (`npm create vite@latest`), then install the Web client SDK (not node-appwrite). Prefer @latest so the agent does not pick an old version:',
    '```bash',
    'npm install appwrite@latest',
    '# or: pnpm add appwrite@latest',
    '# or: bun add appwrite@latest',
    '```',
    '',
    ...sharedTokenRules(),
    '',
    'Implement these first-party routes (create the pages if they do not exist):',
    '- `/auth` (or similar): signed-out auth screen with the sign-in button',
    '- `/auth/success`: OAuth success callback. Read `userId` + `secret` from the query string, await account.createSession(...), then redirect to `/dashboard`',
    '- `/auth/failure`: OAuth failure callback. Show an error and a way back to `/auth`',
    '- `/dashboard`: signed-in mini dashboard',
    '',
    '```ts',
    `import { Client, Account, OAuthProvider } from "appwrite";`,
    '',
    'const client = new Client()',
    `  .setEndpoint("${endpoint}")`,
    `  .setProject("${projectId}");`,
    '',
    'const account = new Account(client);',
    '',
    'async function signInWithProvider() {',
    "  const success = `${window.location.origin}/auth/success`;",
    "  const failure = `${window.location.origin}/auth/failure`;",
    '',
    '  // createOAuth2Token navigates the browser to the provider; do not redirect manually.',
    '  await account.createOAuth2Token({',
    `    provider: OAuthProvider.${providerEnum}, // or "${providerId}"`,
    '    success,',
    '    failure,',
    '  });',
    '}',
    '',
    'async function handleOAuthSuccess() {',
    '  const url = new URL(window.location.href);',
    '  const secret = url.searchParams.get("secret");',
    '  const userId = url.searchParams.get("userId");',
    '  if (!secret || !userId) throw new Error("Missing OAuth credentials");',
    '  await account.createSession({ userId, secret });',
    '  window.location.assign("/dashboard");',
    '}',
    '',
    'async function loadDashboard() {',
    '  const user = await account.get();',
    '  // Render user.name (fallback to user.email) and a Sign out button.',
    '}',
    '',
    'async function signOut() {',
    '  await account.deleteSession({ sessionId: "current" });',
    '  window.location.assign("/auth");',
    '}',
    '```',
    '',
    'Auth guards (required):',
    '1. On `/dashboard`: await account.get(). If it fails, redirect to `/auth`.',
    '2. On `/auth`: await account.get(). If it succeeds, redirect to `/dashboard`.',
    '3. Run these checks before rendering protected UI.',
    '',
    'UI requirements:',
    `1. Label the button "Sign in with ${providerName}".`,
    '2. Style a clear primary CTA. Include the provider icon next to the label.',
    '3. Dashboard should show the signed-in user name from account.get() and a Sign out control that awaits account.deleteSession({ sessionId: "current" }).',
    `4. This provider is (or will be) enabled in Auth > Social providers for project ${projectId}.`,
    '5. Do not put OAuth client secrets in frontend code. Secrets stay in the Appwrite Console.',
    '6. Always await Appwrite Account calls before navigating or rendering dependent UI.',
  ]

  return appendIcon(lines, ctx.iconSvg)
}

function buildReactNativePrompt(ctx: PromptBuildContext): string {
  const { providerName, providerEnum, endpoint, projectId, providerId } = ctx
  const lines = [
    `Add "Sign in with ${providerName}" using Appwrite Auth OAuth2, plus a small signed-in dashboard and navigation guards.`,
    '',
    ...sharedProjectDetails(ctx, `OAuthProvider.${providerEnum} (React Native)`),
    '',
    'Scaffold with Expo if needed (`npx create-expo-app@latest`), then install the React Native client SDK (not node-appwrite). Prefer @latest:',
    '```bash',
    'npx expo install react-native-appwrite@latest expo-auth-session expo-web-browser',
    '# or: npm install react-native-appwrite@latest expo-auth-session expo-web-browser',
    '# or: bun add react-native-appwrite@latest expo-auth-session expo-web-browser',
    '```',
    '',
    `In app.json / app.config, set the URL scheme to appwrite-callback-${projectId}.`,
    '',
    ...sharedTokenRules(),
    '',
    'Implement these screens/routes:',
    '- Auth (signed-out): sign-in button',
    '- OAuth callback / deep link handler: read `userId` + `secret`, await account.createSession(...), then go to Dashboard',
    '- Failure path: show an error and return to Auth',
    '- Dashboard (signed-in): show name + logout',
    '',
    '```ts',
    `import { Client, Account, OAuthProvider } from "react-native-appwrite";`,
    'import { makeRedirectUri } from "expo-auth-session";',
    'import * as WebBrowser from "expo-web-browser";',
    '',
    'const client = new Client()',
    `  .setEndpoint("${endpoint}")`,
    `  .setProject("${projectId}");`,
    '',
    'const account = new Account(client);',
    '',
    'async function signInWithProvider() {',
    '  const deepLink = new URL(makeRedirectUri({ preferLocalhost: true }));',
    '  const scheme = `${deepLink.protocol}//`;',
    '',
    '  const loginUrl = await account.createOAuth2Token({',
    `    provider: OAuthProvider.${providerEnum}, // or "${providerId}"`,
    '    success: `${deepLink}`,',
    '    failure: `${deepLink}`,',
    '  });',
    '',
    '  const result = await WebBrowser.openAuthSessionAsync(`${loginUrl}`, scheme);',
    '  if (result.type !== "success" || !("url" in result) || !result.url) {',
    '    throw new Error("OAuth was cancelled or failed");',
    '  }',
    '',
    '  const url = new URL(result.url);',
    '  const secret = url.searchParams.get("secret");',
    '  const userId = url.searchParams.get("userId");',
    '  if (!secret || !userId) throw new Error("Missing OAuth credentials");',
    '  await account.createSession({ userId, secret });',
    '}',
    '',
    'async function loadDashboard() {',
    '  const user = await account.get();',
    '  // Render user.name (fallback to user.email) and a Sign out button.',
    '}',
    '',
    'async function signOut() {',
    '  await account.deleteSession("current");',
    '}',
    '```',
    '',
    'Auth guards (required):',
    '1. Dashboard stack: await account.get(). If it fails, navigate to Auth.',
    '2. Auth screen: await account.get(). If it succeeds, navigate to Dashboard.',
    '3. Resolve auth state before rendering protected UI.',
    '',
    'UI requirements:',
    `1. Label the button "Sign in with ${providerName}".`,
    '2. Style a clear primary CTA. Include the provider icon next to the label.',
    '3. Dashboard should show the signed-in user name from account.get() and a Sign out control.',
    `4. This provider is (or will be) enabled in Auth > Social providers for project ${projectId}.`,
    '5. Do not put OAuth client secrets in the app binary. Secrets stay in the Appwrite Console.',
    '6. Always await Appwrite Account calls before navigating or rendering dependent UI.',
  ]

  return appendIcon(lines, ctx.iconSvg)
}

function buildFlutterPrompt(ctx: PromptBuildContext): string {
  const { providerName, providerId, endpoint, projectId } = ctx
  const flutterEnum = providerId // Flutter uses lowercase enum members like github
  const lines = [
    `Add "Sign in with ${providerName}" using Appwrite Auth OAuth2, plus a small signed-in dashboard and route guards.`,
    '',
    ...sharedProjectDetails(ctx, `OAuthProvider.${flutterEnum} (Flutter)`),
    '',
    'Scaffold with Flutter if needed (`flutter create`), then install the Flutter client SDK. Prefer the latest package:',
    '```bash',
    'flutter pub add appwrite',
    '```',
    '',
    `Configure the OAuth callback scheme appwrite-callback-${projectId}:`,
    '- Android: add CallbackActivity / intent-filter in AndroidManifest.xml (see OAuth2 docs)',
    '- iOS: no extra plist scheme is required beyond Flutter defaults for this flow',
    '',
    ...sharedTokenRules(),
    '',
    'Deep links / success + failure URLs:',
    `- Use a first-party deep link such as appwrite-callback-${projectId}://auth/success and appwrite-callback-${projectId}://auth/failure (or one shared callback URL).`,
    '- Implement the success path: parse userId + secret from the redirect URI, await account.createSession(...), then go to the dashboard.',
    '- Implement the failure path: show an error and return to the login screen.',
    '',
    'Screens:',
    '- Login (signed-out)',
    '- Dashboard (signed-in): name + logout',
    '',
    '```dart',
    "import 'package:appwrite/appwrite.dart';",
    "import 'package:appwrite/enums.dart';",
    '',
    'final client = Client()',
    `  .setEndpoint('${endpoint}')`,
    `  .setProject('${projectId}');`,
    '',
    'final account = Account(client);',
    '',
    'Future<void> signInWithProvider() async {',
    `  final success = 'appwrite-callback-${projectId}://auth/success';`,
    `  final failure = 'appwrite-callback-${projectId}://auth/failure';`,
    '',
    '  final loginUrl = await account.createOAuth2Token(',
    `    provider: OAuthProvider.${flutterEnum},`,
    '    success: success,',
    '    failure: failure,',
    '  );',
    '',
    '  // Open loginUrl in an auth browser / deep-link session, then on redirect:',
    '  // final uri = Uri.parse(redirectedUrl);',
    "  // final userId = uri.queryParameters['userId'];",
    "  // final secret = uri.queryParameters['secret'];",
    '  // await account.createSession(userId: userId!, secret: secret!);',
    '}',
    '',
    'Future<void> loadDashboard() async {',
    '  final user = await account.get();',
    '  // Render user.name (fallback to user.email) and a Sign out button.',
    '}',
    '',
    'Future<void> signOut() async {',
    "  await account.deleteSession(sessionId: 'current');",
    '}',
    '```',
    '',
    'Auth guards (required):',
    '1. Dashboard route: await account.get(). If it fails, go to Login.',
    '2. Login route: await account.get(). If it succeeds, go to Dashboard.',
    '3. Resolve auth before building protected widgets.',
    '',
    'UI requirements:',
    `1. Label the button "Sign in with ${providerName}".`,
    '2. Style a clear primary CTA. Include the provider icon next to the label.',
    '3. Dashboard should show the signed-in user name from account.get() and a Sign out control.',
    `4. This provider is (or will be) enabled in Auth > Social providers for project ${projectId}.`,
    '5. Do not put OAuth client secrets in the app. Secrets stay in the Appwrite Console.',
    '6. Always await Appwrite Account calls before navigating or rendering dependent UI.',
  ]

  return appendIcon(lines, ctx.iconSvg)
}

function buildApplePrompt(ctx: PromptBuildContext): string {
  const { providerName, providerId, endpoint, projectId } = ctx
  const lines = [
    `Add "Sign in with ${providerName}" using Appwrite Auth OAuth2, plus a small signed-in dashboard and navigation guards.`,
    '',
    ...sharedProjectDetails(ctx, `.${providerId} / OAuthProvider (Apple SDK)`),
    '',
    'Create an iOS SwiftUI app in Xcode if needed, then add the Apple client SDK via Swift Package Manager (use the latest release):',
    '```text',
    'https://github.com/appwrite/sdk-for-apple',
    '```',
    '',
    `Register the URL scheme appwrite-callback-${projectId} in Info.plist (CFBundleURLSchemes).`,
    'If using UIKit scenes, forward appwrite-callback URLs through WebAuthComponent.handleIncomingCookie when applicable.',
    '',
    ...sharedTokenRules(),
    '',
    'Deep links / success + failure URLs:',
    `- Use appwrite-callback-${projectId}://auth/success and appwrite-callback-${projectId}://auth/failure (or one shared callback URL).`,
    '- Implement success: parse userId + secret from the callback URL, await account.createSession(...), then show the dashboard.',
    '- Implement failure: show an error and return to login.',
    '',
    'Screens:',
    '- Login (signed-out)',
    '- Dashboard (signed-in): name + logout',
    '',
    '```swift',
    'import Appwrite',
    'import AppwriteEnums',
    '',
    'let client = Client()',
    `    .setEndpoint("${endpoint}")`,
    `    .setProject("${projectId}")`,
    '',
    'let account = Account(client)',
    '',
    'func signInWithProvider() async throws {',
    `    let success = "appwrite-callback-${projectId}://auth/success"`,
    `    let failure = "appwrite-callback-${projectId}://auth/failure"`,
    '',
    '    let loginUrl = try await account.createOAuth2Token(',
    `        provider: .${providerId},`,
    '        success: success,',
    '        failure: failure',
    '    )',
    '',
    '    // Open loginUrl (ASWebAuthenticationSession / Safari), then on redirect:',
    '    // let userId = ...; let secret = ...',
    '    // _ = try await account.createSession(userId: userId, secret: secret)',
    '}',
    '',
    'func loadDashboard() async throws {',
    '    let user = try await account.get()',
    '    // Render user.name (fallback to user.email) and a Sign out button.',
    '}',
    '',
    'func signOut() async throws {',
    '    _ = try await account.deleteSession(sessionId: "current")',
    '}',
    '```',
    '',
    'Auth guards (required):',
    '1. Dashboard: try await account.get(). On failure, show Login.',
    '2. Login: try await account.get(). On success, show Dashboard.',
    '3. Resolve auth before rendering protected UI.',
    '',
    'UI requirements:',
    `1. Label the button "Sign in with ${providerName}".`,
    '2. Style a clear primary CTA. Include the provider icon next to the label.',
    '3. Dashboard should show the signed-in user name from account.get() and a Sign out control.',
    `4. This provider is (or will be) enabled in Auth > Social providers for project ${projectId}.`,
    '5. Do not put OAuth client secrets in the app. Secrets stay in the Appwrite Console.',
    '6. Always await Appwrite Account calls before navigating or rendering dependent UI.',
  ]

  return appendIcon(lines, ctx.iconSvg)
}

function buildAndroidPrompt(ctx: PromptBuildContext): string {
  const { providerName, providerId, endpoint, projectId } = ctx
  const androidEnum = providerId.toUpperCase()
  const lines = [
    `Add "Sign in with ${providerName}" using Appwrite Auth OAuth2, plus a small signed-in dashboard and navigation guards.`,
    '',
    ...sharedProjectDetails(ctx, `OAuthProvider.${androidEnum} (Android)`),
    '',
    'Create an Android app in Android Studio if needed, then add the Android client SDK. Prefer the latest published version of io.appwrite:sdk-for-android:',
    '```kotlin',
    '// module build.gradle.kts',
    'dependencies {',
    '  implementation("io.appwrite:sdk-for-android:+") // resolve to latest stable',
    '}',
    '```',
    '',
    `In AndroidManifest.xml, register CallbackActivity with scheme appwrite-callback-${projectId} (see OAuth2 docs).`,
    '',
    ...sharedTokenRules(),
    '',
    'Deep links / success + failure URLs:',
    `- Use appwrite-callback-${projectId}://auth/success and appwrite-callback-${projectId}://auth/failure (or one shared callback URL).`,
    '- Implement success: parse userId + secret from the intent data URI, await account.createSession(...), then open the dashboard.',
    '- Implement failure: show an error and return to login.',
    '',
    'Screens:',
    '- Login (signed-out)',
    '- Dashboard (signed-in): name + logout',
    '',
    '```kotlin',
    'import io.appwrite.Client',
    'import io.appwrite.services.Account',
    'import io.appwrite.enums.OAuthProvider',
    '',
    'val client = Client(context)',
    `    .setEndpoint("${endpoint}")`,
    `    .setProject("${projectId}")`,
    '',
    'val account = Account(client)',
    '',
    'suspend fun signInWithProvider() {',
    `    val success = "appwrite-callback-${projectId}://auth/success"`,
    `    val failure = "appwrite-callback-${projectId}://auth/failure"`,
    '',
    '    val loginUrl = account.createOAuth2Token(',
    `        provider = OAuthProvider.${androidEnum},`,
    '        success = success,',
    '        failure = failure,',
    '    )',
    '',
    '    // Open loginUrl (Custom Tabs / browser), then on redirect intent:',
    '    // val userId = ...; val secret = ...',
    '    // account.createSession(userId, secret)',
    '}',
    '',
    'suspend fun loadDashboard() {',
    '    val user = account.get()',
    '    // Render user.name (fallback to user.email) and a Sign out button.',
    '}',
    '',
    'suspend fun signOut() {',
    '    account.deleteSession("current")',
    '}',
    '```',
    '',
    'Auth guards (required):',
    '1. Dashboard: call account.get(). On failure, navigate to Login.',
    '2. Login: call account.get(). On success, navigate to Dashboard.',
    '3. Resolve auth before showing protected UI.',
    '',
    'UI requirements:',
    `1. Label the button "Sign in with ${providerName}".`,
    '2. Style a clear primary CTA. Include the provider icon next to the label.',
    '3. Dashboard should show the signed-in user name from account.get() and a Sign out control.',
    `4. This provider is (or will be) enabled in Auth > Social providers for project ${projectId}.`,
    '5. Do not put OAuth client secrets in the APK. Secrets stay in the Appwrite Console.',
    '6. Always await / suspend Appwrite Account calls before navigating or rendering dependent UI.',
  ]

  return appendIcon(lines, ctx.iconSvg)
}

export function buildOAuth2SignInPrompt(options: {
  projectId: string
  endpoint: string
  providerId: string
  providerName?: string
  iconSvg?: string
  sdk?: OAuth2SignInPromptSdkId
}): string {
  const providerName =
    options.providerName || getOAuth2ProviderDisplayName(options.providerId)
  const providerEnum = toOAuthProviderEnumMember(options.providerId)
  const sdk = options.sdk ?? DEFAULT_OAUTH2_SIGN_IN_PROMPT_SDK

  const ctx: PromptBuildContext = {
    projectId: options.projectId,
    endpoint: options.endpoint,
    providerId: options.providerId,
    providerName,
    providerEnum,
    iconSvg: options.iconSvg,
  }

  switch (sdk) {
    case 'flutter':
      return buildFlutterPrompt(ctx)
    case 'react-native':
      return buildReactNativePrompt(ctx)
    case 'apple':
      return buildApplePrompt(ctx)
    case 'android':
      return buildAndroidPrompt(ctx)
    case 'web':
    default:
      return buildWebPrompt(ctx)
  }
}
