import {
  getPublicIconPickerFilenames,
  PUBLIC_ICON_PICKER_EXCLUDED,
} from '@/lib/public-icons/manifest'
import {
  getCoverIconPreviewClasses,
} from '@/lib/cover-generator/cover-icon-tone'
import type { CoverThemeId } from '@/lib/cover-generator/themes'

export type CoverBuiltInIconEntry = {
  path: string
  label: string
}

export type CoverBuiltInIconCategory = {
  id: string
  label: string
  icons: CoverBuiltInIconEntry[]
}

/** Curated groupings for /public/icons in the cover generator picker. */
const COVER_BUILT_IN_ICON_CATEGORY_DEFINITIONS: Omit<CoverBuiltInIconCategory, 'icons'>[] =
  [
    { id: 'appwrite', label: 'Appwrite' },
    { id: 'frameworks', label: 'Frameworks' },
    { id: 'languages', label: 'Languages' },
    { id: 'ai', label: 'AI & IDEs' },
    { id: 'cloud', label: 'Cloud & hosting' },
    { id: 'devtools', label: 'Dev tools' },
    { id: 'design', label: 'Design & storage' },
    { id: 'browsers', label: 'Browsers' },
    { id: 'auth', label: 'Auth & OAuth' },
    { id: 'messaging', label: 'Messaging' },
    { id: 'payments', label: 'Payments' },
    { id: 'social', label: 'Social' },
    { id: 'business', label: 'Business' },
  ]

const COVER_BUILT_IN_ICON_CATEGORY_ICONS: Record<string, string[]> = {
  appwrite: ['appwrite.svg', 'appwrite-white.svg', 'open-source.svg'],
  frameworks: [
    'react.svg',
    'react-native.svg',
    'nextjs.svg',
    'remix.svg',
    'tanstack.svg',
    'vue.svg',
    'nuxt.svg',
    'angular.svg',
    'analog.svg',
    'svelte.svg',
    'solid.svg',
    'refine.svg',
    'astro.svg',
    'vite.svg',
    'qwik.svg',
    'docusaurus.svg',
    'lynx.svg',
  ],
  languages: [
    'js.svg',
    'ts.svg',
    'node.svg',
    'deno.svg',
    'bun.svg',
    'python.svg',
    'dart.svg',
    'php.svg',
    'ruby.svg',
    'dotnet.svg',
    'go.svg',
    'rust.svg',
    'swift.svg',
    'kotlin.svg',
    'java.svg',
    'cpp.svg',
    'flutter.svg',
    'android.svg',
    'apple.svg',
    'ios.svg',
    'html5.svg',
    'css3.svg',
    'linux.svg',
    'code.svg',
  ],
  ai: [
    'anthropic.svg',
    'claude.svg',
    'chatgpt.svg',
    'cursor-ai.svg',
    'opencode.svg',
    'windsurf.svg',
    'zed.svg',
    'vscode.svg',
    'vs_code.svg',
    'lovable.svg',
    'emergent.svg',
    'bolt.svg',
    'zenflow.svg',
    'google-antigravity.svg',
    'imagine.svg',
    'perplexity.svg',
    'elevenlabs.svg',
    'hugging-face.svg',
  ],
  cloud: [
    'amazon.svg',
    'google.svg',
    'microsoft.svg',
    'vercel.svg',
    'digitalocean.svg',
    'coolify.svg',
    'docker.svg',
    'terraform.svg',
    'firebase.svg',
    'supabase.svg',
    'nhost.svg',
    'globe.svg',
    'rclone.svg',
    'cyberduck.svg',
  ],
  devtools: [
    'github.svg',
    'github-circle.svg',
    'gitlab.svg',
    'bitbucket.svg',
    'git.svg',
    'gitea.svg',
    'gogs.svg',
    'forgejo.svg',
    'graphql.svg',
    'npm.svg',
    'pnpm.svg',
    'redis.svg',
    'mongo-db.svg',
    'neon.svg',
    'neo4j.svg',
    'upstash.svg',
    'algolia.svg',
    'rxdb.svg',
    'sentry.svg',
    'appsignal.svg',
    'raygun.svg',
    'wordpress.svg',
  ],
  design: ['figma.svg', 'box.svg', 'dropbox.svg', 'autodesk.svg', 'unity.svg'],
  browsers: ['firefox.svg', 'opera.svg', 'safari.svg', 'microsoft_edge.svg'],
  auth: [
    'auth0.svg',
    'authentik.svg',
    'oidc.svg',
    'okta.svg',
    'discord-simple.svg',
    'discord.svg',
    'notion.svg',
    'x.svg',
    'facebook.svg',
    'linkedin.svg',
  ],
  messaging: [
    'mailgun.svg',
    'sendgrid.svg',
    'twilio.svg',
    'msg91.svg',
    'vonage.svg',
    'textmagic.svg',
    'telesign.svg',
    'resend.svg',
    'slack.svg',
    'whatsapp.svg',
    'telegram.svg',
    'mqtt.svg',
    'skype.svg',
  ],
  payments: ['stripe.svg', 'paypal.svg', 'lemon-squeezy.svg'],
  social: [
    'twitter.svg',
    'youtube.svg',
    'instagram.svg',
    'tiktok.svg',
    'reddit.svg',
    'medium.svg',
    'product-hunt.svg',
    'ycombinator.svg',
    'dribbble.svg',
    'behance.svg',
    'spotify.svg',
    'twitch.svg',
    'vimeo.svg',
    'vk.svg',
    'pinterest.svg',
    'yahoo.svg',
    'disqus.svg',
    'dailymotion.svg',
    'daily-dev.svg',
    'bitly.svg',
    'etsy.svg',
    'tumbir.svg',
    'yandex.svg',
  ],
  business: [
    'salesforce.svg',
    'zoho.svg',
    'podio.svg',
    'zoom.svg',
    'yammer.svg',
    'tradeshift.svg',
  ],
}

export function formatCoverBuiltInIconLabel(filename: string): string {
  return filename
    .replace(/\.svg$/i, '')
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function createPublicIconEntry(filename: string): CoverBuiltInIconEntry {
  return {
    path: `/icons/${filename}`,
    label: formatCoverBuiltInIconLabel(filename),
  }
}

function buildCoverBuiltInIconCategories(): CoverBuiltInIconCategory[] {
  const pickerFilenames = new Set(getPublicIconPickerFilenames())
  const categorized = new Set<string>()

  const categories = COVER_BUILT_IN_ICON_CATEGORY_DEFINITIONS.map((definition) => {
    const icons = (COVER_BUILT_IN_ICON_CATEGORY_ICONS[definition.id] ?? [])
      .filter((filename) => {
        if (!pickerFilenames.has(filename)) return false
        categorized.add(filename)
        return true
      })
      .map(createPublicIconEntry)

    return {
      ...definition,
      icons,
    }
  }).filter((category) => category.icons.length > 0)

  const uncategorized = getPublicIconPickerFilenames()
    .filter((filename) => !categorized.has(filename))
    .sort((a, b) => a.localeCompare(b))

  if (uncategorized.length > 0) {
    categories.push({
      id: 'other',
      label: 'Other',
      icons: uncategorized.map(createPublicIconEntry),
    })
  }

  return categories
}

export const COVER_BUILT_IN_ICON_CATEGORIES = buildCoverBuiltInIconCategories()

const COVER_BUILT_IN_ICON_PATH_SET = new Set(
  COVER_BUILT_IN_ICON_CATEGORIES.flatMap((category) =>
    category.icons.map((icon) => icon.path),
  ),
)

export const COVER_BUILT_IN_ICONS = COVER_BUILT_IN_ICON_CATEGORIES.flatMap(
  (category) => category.icons,
).sort((a, b) => a.label.localeCompare(b.label))

/** @deprecated Use icon.path from CoverBuiltInIconEntry. */
export function getCoverBuiltInIconPath(filename: string): string {
  return `/icons/${filename}`
}

export function isCoverBuiltInIconPath(value: string | undefined): boolean {
  if (!value) return false
  return COVER_BUILT_IN_ICON_PATH_SET.has(value)
}

export function isCoverPickerIconValue(value: string | undefined): boolean {
  if (!value) return false
  return isCoverBuiltInIconPath(value) || value.startsWith('lucide:')
}

export function getCoverBuiltInIconEntry(
  value: string | undefined,
): CoverBuiltInIconEntry | null {
  if (!isCoverBuiltInIconPath(value)) return null
  return COVER_BUILT_IN_ICONS.find((icon) => icon.path === value) ?? null
}

/** @deprecated Use getCoverBuiltInIconEntry(value)?.path and match by path. */
export function getCoverBuiltInIconFilename(value: string | undefined): string | null {
  if (!value?.startsWith('/icons/')) return null
  const filename = value.slice('/icons/'.length)
  if (PUBLIC_ICON_PICKER_EXCLUDED.has(filename)) return null
  if (!getPublicIconPickerFilenames().includes(filename)) return null
  return filename
}

export function searchCoverBuiltInIcons(query: string): CoverBuiltInIconEntry[] {
  const normalized = query.trim().toLowerCase()
  if (!normalized) return COVER_BUILT_IN_ICONS

  return COVER_BUILT_IN_ICONS.filter((icon) => {
    const filename = icon.path.split('/').pop()?.toLowerCase() ?? ''
    return (
      filename.includes(normalized) ||
      icon.label.toLowerCase().includes(normalized) ||
      icon.path.toLowerCase().includes(normalized)
    )
  })
}

export function getCoverBuiltInIconPreviewClasses(themeId: CoverThemeId): string {
  return getCoverIconPreviewClasses(themeId)
}
