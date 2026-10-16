import { getFrameworkIconFile } from '@/lib/frameworks/icons'

/** Integration slug to icon filename in /public/icons/. */
export const INTEGRATION_SLUG_ICON_MAP: Record<string, string> = {
  'ai-elevenlabs-text-to-speech': 'elevenlabs.svg',
  'ai-hugging-face-image-classification': 'hugging-face.svg',
  'ai-hugging-face-language-translation': 'hugging-face.svg',
  'ai-hugging-face-speech-recognition': 'hugging-face.svg',
  'ai-openai': 'chatgpt.svg',
  'ai-perplexity': 'perplexity.svg',
  'deployments-github': 'github.svg',
  'email-sendgrid': 'sendgrid.svg',
  'flutterflow-auth-kit': 'flutter.svg',
  'lemon-squeezy-payments': 'lemon-squeezy.svg',
  'lemon-squeezy-subscriptions': 'lemon-squeezy.svg',
  'logging-appsignal': 'appsignal.svg',
  'logging-raygun': 'raygun.svg',
  'logging-sentry': 'sentry.svg',
  'mcp-claude': 'claude.svg',
  'mcp-cursor': 'cursor-ai.svg',
  'mcp-windsurf': 'windsurf.svg',
  'native-auth-apple': 'apple.svg',
  'oauth-amazon': 'amazon.svg',
  'oauth-apple': 'apple.svg',
  'oauth-discord': 'discord-simple.svg',
  'oauth-google': 'google.svg',
  'oauth-notion': 'notion.svg',
  'oauth-x': 'x.svg',
  'phone-auth-twilio': 'twilio.svg',
  'push-apns': 'apple.svg',
  'push-fcm': 'firebase.svg',
  'query-mongodb': 'mongo-db.svg',
  'query-upstash': 'upstash.svg',
  'replication-rxdb': 'rxdb.svg',
  'search-algolia': 'algolia.svg',
  'self-hosted-mongodb': 'mongo-db.svg',
  'sites-docusaurus': 'docusaurus.svg',
  'sites-magic-portfolio': 'appwrite.svg',
  'sites-nxtlnk': 'appwrite.svg',
  'sites-react-admin': 'react.svg',
  'sites-starlight': 'astro.svg',
  'sites-vuepress': 'vue.svg',
  'sms-twilio': 'twilio.svg',
  'storage-s3': 'amazon.svg',
  'stripe-payments': 'stripe.svg',
  'stripe-subscriptions': 'stripe.svg',
  'terraform-provider': 'terraform.svg',
  'whatsapp-vonage': 'vonage.svg',
}

const VENDOR_ICON_ALIASES: Record<string, string> = {
  anthropic: 'anthropic.svg',
  aws: 'amazon.svg',
  firebase: 'firebase.svg',
  mongodb: 'mongo-db.svg',
  'react admin': 'react.svg',
  starlight: 'astro.svg',
  vuepress: 'vue.svg',
  'magic portfolio': 'appwrite.svg',
  nxtlnk: 'appwrite.svg',
  flutterflow: 'flutter.svg',
  'lemon squeezy': 'lemon-squeezy.svg',
  'hugging face': 'hugging-face.svg',
  elevenlabs: 'elevenlabs.svg',
  openai: 'chatgpt.svg',
  cursor: 'cursor-ai.svg',
}

function normalizeVendorKey(value: string): string {
  return value.toLowerCase().replace(/\s+/g, ' ').trim()
}

export function getIntegrationIconFile(
  slug: string,
  vendor?: string,
): string | null {
  const slugIcon = INTEGRATION_SLUG_ICON_MAP[slug]
  if (slugIcon) return slugIcon

  if (vendor) {
    const normalizedVendor = normalizeVendorKey(vendor)
    const alias = VENDOR_ICON_ALIASES[normalizedVendor]
    if (alias) return alias

    const frameworkIcon = getFrameworkIconFile(vendor)
    if (frameworkIcon) return frameworkIcon
  }

  return null
}

export function getIntegrationIconPath(slug: string, vendor?: string): string | null {
  const file = getIntegrationIconFile(slug, vendor)
  return file ? `/icons/${file}` : null
}
