import type { TurnToolView } from '@/lib/assistant/turn-view'

export type ToolSite = {
  /** Canonical site URL used for favicon fetch (usually origin). */
  url: string
  hostname: string
  /** Optional page title or original full URL for tooltip. */
  label?: string
}

/** Tools that scan or visit websites and should show a favicon stack. */
const SITE_VISUAL_TOOLS = new Set([
  'web_search',
  'browser_fetch',
  'browser_navigate',
  'web_fetch',
  'fetch_url',
  'browse_page',
])

const URL_STRING_RE = /https?:\/\/[^\s"'<>\\)\]]+/gi

const URL_KEYS = new Set([
  'url',
  'link',
  'href',
  'source',
  'website',
  'uri',
  'pageUrl',
  'page_url',
  'canonicalUrl',
  'canonical_url',
])

function cleanUrlCandidate(raw: string): string | null {
  let value = raw.trim()
  if (!value) return null
  // Trim common trailing punctuation from JSON / markdown extracts.
  value = value.replace(/[.,;:!?)]+$/g, '')
  try {
    const parsed = new URL(value)
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return null
    }
    return parsed.toString()
  } catch {
    return null
  }
}

function originUrl(href: string): string | null {
  try {
    const parsed = new URL(href)
    return parsed.origin
  } catch {
    return null
  }
}

function hostnameOf(href: string): string | null {
  try {
    return new URL(href).hostname.replace(/^www\./i, '')
  } catch {
    return null
  }
}

function addSite(
  sites: Map<string, ToolSite>,
  href: string,
  label?: string,
): void {
  const cleaned = cleanUrlCandidate(href)
  if (!cleaned) return
  const origin = originUrl(cleaned)
  const hostname = hostnameOf(cleaned)
  if (!origin || !hostname) return

  const existing = sites.get(origin)
  if (existing) {
    if (!existing.label && label) existing.label = label
    return
  }

  sites.set(origin, {
    url: origin,
    hostname,
    label: label || cleaned,
  })
}

function collectFromString(sites: Map<string, ToolSite>, value: string): void {
  URL_STRING_RE.lastIndex = 0
  let match: RegExpExecArray | null
  while ((match = URL_STRING_RE.exec(value)) !== null) {
    addSite(sites, match[0])
  }
}

function collectFromUnknown(
  sites: Map<string, ToolSite>,
  value: unknown,
  depth = 0,
): void {
  if (value == null || depth > 6) return

  if (typeof value === 'string') {
    const asUrl = cleanUrlCandidate(value)
    if (asUrl) {
      addSite(sites, asUrl)
      return
    }
    collectFromString(sites, value)
    return
  }

  if (Array.isArray(value)) {
    for (const item of value) {
      collectFromUnknown(sites, item, depth + 1)
    }
    return
  }

  if (typeof value !== 'object') return

  const record = value as Record<string, unknown>
  const title =
    typeof record.title === 'string'
      ? record.title
      : typeof record.name === 'string'
        ? record.name
        : undefined

  for (const [key, entry] of Object.entries(record)) {
    if (URL_KEYS.has(key) && typeof entry === 'string') {
      addSite(sites, entry, title)
      continue
    }
    collectFromUnknown(sites, entry, depth + 1)
  }
}

export function isSiteVisualTool(toolName?: string | null): boolean {
  if (!toolName) return false
  return SITE_VISUAL_TOOLS.has(toolName.toLowerCase())
}

/**
 * Extract unique websites referenced by a tool call (input + output).
 * Returns null when the tool is not a site-visual tool or no URLs were found.
 */
export function getToolVisualSites(
  tool: Pick<TurnToolView, 'name' | 'input' | 'output'>,
  limit = 8,
): ToolSite[] | null {
  if (!isSiteVisualTool(tool.name)) return null

  const sites = new Map<string, ToolSite>()
  collectFromUnknown(sites, tool.input)
  collectFromUnknown(sites, tool.output)

  if (sites.size === 0) return null
  return Array.from(sites.values()).slice(0, limit)
}
