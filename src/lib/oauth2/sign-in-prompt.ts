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

/** Best-effort SDK enum member name from provider id (e.g. github -> Github). */
function toOAuthProviderEnumMember(providerId: string): string {
  if (!providerId) return providerId
  return providerId.charAt(0).toUpperCase() + providerId.slice(1)
}

export function buildOAuth2SignInPrompt(options: {
  projectId: string
  endpoint: string
  providerId: string
  providerName?: string
  iconSvg?: string
}): string {
  const providerName =
    options.providerName || getOAuth2ProviderDisplayName(options.providerId)
  const providerEnum = toOAuthProviderEnumMember(options.providerId)

  const lines = [
    `Add a "Sign in with ${providerName}" button to my app using Appwrite Auth OAuth2.`,
    '',
    'Docs: https://appwrite.io/docs/products/auth/oauth2',
    '',
    'Project details:',
    `- Project ID: ${options.projectId}`,
    `- API endpoint: ${options.endpoint}`,
    `- OAuth provider ID: ${options.providerId}`,
    `- Provider display name: ${providerName}`,
    `- SDK enum (Web/JS): OAuthProvider.${providerEnum}`,
    '',
    'Use the Appwrite client Account service. Pick the flow that matches my stack:',
    '',
    '### Browser / SPA: Account.createOAuth2Session',
    'Navigate the user to the provider, then Appwrite redirects back to my success/failure URLs.',
    '```ts',
    `import { Client, Account, OAuthProvider } from "appwrite";`,
    '',
    'const client = new Client()',
    `  .setEndpoint("${options.endpoint}")`,
    `  .setProject("${options.projectId}");`,
    '',
    'const account = new Account(client);',
    '',
    'account.createOAuth2Session({',
    `  provider: OAuthProvider.${providerEnum}, // or "${options.providerId}"`,
    `  success: "https://example.com/auth/success",`,
    `  failure: "https://example.com/auth/failure",`,
    '  // scopes: [] // optional, provider-specific',
    '});',
    '```',
    '',
    '### SSR / React Native / deep link: Account.createOAuth2Token + Account.createSession',
    '1. Call account.createOAuth2Token({ provider, success, failure }) to get the login URL.',
    '2. Open that URL (browser or auth session) and wait for the redirect back to my app.',
    '3. From the redirect URL query params, read userId and secret.',
    '4. Complete sign-in with account.createSession({ userId, secret }).',
    '```ts',
    `const loginUrl = await account.createOAuth2Token({`,
    `  provider: OAuthProvider.${providerEnum}, // or "${options.providerId}"`,
    `  success: deepLink,`,
    `  failure: deepLink,`,
    '});',
    '// Open loginUrl, listen for redirect, then:',
    'const url = new URL(resultUrl);',
    `const secret = url.searchParams.get("secret");`,
    `const userId = url.searchParams.get("userId");`,
    'await account.createSession({ userId, secret });',
    '```',
    '',
    'UI requirements:',
    `1. Label the button "Sign in with ${providerName}".`,
    '2. Style a clear primary CTA. Include the provider icon next to the label.',
    `3. This provider is (or will be) enabled in Auth > Social providers for project ${options.projectId}.`,
    '4. Do not put OAuth client secrets in frontend code. Secrets stay in the Appwrite Console.',
    '5. Prefer real success/failure (or deep link) URLs for my app instead of example.com placeholders.',
  ]

  if (options.iconSvg?.trim()) {
    lines.push(
      '',
      'Use this SVG markup for the button icon:',
      '```svg',
      options.iconSvg.trim(),
      '```',
    )
  }

  lines.push(
    '',
    'Implement the sign-in button and OAuth click handler only. Do not change unrelated app structure.',
  )

  return lines.join('\n')
}
