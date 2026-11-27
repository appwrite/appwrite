/**
 * Code for signing an app's users in through a project team's identity
 * providers, per platform, and the same setup phrased for an AI coding agent.
 *
 * No SDK has methods for single sign-on yet, so every platform calls the two
 * Cloud endpoints directly and only uses its SDK for the last step:
 *
 *   GET  /v1/account/sessions/sso/providers?email=   listSSOProviders (public, X-Appwrite-Project)
 *   GET  /v1/account/tokens/sso?project=&providerId=&success=&failure=&state=
 *                                                    createSSOToken (browser navigation)
 *   POST /v1/account/sessions/token                  createSession (every SDK has it)
 *
 * The token flow is used everywhere, as the OAuth2 sign-in prompts do: the
 * success URL gets `userId`, `secret` and `state`, and the app trades them for
 * a session in its own context (browser, server or device). Nothing relies on
 * the API's own cookie, so it works on another site than the API and on a
 * server. The app binds the sign-in to the browser with `state`.
 *
 * Return URLs pass CE's redirect validator: an https URL on a hostname of the
 * project's platforms (or localhost), or the `appwrite-callback-<projectId>`
 * scheme every native app may use.
 */

import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'

export const SSO_SIGN_IN_PLATFORMS = [
  'web',
  'nextjs',
  'sveltekit',
  'flutter',
  'react-native',
  'apple',
  'android',
  'python',
  'http',
] as const

export type SSOSignInPlatform = (typeof SSO_SIGN_IN_PLATFORMS)[number]

export type SSOSignInPlatformMeta = {
  label: string
  /** Icon key: `web` uses PlatformIcon, `http` a glyph, the rest FrameworkIcon. */
  icon: string
  /** Shown under the steps: what is special about this platform. */
  note: string
  /** What the agent is told it is working on. */
  subject: string
}

export const SSO_SIGN_IN_PLATFORM_META: Record<
  SSOSignInPlatform,
  SSOSignInPlatformMeta
> = {
  web: {
    label: 'Web',
    icon: 'web',
    subject: 'web app (runs in the browser)',
    note: "Add your site as a Web platform of this project first: Appwrite only sends people back to its platforms' hostnames, and localhost.",
  },
  nextjs: {
    label: 'Next.js',
    icon: 'nextjs',
    subject: 'Next.js app (App Router, rendered on the server)',
    note: 'The server trades the token for the session with an API key that has the sessions.write scope, then keeps the session in its own cookie.',
  },
  sveltekit: {
    label: 'SvelteKit',
    icon: 'sveltekit',
    subject: 'SvelteKit app (rendered on the server)',
    note: 'The server trades the token for the session with an API key that has the sessions.write scope, then keeps the session in its own cookie.',
  },
  flutter: {
    label: 'Flutter',
    icon: 'flutter',
    subject: 'Flutter app',
    note: 'The sign-in opens in a system browser sheet and comes back to the appwrite-callback scheme, which every app of this project may use.',
  },
  'react-native': {
    label: 'React Native',
    icon: 'react-native',
    subject: 'React Native app (Expo)',
    note: 'The sign-in opens in a system browser sheet and comes back to the appwrite-callback scheme, which every app of this project may use.',
  },
  apple: {
    label: 'Apple',
    icon: 'apple',
    subject: 'SwiftUI app (iOS 16.4+, macOS 13.3+)',
    note: 'ASWebAuthenticationSession catches the appwrite-callback scheme itself, so there is nothing to register in Info.plist.',
  },
  android: {
    label: 'Android',
    icon: 'android',
    subject: 'Android app (Kotlin)',
    note: 'The sign-in opens in a Custom Tab and comes back to an activity that handles the appwrite-callback scheme.',
  },
  python: {
    label: 'Python',
    icon: 'python',
    subject: 'Python web app (Flask, rendered on the server)',
    note: 'The server trades the token for the session with an API key that has the sessions.write scope, then keeps the session in its own cookie.',
  },
  http: {
    label: 'HTTP',
    icon: 'http',
    subject: 'app (using the HTTP API directly)',
    note: 'Any language works: two requests and one browser redirect. With an API key, the session response includes its secret for your own cookie.',
  },
}

export function isSSOSignInPlatform(
  value: string | null | undefined,
): value is SSOSignInPlatform {
  return (
    !!value && (SSO_SIGN_IN_PLATFORMS as readonly string[]).includes(value)
  )
}

export type SSOSignInStep = {
  /** Stable id, also the code frame's tab id. */
  id: string
  /** Step title, shown in the step list. */
  title: string
  /** One line on what happens in this step. */
  description: string
  /** File the code goes in; the code frame's tab label. */
  file: string
  code: string
  language: CodeBlockLanguage
}

export type SSOSignInGuide = {
  install?: { code: string; language: CodeBlockLanguage }
  steps: SSOSignInStep[]
}

export type SSOSignInInput = {
  endpoint: string
  projectId: string
  /** An email on one of the team's verified domains, for the HTTP example. */
  exampleEmail: string
  /** A real identity provider, for the HTTP example. */
  exampleProvider?: { $id: string; name: string }
}

/** The scheme every native app of the project may return to. */
export function ssoCallbackScheme(projectId: string): string {
  return `appwrite-callback-${projectId}`
}

const FIND_TITLE = 'Find the identity providers for an email'
const FIND_DESCRIPTION =
  'People type their work email. None found: offer your other sign-in methods. Several: let them choose by name.'
const SEND_TITLE = 'Send the browser to the chosen one'
const SEND_DESCRIPTION =
  'With a fresh random state, so your app only accepts sign-ins it started.'
const FINISH_TITLE = 'Create the session when it comes back'
const FINISH_DESCRIPTION =
  'The success URL gets userId, secret and state. Check the state, then trade the rest for the session.'
const MANIFEST_TITLE = 'Handle the callback scheme'

function webGuide({ endpoint, projectId }: SSOSignInInput): SSOSignInGuide {
  return {
    install: { language: 'bash', code: 'npm install appwrite' },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'src/lib/sso.ts',
        language: 'typescript',
        code: `export const ENDPOINT = '${endpoint}'
export const PROJECT_ID = '${projectId}'

export type IdentityProvider = { $id: string; name: string }

// No SDK method yet: call the endpoint directly.
export async function findIdentityProviders(
  email: string,
): Promise<IdentityProvider[]> {
  const url = new URL(\`\${ENDPOINT}/account/sessions/sso/providers\`)
  url.searchParams.set('email', email)
  const response = await fetch(url, {
    headers: { 'X-Appwrite-Project': PROJECT_ID },
  })
  const body = await response.json()
  if (!response.ok) throw new Error(body.message)
  return body.providers
}`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'src/lib/sso.ts',
        language: 'typescript',
        code: `export function signInWithSSO(providerId: string) {
  const state = crypto.randomUUID()
  sessionStorage.setItem('sso-state', state)

  const url = new URL(\`\${ENDPOINT}/account/tokens/sso\`)
  url.searchParams.set('project', PROJECT_ID)
  url.searchParams.set('providerId', providerId)
  url.searchParams.set('success', \`\${location.origin}/auth/sso/success\`)
  url.searchParams.set('failure', \`\${location.origin}/auth/sso/failure\`)
  url.searchParams.set('state', state)
  location.assign(url)
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'src/pages/sso-callback.ts',
        language: 'typescript',
        code: `import { Account, Client } from 'appwrite'
import { ENDPOINT, PROJECT_ID } from '../lib/sso'

const account = new Account(
  new Client().setEndpoint(ENDPOINT).setProject(PROJECT_ID),
)

// Runs on /auth/sso/success
export async function finishSSOSignIn() {
  const params = new URLSearchParams(location.search)
  const state = sessionStorage.getItem('sso-state')
  sessionStorage.removeItem('sso-state')
  if (!state || params.get('state') !== state) {
    throw new Error('This sign-in was not started here.')
  }

  await account.createSession({
    userId: params.get('userId')!,
    secret: params.get('secret')!,
  })
  location.replace('/')
}

// Runs on /auth/sso/failure: { message, type, code }
export function ssoFailure() {
  const error = new URLSearchParams(location.search).get('error')
  return error ? JSON.parse(error) : null
}`,
      },
    ],
  }
}

/** The lookup as server code (Next.js, SvelteKit): the same fetch. */
function serverLookup(endpoint: string, projectId: string): string {
  return `export const ENDPOINT = '${endpoint}'
export const PROJECT_ID = '${projectId}'

export type IdentityProvider = { $id: string; name: string }

// Call it from your sign-in form's action. No SDK method yet.
export async function findIdentityProviders(
  email: string,
): Promise<IdentityProvider[]> {
  const url = new URL(\`\${ENDPOINT}/account/sessions/sso/providers\`)
  url.searchParams.set('email', email)
  const response = await fetch(url, {
    headers: { 'X-Appwrite-Project': PROJECT_ID },
  })
  const body = await response.json()
  if (!response.ok) throw new Error(body.message)
  return body.providers
}`
}

function nextjsGuide({ endpoint, projectId }: SSOSignInInput): SSOSignInGuide {
  return {
    install: { language: 'bash', code: 'npm install node-appwrite' },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'lib/sso.ts',
        language: 'typescript',
        code: serverLookup(endpoint, projectId),
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'app/auth/sso/route.ts',
        language: 'typescript',
        code: `import { cookies } from 'next/headers'
import { NextResponse, type NextRequest } from 'next/server'
import { ENDPOINT, PROJECT_ID } from '@/lib/sso'

// GET /auth/sso?providerId=...
export async function GET(request: NextRequest) {
  const providerId = request.nextUrl.searchParams.get('providerId')
  if (!providerId) return NextResponse.redirect(new URL('/sign-in', request.url))

  const state = crypto.randomUUID()
  const jar = await cookies()
  jar.set('sso-state', state, {
    httpOnly: true,
    secure: true,
    sameSite: 'lax',
    maxAge: 600,
    path: '/',
  })

  const url = new URL(\`\${ENDPOINT}/account/tokens/sso\`)
  url.searchParams.set('project', PROJECT_ID)
  url.searchParams.set('providerId', providerId)
  url.searchParams.set('success', new URL('/auth/sso/callback', request.url).href)
  url.searchParams.set('failure', new URL('/sign-in', request.url).href)
  url.searchParams.set('state', state)
  return NextResponse.redirect(url)
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'app/auth/sso/callback/route.ts',
        language: 'typescript',
        code: `import { cookies } from 'next/headers'
import { NextResponse, type NextRequest } from 'next/server'
import { Account, Client } from 'node-appwrite'
import { ENDPOINT, PROJECT_ID } from '@/lib/sso'

export async function GET(request: NextRequest) {
  const params = request.nextUrl.searchParams
  const jar = await cookies()
  const state = jar.get('sso-state')?.value
  jar.delete('sso-state')
  if (!state || params.get('state') !== state) {
    return NextResponse.redirect(new URL('/sign-in', request.url))
  }

  // API key with the sessions.write scope. Server only.
  const admin = new Client()
    .setEndpoint(ENDPOINT)
    .setProject(PROJECT_ID)
    .setKey(process.env.APPWRITE_API_KEY!)
  const session = await new Account(admin).createSession({
    userId: params.get('userId')!,
    secret: params.get('secret')!,
  })

  // Later requests: new Client()...setSession(cookie)
  jar.set(\`a_session_\${PROJECT_ID}\`, session.secret, {
    httpOnly: true,
    secure: true,
    sameSite: 'lax',
    expires: new Date(session.expire),
    path: '/',
  })
  return NextResponse.redirect(new URL('/', request.url))
}`,
      },
    ],
  }
}

function sveltekitGuide({
  endpoint,
  projectId,
}: SSOSignInInput): SSOSignInGuide {
  return {
    install: { language: 'bash', code: 'npm install node-appwrite' },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'src/lib/server/sso.ts',
        language: 'typescript',
        code: serverLookup(endpoint, projectId),
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'src/routes/auth/sso/+server.ts',
        language: 'typescript',
        code: `import { redirect } from '@sveltejs/kit'
import { ENDPOINT, PROJECT_ID } from '#lib/server/sso.js' // SvelteKit 2: '$lib/server/sso'
import type { RequestHandler } from './$types'

// GET /auth/sso?providerId=...
export const GET: RequestHandler = ({ url, cookies }) => {
  const providerId = url.searchParams.get('providerId')
  if (!providerId) redirect(303, '/sign-in')

  const state = crypto.randomUUID()
  cookies.set('sso-state', state, {
    path: '/',
    httpOnly: true,
    sameSite: 'lax',
    maxAge: 600,
  })

  const target = new URL(\`\${ENDPOINT}/account/tokens/sso\`)
  target.searchParams.set('project', PROJECT_ID)
  target.searchParams.set('providerId', providerId)
  target.searchParams.set('success', new URL('/auth/sso/callback', url).href)
  target.searchParams.set('failure', new URL('/sign-in', url).href)
  target.searchParams.set('state', state)
  // Another site: a plain redirect, which redirect() refuses without options
  return new Response(null, { status: 303, headers: { location: target.href } })
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'src/routes/auth/sso/callback/+server.ts',
        language: 'typescript',
        code: `import { redirect } from '@sveltejs/kit'
import { APPWRITE_API_KEY } from '$app/env/private' // SvelteKit 2: env from '$env/dynamic/private'
import { Account, Client } from 'node-appwrite'
import { ENDPOINT, PROJECT_ID } from '#lib/server/sso.js'
import type { RequestHandler } from './$types'

export const GET: RequestHandler = async ({ url, cookies }) => {
  const state = cookies.get('sso-state')
  cookies.delete('sso-state', { path: '/' })
  if (!state || url.searchParams.get('state') !== state) {
    redirect(303, '/sign-in')
  }

  // API key with the sessions.write scope. Server only.
  const admin = new Client()
    .setEndpoint(ENDPOINT)
    .setProject(PROJECT_ID)
    .setKey(APPWRITE_API_KEY)
  const session = await new Account(admin).createSession({
    userId: url.searchParams.get('userId')!,
    secret: url.searchParams.get('secret')!,
  })

  // Later requests: new Client()...setSession(cookie)
  cookies.set(\`a_session_\${PROJECT_ID}\`, session.secret, {
    path: '/',
    httpOnly: true,
    secure: true,
    sameSite: 'lax',
    expires: new Date(session.expire),
  })
  redirect(303, '/')
}`,
      },
      {
        id: 'env',
        title: 'Declare the API key',
        description:
          'SvelteKit reads it from the environment when the app starts. Put it in .env locally.',
        file: 'src/env.ts',
        language: 'typescript',
        code: `import { defineEnvVars } from '@sveltejs/kit/env'

// SvelteKit 2 needs no declaration: read env.APPWRITE_API_KEY from '$env/dynamic/private'
export const variables = defineEnvVars({
  APPWRITE_API_KEY: { description: 'API key with the sessions.write scope' },
})`,
      },
    ],
  }
}

function flutterGuide({ endpoint, projectId }: SSOSignInInput): SSOSignInGuide {
  const scheme = ssoCallbackScheme(projectId)
  return {
    install: {
      language: 'bash',
      // One command picks an old appwrite to fit the newest flutter_web_auth_2.
      code: 'flutter pub add appwrite && flutter pub add flutter_web_auth_2 http',
    },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'lib/sso.dart',
        language: 'dart',
        code: `// Imports for the whole file: steps 2 and 3 go below.
import 'dart:convert';
import 'dart:math';
import 'package:appwrite/appwrite.dart';
import 'package:flutter_web_auth_2/flutter_web_auth_2.dart';
import 'package:http/http.dart' as http;

const endpoint = '${endpoint}';
const projectId = '${projectId}';

typedef IdentityProvider = ({String id, String name});

// No SDK method yet: call the endpoint directly.
Future<List<IdentityProvider>> findIdentityProviders(String email) async {
  final uri = Uri.parse('$endpoint/account/sessions/sso/providers')
      .replace(queryParameters: {'email': email});
  final response =
      await http.get(uri, headers: {'X-Appwrite-Project': projectId});
  final body = jsonDecode(response.body) as Map<String, dynamic>;
  if (response.statusCode != 200) throw Exception(body['message']);
  return [
    for (final p in body['providers'] as List)
      (id: p[r'$id'] as String, name: p['name'] as String),
  ];
}`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'lib/sso.dart',
        language: 'dart',
        code: `const callbackScheme = '${scheme}';

/// Opens the identity provider and returns the URL it came back to.
Future<Uri> openSSOSignIn(String providerId) async {
  final state = base64UrlEncode(
    List.generate(24, (_) => Random.secure().nextInt(256)),
  );
  final uri = Uri.parse('$endpoint/account/tokens/sso').replace(
    queryParameters: {
      'project': projectId,
      'providerId': providerId,
      'success': '$callbackScheme://sso/success',
      'failure': '$callbackScheme://sso/failure',
      'state': state,
    },
  );
  final result = Uri.parse(await FlutterWebAuth2.authenticate(
    url: uri.toString(),
    callbackUrlScheme: callbackScheme,
  ));
  if (result.queryParameters['state'] != state) {
    throw Exception('This sign-in was not started here.');
  }
  return result;
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'lib/sso.dart',
        language: 'dart',
        code: `final account = Account(
  Client().setEndpoint(endpoint).setProject(projectId),
);

Future<void> signInWithSSO(String providerId) async {
  final result = await openSSOSignIn(providerId);
  final error = result.queryParameters['error'];
  if (error != null) {
    // { message, type, code }
    throw Exception(jsonDecode(error)['message']);
  }
  await account.createSession(
    userId: result.queryParameters['userId']!,
    secret: result.queryParameters['secret']!,
  );
}`,
      },
      {
        id: 'manifest',
        title: MANIFEST_TITLE,
        description:
          'Android only: let flutter_web_auth_2 receive the callback. iOS needs nothing.',
        file: 'android/app/src/main/AndroidManifest.xml',
        language: 'markup',
        code: `<!-- Inside <application> -->
<activity
    android:name="com.linusu.flutter_web_auth_2.CallbackActivity"
    android:exported="true">
    <intent-filter android:label="flutter_web_auth_2">
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="${scheme}" />
    </intent-filter>
</activity>`,
      },
      {
        id: 'gradle',
        title: 'Turn on desugaring',
        description:
          'Android only: the Appwrite SDK needs core library desugaring, or the build fails.',
        file: 'android/app/build.gradle.kts',
        language: 'kotlin',
        code: `android {
    compileOptions {
        isCoreLibraryDesugaringEnabled = true
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.5")
}`,
      },
    ],
  }
}

function reactNativeGuide({
  endpoint,
  projectId,
}: SSOSignInInput): SSOSignInGuide {
  const scheme = ssoCallbackScheme(projectId)
  return {
    install: {
      language: 'bash',
      code: 'npx expo install react-native-appwrite react-native-url-polyfill expo-web-browser expo-crypto',
    },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'lib/sso.ts',
        language: 'typescript',
        code: `// Imports for the whole file: steps 2 and 3 go below.
import 'react-native-url-polyfill/auto'
import * as Crypto from 'expo-crypto'
import * as WebBrowser from 'expo-web-browser'
import { Account, Client } from 'react-native-appwrite'

export const ENDPOINT = '${endpoint}'
export const PROJECT_ID = '${projectId}'

export type IdentityProvider = { $id: string; name: string }

// No SDK method yet: call the endpoint directly.
export async function findIdentityProviders(
  email: string,
): Promise<IdentityProvider[]> {
  const url = new URL(\`\${ENDPOINT}/account/sessions/sso/providers\`)
  url.searchParams.set('email', email)
  const response = await fetch(url.href, {
    headers: { 'X-Appwrite-Project': PROJECT_ID },
  })
  const body = await response.json()
  if (!response.ok) throw new Error(body.message)
  return body.providers
}`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'lib/sso.ts',
        language: 'typescript',
        code: `const CALLBACK = '${scheme}://sso'

/** Opens the identity provider; null when the person cancels. */
export async function openSSOSignIn(providerId: string) {
  const state = Crypto.randomUUID()
  const url = new URL(\`\${ENDPOINT}/account/tokens/sso\`)
  url.searchParams.set('project', PROJECT_ID)
  url.searchParams.set('providerId', providerId)
  url.searchParams.set('success', \`\${CALLBACK}/success\`)
  url.searchParams.set('failure', \`\${CALLBACK}/failure\`)
  url.searchParams.set('state', state)

  const result = await WebBrowser.openAuthSessionAsync(url.href, CALLBACK)
  if (result.type !== 'success') return null
  const params = new URL(result.url).searchParams
  if (params.get('state') !== state) {
    throw new Error('This sign-in was not started here.')
  }
  return params
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'lib/sso.ts',
        language: 'typescript',
        code: `const account = new Account(
  new Client()
    .setEndpoint(ENDPOINT)
    .setProject(PROJECT_ID)
    .setPlatform('com.example.app'), // your bundle ID / package name
)

export async function signInWithSSO(providerId: string) {
  const params = await openSSOSignIn(providerId)
  if (!params) return
  const error = params.get('error')
  if (error) throw new Error(JSON.parse(error).message)

  await account.createSession({
    userId: params.get('userId')!,
    secret: params.get('secret')!,
  })
}`,
      },
      {
        id: 'manifest',
        title: MANIFEST_TITLE,
        description: 'Register the scheme so the system hands the callback back to the app.',
        file: 'app.json',
        language: 'json',
        code: `{
  "expo": {
    "scheme": "${scheme}"
  }
}`,
      },
    ],
  }
}

function appleGuide({ endpoint, projectId }: SSOSignInInput): SSOSignInGuide {
  return {
    install: {
      language: 'plaintext',
      code: 'Xcode > File > Add Package Dependencies: https://github.com/appwrite/sdk-for-apple',
    },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'SSO.swift',
        language: 'swift',
        code: `import Foundation

let endpoint = "${endpoint}"
let projectId = "${projectId}"

struct IdentityProvider: Decodable, Identifiable {
    let id: String
    let name: String
    enum CodingKeys: String, CodingKey { case id = "$id", name }
}

// No SDK method yet: call the endpoint directly.
func findIdentityProviders(email: String) async throws -> [IdentityProvider] {
    var components = URLComponents(string: "\\(endpoint)/account/sessions/sso/providers")!
    components.queryItems = [URLQueryItem(name: "email", value: email)]
    var request = URLRequest(url: components.url!)
    request.setValue(projectId, forHTTPHeaderField: "X-Appwrite-Project")
    let (data, response) = try await URLSession.shared.data(for: request)
    guard (response as? HTTPURLResponse)?.statusCode == 200 else {
        throw URLError(.badServerResponse)
    }
    struct List: Decodable { let providers: [IdentityProvider] }
    return try JSONDecoder().decode(List.self, from: data).providers
}`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'SSO.swift',
        language: 'swift',
        code: `let callbackScheme = "appwrite-callback-\\(projectId)"

func ssoSignInURL(providerId: String, state: String) -> URL {
    var components = URLComponents(string: "\\(endpoint)/account/tokens/sso")!
    components.queryItems = [
        URLQueryItem(name: "project", value: projectId),
        URLQueryItem(name: "providerId", value: providerId),
        URLQueryItem(name: "success", value: "\\(callbackScheme)://sso/success"),
        URLQueryItem(name: "failure", value: "\\(callbackScheme)://sso/failure"),
        URLQueryItem(name: "state", value: state),
    ]
    return components.url!
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'SSOButton.swift',
        language: 'swift',
        code: `import Appwrite
import AuthenticationServices
import SwiftUI

let account = Account(Client().setEndpoint(endpoint).setProject(projectId))

struct SSOButton: View {
    let provider: IdentityProvider
    @Environment(\\.webAuthenticationSession) private var webAuthenticationSession

    var body: some View {
        Button("Continue with \\(provider.name)") {
            Task {
                do { try await signIn() } catch { print(error) } // show it to the person
            }
        }
    }

    func signIn() async throws {
        let state = UUID().uuidString
        let callback = try await webAuthenticationSession.authenticate(
            using: ssoSignInURL(providerId: provider.id, state: state),
            callbackURLScheme: callbackScheme
        )
        let query = URLComponents(url: callback, resolvingAgainstBaseURL: false)?.queryItems ?? []
        func value(_ name: String) -> String? { query.first { $0.name == name }?.value }

        guard value("state") == state else { throw SSOError("This sign-in was not started here.") }
        guard let userId = value("userId"), let secret = value("secret") else {
            // On failure, "error" holds JSON with message, type and code, "+" for spaces.
            let error = value("error")?.replacingOccurrences(of: "+", with: " ") ?? "{}"
            let json = try? JSONSerialization.jsonObject(with: Data(error.utf8)) as? [String: Any]
            throw SSOError(json?["message"] as? String ?? "Sign-in failed.")
        }
        _ = try await account.createSession(userId: userId, secret: secret)
    }
}

struct SSOError: LocalizedError {
    let errorDescription: String?
    init(_ message: String) { errorDescription = message }
}`,
      },
    ],
  }
}

function androidGuide({ endpoint, projectId }: SSOSignInInput): SSOSignInGuide {
  const scheme = ssoCallbackScheme(projectId)
  return {
    install: {
      language: 'kotlin',
      code: `// app/build.gradle.kts
dependencies {
    implementation("io.appwrite:sdk-for-android:+") // pin the latest release
    implementation("androidx.browser:browser:1.8.0")
}`,
    },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'SSO.kt',
        language: 'kotlin',
        code: `// Imports for the whole file: step 2 goes below.
import android.content.Context
import android.net.Uri
import androidx.browser.customtabs.CustomTabsIntent
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

const val ENDPOINT = "${endpoint}"
const val PROJECT_ID = "${projectId}"
const val CALLBACK_SCHEME = "${scheme}"

data class IdentityProvider(val id: String, val name: String)

// No SDK method yet: call the endpoint directly.
suspend fun findIdentityProviders(email: String): List<IdentityProvider> =
    withContext(Dispatchers.IO) {
        val query = URLEncoder.encode(email, "UTF-8")
        val connection = URL("$ENDPOINT/account/sessions/sso/providers?email=$query")
            .openConnection() as HttpURLConnection
        connection.setRequestProperty("X-Appwrite-Project", PROJECT_ID)
        val body = JSONObject(connection.inputStream.bufferedReader().readText())
        val providers = body.getJSONArray("providers")
        List(providers.length()) { i ->
            val p = providers.getJSONObject(i)
            IdentityProvider(p.getString("\\$id"), p.getString("name"))
        }
    }`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'SSO.kt',
        language: 'kotlin',
        code: `fun openSSOSignIn(context: Context, providerId: String) {
    val state = UUID.randomUUID().toString()
    context.getSharedPreferences("sso", Context.MODE_PRIVATE)
        .edit().putString("state", state).apply()

    val uri = Uri.parse("$ENDPOINT/account/tokens/sso").buildUpon()
        .appendQueryParameter("project", PROJECT_ID)
        .appendQueryParameter("providerId", providerId)
        .appendQueryParameter("success", "$CALLBACK_SCHEME://sso/success")
        .appendQueryParameter("failure", "$CALLBACK_SCHEME://sso/failure")
        .appendQueryParameter("state", state)
        .build()
    CustomTabsIntent.Builder().build().launchUrl(context, uri)
}`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'SSOCallbackActivity.kt',
        language: 'kotlin',
        code: `import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.lifecycle.lifecycleScope
import io.appwrite.Client
import io.appwrite.services.Account
import kotlinx.coroutines.launch
import org.json.JSONObject

class SSOCallbackActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val data = intent.data ?: return finish()
        val prefs = getSharedPreferences("sso", MODE_PRIVATE)
        val state = prefs.getString("state", null)
        prefs.edit().remove("state").apply()

        lifecycleScope.launch {
            // On failure, "error" holds JSON with message, type and code.
            var error = data.getQueryParameter("error")?.let { JSONObject(it).optString("message") }
            val userId = data.getQueryParameter("userId")
            val secret = data.getQueryParameter("secret")
            if (state == null || data.getQueryParameter("state") != state) {
                error = "This sign-in was not started here."
            } else if (userId != null && secret != null) {
                val client = Client(applicationContext)
                    .setEndpoint(ENDPOINT)
                    .setProject(PROJECT_ID)
                runCatching { Account(client).createSession(userId = userId, secret = secret) }
                    .onFailure { error = it.message }
            }
            // Show ssoError, when set, on your sign-in screen.
            startActivity(
                Intent(this@SSOCallbackActivity, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP)
                    .putExtra("ssoError", error),
            )
            finish()
        }
    }
}`,
      },
      {
        id: 'manifest',
        title: MANIFEST_TITLE,
        description: 'The Custom Tab hands the callback to this activity.',
        file: 'AndroidManifest.xml',
        language: 'markup',
        code: `<!-- Inside <application> -->
<activity
    android:name=".SSOCallbackActivity"
    android:exported="true"
    android:launchMode="singleTask">
    <intent-filter>
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="${scheme}" android:host="sso" />
    </intent-filter>
</activity>`,
      },
    ],
  }
}

function pythonGuide({ endpoint, projectId }: SSOSignInInput): SSOSignInGuide {
  return {
    install: { language: 'bash', code: 'pip install appwrite flask requests' },
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'app.py',
        language: 'python',
        code: `# Imports for the whole file: steps 2 and 3 go below.
import os
import secrets
from datetime import datetime
from urllib.parse import urlencode

import requests
from appwrite.client import Client
from appwrite.services.account import Account
from flask import Flask, redirect, request, session, url_for

ENDPOINT = '${endpoint}'
PROJECT_ID = '${projectId}'

app = Flask(__name__)
app.secret_key = os.environ['FLASK_SECRET_KEY']


# No SDK method yet: call the endpoint directly.
def find_identity_providers(email):
    response = requests.get(
        f'{ENDPOINT}/account/sessions/sso/providers',
        params={'email': email},
        headers={'X-Appwrite-Project': PROJECT_ID},
    )
    response.raise_for_status()
    return response.json()['providers']  # [{'$id': ..., 'name': ...}]`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'app.py',
        language: 'python',
        code: `@app.get('/auth/sso')
def start_sso():
    state = secrets.token_urlsafe(24)
    session['sso_state'] = state
    params = {
        'project': PROJECT_ID,
        'providerId': request.args['providerId'],
        'success': url_for('sso_callback', _external=True),
        'failure': url_for('sign_in', _external=True),  # your sign-in page
        'state': state,
    }
    return redirect(f'{ENDPOINT}/account/tokens/sso?{urlencode(params)}')`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'app.py',
        language: 'python',
        code: `@app.get('/auth/sso/callback')
def sso_callback():
    state = session.pop('sso_state', None)
    if not state or request.args.get('state') != state:
        return redirect(url_for('sign_in'))

    # API key with the sessions.write scope. Server only.
    admin = Client()
    admin.set_endpoint(ENDPOINT).set_project(PROJECT_ID).set_key(
        os.environ['APPWRITE_API_KEY']
    )
    created = Account(admin).create_session(
        user_id=request.args['userId'], secret=request.args['secret']
    )

    # Later requests: Client()...set_session(cookie)
    response = redirect('/')
    response.set_cookie(
        f'a_session_{PROJECT_ID}',
        created.secret,
        httponly=True,
        secure=True,
        samesite='Lax',
        expires=datetime.fromisoformat(created.expire),
    )
    return response`,
      },
    ],
  }
}

function httpGuide({
  endpoint,
  projectId,
  exampleEmail,
  exampleProvider,
}: SSOSignInInput): SSOSignInGuide {
  const api = new URL(endpoint)
  const base = api.pathname.replace(/\/$/, '')
  const provider = exampleProvider ?? { $id: 'PROVIDER_ID', name: 'Okta' }
  const lookup = new URLSearchParams({ email: exampleEmail })
  const query = [
    ['project', projectId],
    ['providerId', provider.$id],
    ['success', 'https://example.com/auth/sso/callback'],
    ['failure', 'https://example.com/sign-in'],
    ['state', 'RANDOM_STATE'],
  ]
    .map(
      ([key, value], index) =>
        `  ${index === 0 ? '?' : '&'}${key}=${encodeURIComponent(value)}`,
    )
    .join('\n')
  return {
    steps: [
      {
        id: 'find',
        title: FIND_TITLE,
        description: FIND_DESCRIPTION,
        file: 'GET /account/sessions/sso/providers',
        language: 'http',
        code: `GET ${base}/account/sessions/sso/providers?${lookup} HTTP/1.1
Host: ${api.host}
X-Appwrite-Project: ${projectId}

HTTP/1.1 200 OK
Content-Type: application/json

{
  "total": 1,
  "providers": [{ "$id": ${JSON.stringify(provider.$id)}, "name": ${JSON.stringify(provider.name)} }]
}`,
      },
      {
        id: 'send',
        title: SEND_TITLE,
        description: SEND_DESCRIPTION,
        file: 'GET /account/tokens/sso',
        language: 'http',
        code: `# Send the browser here (a redirect or a link, not fetch).
# One URL, split over lines to read:
GET ${endpoint}/account/tokens/sso
${query}

# It signs in at the identity provider, then comes back to one of:
https://example.com/auth/sso/callback?userId=USER_ID&secret=SECRET&state=RANDOM_STATE
https://example.com/sign-in?error={"message":"…","type":"…","code":…}&state=RANDOM_STATE`,
      },
      {
        id: 'finish',
        title: FINISH_TITLE,
        description: FINISH_DESCRIPTION,
        file: 'POST /account/sessions/token',
        language: 'http',
        code: `POST ${base}/account/sessions/token HTTP/1.1
Host: ${api.host}
X-Appwrite-Project: ${projectId}
X-Appwrite-Key: API_KEY_WITH_SESSIONS_WRITE
Content-Type: application/json

{ "userId": "USER_ID", "secret": "SECRET" }

HTTP/1.1 201 Created
Content-Type: application/json

{ "$id": "…", "userId": "USER_ID", "secret": "…", "expire": "…" }

# From a browser, send it without the key: the response sets the
# session cookie instead of returning the secret.`,
      },
    ],
  }
}

export function buildSSOSignInGuide(
  platform: SSOSignInPlatform,
  input: SSOSignInInput,
): SSOSignInGuide {
  switch (platform) {
    case 'nextjs':
      return nextjsGuide(input)
    case 'sveltekit':
      return sveltekitGuide(input)
    case 'flutter':
      return flutterGuide(input)
    case 'react-native':
      return reactNativeGuide(input)
    case 'apple':
      return appleGuide(input)
    case 'android':
      return androidGuide(input)
    case 'python':
      return pythonGuide(input)
    case 'http':
      return httpGuide(input)
    case 'web':
    default:
      return webGuide(input)
  }
}

const SERVER_PLATFORMS: readonly SSOSignInPlatform[] = [
  'nextjs',
  'sveltekit',
  'python',
]
const NATIVE_PLATFORMS: readonly SSOSignInPlatform[] = [
  'flutter',
  'react-native',
  'apple',
  'android',
]

/**
 * The whole sign-in for `platform`, phrased for an AI coding agent: the
 * project's values, the flow, every file of the guide and the rules the code
 * alone does not show.
 */
export function buildSSOSignInPrompt(
  platform: SSOSignInPlatform,
  input: SSOSignInInput & {
    teamId: string
    teamName?: string
    providers: { $id: string; name: string }[]
    domains: string[]
  },
): string {
  const meta = SSO_SIGN_IN_PLATFORM_META[platform]
  const guide = buildSSOSignInGuide(platform, input)
  const fence = (language: string, code: string) =>
    `\`\`\`${language === 'markup' ? 'xml' : language}\n${code}\n\`\`\``
  const team = input.teamName
    ? `"${input.teamName}" (${input.teamId})`
    : input.teamId

  const lines = [
    `Add single sign-on (SSO) to this ${meta.subject} with Appwrite. People type their work email, choose their company's identity provider, sign in there and come back signed in to the app.`,
    '',
    'Context:',
    `- Appwrite endpoint: ${input.endpoint}`,
    `- Project ID: ${input.projectId}`,
    `- Team: ${team}. People who sign in this way join it with their identity provider's roles.`,
    ...(input.domains.length
      ? [`- Email domains that use SSO: ${input.domains.join(', ')}`]
      : []),
    ...(input.providers.length
      ? [
          `- Identity providers: ${input.providers
            .map((provider) => `${provider.name} (${provider.$id})`)
            .join(', ')}. Do not hard-code them; look them up by email as below.`,
        ]
      : []),
    '',
    'How it works:',
    '1. GET /account/sessions/sso/providers?email=… with the X-Appwrite-Project header answers { total, providers: [{ $id, name }] }.',
    '2. The browser goes to /account/tokens/sso with project, providerId, success, failure and state query parameters.',
    '3. It comes back to the success URL with userId, secret and state (or to the failure URL with error and state); userId + secret become a session with Account.createSession.',
    'No Appwrite SDK has methods for steps 1 and 2 yet. Call those endpoints exactly as below and do not invent SDK methods such as listSSOProviders or createSSOToken. Use the SDK for createSession only.',
    '',
  ]

  let step = 1
  if (guide.install) {
    lines.push(
      `${step++}. Install:`,
      fence(guide.install.language, guide.install.code),
      '',
    )
  }
  for (const item of guide.steps) {
    lines.push(
      `${step++}. ${item.title} (${item.file}):`,
      fence(item.language, item.code),
      '',
    )
  }

  lines.push(
    'Requirements:',
    '- Sign-in screen: an email field and a "Continue with SSO" button. No identity providers for the email: say it cannot use single sign-on and offer the app\'s other sign-in methods. One: go straight to it. Several: let the person choose by name.',
    '- Generate a new random state for every sign-in, keep it until the person comes back, and reject any return whose state does not match.',
    '- On the failure URL, parse the error query parameter (JSON with message, type and code) and show the message with a way to try again.',
  )
  if (platform === 'web' || platform === 'http' || SERVER_PLATFORMS.includes(platform)) {
    lines.push(
      `- The success and failure URLs must be https on a hostname of a platform added to project ${input.projectId} (localhost works for development). Tell me to add the app's domain under Overview > Platforms in the Appwrite Console if it is not there.`,
    )
  }
  if (SERVER_PLATFORMS.includes(platform)) {
    lines.push(
      '- Trade the token on the server with an API key that has only the sessions.write scope, read from the APPWRITE_API_KEY environment variable. Never expose it to the browser or commit it; tell me to create it in the Appwrite Console.',
      `- Keep the session in an httpOnly cookie named a_session_${input.projectId} and use it with Client.setSession on later requests. Signing out deletes the session with Account.deleteSession and clears the cookie.`,
    )
  }
  if (NATIVE_PLATFORMS.includes(platform)) {
    lines.push(
      `- Return to the ${ssoCallbackScheme(input.projectId)} scheme; Appwrite accepts it for every app of this project. Add the app itself as a platform of the project (bundle ID / package name) if it is not there yet.`,
    )
  }
  if (platform === 'android' || platform === 'flutter') {
    lines.push(
      `- If the app already registers an activity for the ${ssoCallbackScheme(input.projectId)} scheme (for example for OAuth2), make sure only one activity handles the sso host so Android does not show a chooser.`,
    )
  }
  lines.push(
    '- Await every Appwrite call before navigating, and check Account.get() to decide whether someone is signed in.',
  )

  return lines.join('\n')
}
