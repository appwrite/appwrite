import type { LucideIcon } from 'lucide-react'
import {
  Code2,
  FileText,
  Globe,
  LayoutGrid,
  Mail,
  MessageSquare,
  Plus,
  Rocket,
  Smartphone,
} from 'lucide-react'
import { FRAMEWORK_ICON_MAP, getFrameworkIconFile } from '@/lib/frameworks/icons'

export type MarkdocIconSize = 's' | 'm' | 'l'

/** Fixed display size for framework/platform SVG icons across docs tables and lists. */
export const MARKDOC_BRAND_ICON_CLASS =
  'h-6 w-6 min-h-6 min-w-6 max-h-6 max-w-6 shrink-0 object-contain'

/** Inline Lucide icons in prose (e.g. plus in navigation paths). */
export const MARKDOC_INLINE_LUCIDE_ICON_CLASS = 'size-4 shrink-0'

/** @deprecated Prefer MARKDOC_BRAND_ICON_CLASS for consistent brand icon sizing. */
export const MARKDOC_ICON_SIZE_CLASSES: Record<MarkdocIconSize, string> = {
  s: 'size-4',
  m: 'size-6',
  l: 'size-6',
}

const MARKDOC_ICON_FILE_OVERRIDES: Record<string, string> = {
  node_js: 'node.svg',
  'bun-sh': 'bun.svg',
  js: 'js.svg',
  javascript: 'js.svg',
  ts: 'ts.svg',
  typescript: 'ts.svg',
  'icon-ts': 'ts.svg',
  'icon-typescript': 'ts.svg',
  cpp: 'cpp.svg',
  c: 'cpp.svg',
  'react-native': 'react-native.svg',
  dotnet: 'dotnet.svg',
  aws: 'amazon.svg',
  azure: 'microsoft.svg',
  openai: 'chatgpt.svg',
  codex: 'chatgpt.svg',
  gemini: 'google.svg',
  imagine: 'imagine.svg',
  'icon-node_js': 'node.svg',
  'icon-js': 'js.svg',
  'icon-dotnet': 'dotnet.svg',
  'icon-react-native': 'react-native.svg',
  'icon-vercel': 'vercel.svg',
  'web-icon-firebase': 'firebase.svg',
  'web-icon-github': 'github.svg',
  'web-icon-terraform': 'terraform.svg',
  'web-icon-mailgun': 'mailgun.svg',
  'web-icon-sendgrid': 'sendgrid.svg',
  'icon-twilio': 'twilio.svg',
  'icon-msg91': 'msg91.svg',
  'icon-vonage': 'vonage.svg',
  'icon-textmagic': 'textmagic.svg',
  'icon-telesign': 'telesign.svg',
}

const MARKDOC_LUCIDE_ICONS: Record<string, LucideIcon> = {
  plus: Plus,
  'icon-mail': Mail,
  'icon-annotation': MessageSquare,
  'icon-device-mobile': Smartphone,
  'icon-globe-alt': Globe,
  'icon-document-text': FileText,
  'icon-code-bracket': Code2,
  'icon-rocket-launch': Rocket,
  'icon-squares-2x2': LayoutGrid,
}

function stripMarkdocIconPrefix(icon: string): string {
  return icon.replace(/^(icon-|web-icon-)/, '')
}

function getMarkdocIconFile(name: string): string | null {
  const normalized = name.toLowerCase().replace(/_/g, '-')
  const override =
    MARKDOC_ICON_FILE_OVERRIDES[name] ??
    MARKDOC_ICON_FILE_OVERRIDES[normalized] ??
    MARKDOC_ICON_FILE_OVERRIDES[name.replace(/-/g, '_')]
  if (override) return override

  return getFrameworkIconFile(normalized) ?? getFrameworkIconFile(name)
}

export function resolveMarkdocIconByName(icon: string | undefined):
  | { type: 'image'; src: string }
  | { type: 'lucide'; Icon: LucideIcon }
  | null {
  if (!icon) return null

  const lucideIcon = MARKDOC_LUCIDE_ICONS[icon] ?? MARKDOC_LUCIDE_ICONS[`icon-${icon}`]
  if (lucideIcon) {
    return { type: 'lucide', Icon: lucideIcon }
  }

  const stripped = stripMarkdocIconPrefix(icon)
  const iconFile = getMarkdocIconFile(stripped)
  if (iconFile) {
    return { type: 'image', src: `/icons/${iconFile}` }
  }

  return null
}

/** Exact card titles mapped to icon lookup keys (frameworks and vendors). */
const CARD_TITLE_ICON_KEYS: Record<string, string> = {
  'next.js': 'nextjs',
  'next.js ssr': 'nextjs',
  react: 'react',
  'react native': 'react-native',
  vue: 'vue',
  'vue.js': 'vue',
  nuxt: 'nuxt',
  'nuxt ssr': 'nuxt',
  sveltekit: 'svelte',
  'sveltekit ssr': 'svelte',
  angular: 'angular',
  'astro ssr': 'astro',
  'tanstack start': 'tanstack',
  flutter: 'flutter',
  apple: 'apple',
  'apple (swift)': 'apple',
  android: 'android',
  'android (kotlin)': 'android',
  'android (java)': 'java',
  'node.js': 'node',
  python: 'python',
  php: 'php',
  ruby: 'ruby',
  '.net': 'dotnet',
  go: 'go',
  deno: 'deno',
  dart: 'dart',
  rust: 'rust',
  swift: 'swift',
  kotlin: 'kotlin',
  web: 'js',
  'vanilla js': 'js',
  remix: 'remix',
  qwik: 'qwik',
  solid: 'solid',
  refine: 'refine',
  astro: 'astro',
  vite: 'vite',
  'claude code': 'claude',
  'claude desktop': 'claude',
  codex: 'chatgpt',
  cursor: 'cursor-ai',
  'vs code': 'vscode',
  opencode: 'opencode',
  antigravity: 'google-antigravity',
  lovable: 'lovable',
  emergent: 'emergent',
  bolt: 'bolt',
  zenflow: 'zenflow',
  windsurf: 'windsurf',
  zed: 'zed',
  'amazon web services': 'amazon',
  digitalocean: 'digitalocean',
  coolify: 'coolify',
  rxdb: 'rxdb',
  'google cloud': 'google',
  'microsoft azure': 'microsoft',
  'migrating from vercel': 'vercel',
  'graphql api': 'graphql',
  github: 'github',
  'terraform registry': 'terraform',
  apns: 'apple',
  fcm: 'firebase',
  mailgun: 'mailgun',
  sendgrid: 'sendgrid',
  twilio: 'twilio',
  msg91: 'msg91',
  vonage: 'vonage',
  textmagic: 'textmagic',
  telesign: 'telesign',
}

function normalizeCardTitle(title: string): string {
  return title.toLowerCase().replace(/\s+/g, ' ').trim()
}

function isKnownBrandIconKey(key: string): boolean {
  const normalized = key.toLowerCase().replace(/\s+/g, '-').replace(/_/g, '-').replace(/\./g, '-')
  return Boolean(
    CARD_TITLE_ICON_KEYS[normalized] ??
      CARD_TITLE_ICON_KEYS[key.toLowerCase()] ??
      FRAMEWORK_ICON_MAP[normalized],
  )
}

function resolveMarkdocCardIconFromTitle(title: string | undefined):
  | { type: 'image'; src: string }
  | null {
  if (!title) return null

  const normalized = normalizeCardTitle(title)
  const iconKey = CARD_TITLE_ICON_KEYS[normalized]
  if (!iconKey) return null

  const iconFile = getMarkdocIconFile(iconKey)
  if (iconFile) return { type: 'image', src: `/icons/${iconFile}` }

  return null
}

function resolveMarkdocCardIconFromHref(href: string | undefined):
  | { type: 'image'; src: string }
  | null {
  if (!href) return null

  const segments = href.split('/').filter(Boolean)
  const lastSegment = segments.at(-1)
  if (!lastSegment || !isKnownBrandIconKey(lastSegment)) return null

  const iconFile = getMarkdocIconFile(lastSegment)
  if (iconFile) return { type: 'image', src: `/icons/${iconFile}` }

  return null
}

/** Resolves cards_item icons from icon, legacy image paths, title, or href. */
export function resolveMarkdocCardIcon({
  icon,
  image,
  title,
  href,
}: {
  icon?: string
  image?: string
  title?: string
  href?: string
}):
  | { type: 'image'; src: string }
  | { type: 'lucide'; Icon: LucideIcon }
  | null {
  if (icon) {
    const resolved = resolveMarkdocIconByName(icon)
    if (resolved) return resolved
  }

  if (image) {
    const src = resolveMarkdocIconImageSrc(image)
    if (src) return { type: 'image', src }
  }

  const fromTitle = resolveMarkdocCardIconFromTitle(title)
  if (fromTitle) return fromTitle

  return resolveMarkdocCardIconFromHref(href)
}

/** Maps legacy /images/docs/* logo paths to /icons/*. Never serves imported docs SVGs. */
export function resolveMarkdocIconImageSrc(src: string | undefined): string | null {
  if (!src) return null

  if (src.startsWith('/images/docs/') && src.endsWith('.svg')) {
    const filename = src.split('/').pop()?.replace(/\.svg$/i, '')
    if (!filename) return null

    const iconFile = getMarkdocIconFile(filename)
    if (iconFile) {
      return `/icons/${iconFile}`
    }

    return null
  }

  const filename = src.split('/').pop()?.replace(/\.svg$/i, '')
  if (!filename) return null

  const iconFile = getMarkdocIconFile(filename)
  if (iconFile) {
    return `/icons/${iconFile}`
  }

  return null
}
