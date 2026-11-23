import type { APIRequestContext } from '@playwright/test'

export type LiveUsageSeed = {
  url: string
  method?: string
  body?: unknown
}

const SENSITIVE_NAME =
  /(?:authorization|cookie|password|secret|token|api[-_]?key)/i
const ALLOWED_METHODS = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])

function containsSensitiveBodyKey(value: unknown): boolean {
  if (Array.isArray(value)) return value.some(containsSensitiveBodyKey)
  if (!value || typeof value !== 'object') return false
  return Object.entries(value).some(
    ([key, entry]) =>
      SENSITIVE_NAME.test(key) || containsSensitiveBodyKey(entry),
  )
}

export function validateLiveUsageSeed(seed: LiveUsageSeed): LiveUsageSeed {
  const url = new URL(seed.url)
  if (url.username || url.password) {
    throw new Error('Usage seed URLs must not include credentials')
  }
  if (!['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)) {
    throw new Error('Usage seed URLs must target the local self-hosted backend')
  }
  for (const key of url.searchParams.keys()) {
    if (SENSITIVE_NAME.test(key)) {
      throw new Error(`Usage seed URL contains sensitive parameter: ${key}`)
    }
  }
  const method = (seed.method ?? 'GET').toUpperCase()
  if (!ALLOWED_METHODS.has(method)) {
    throw new Error(`Unsupported usage seed method: ${method}`)
  }
  if (containsSensitiveBodyKey(seed.body)) {
    throw new Error('Usage seed bodies must not contain sensitive fields')
  }
  return { url: url.toString(), method, body: seed.body }
}

export function parseLiveUsageSeeds(
  raw: string | undefined,
): Record<string, LiveUsageSeed> {
  if (!raw?.trim()) return {}
  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    throw new Error('E2E_USAGE_SEEDS_JSON must be valid JSON')
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error('E2E_USAGE_SEEDS_JSON must be an object')
  }
  return Object.fromEntries(
    Object.entries(parsed).map(([category, value]) => {
      if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error(`Usage seed for ${category} must be an object`)
      }
      const seed = value as { url?: unknown; method?: unknown; body?: unknown }
      if (typeof seed.url !== 'string') {
        throw new Error(`Usage seed for ${category} requires a URL`)
      }
      if (seed.method !== undefined && typeof seed.method !== 'string') {
        throw new Error(`Usage seed method for ${category} must be a string`)
      }
      return [
        category,
        validateLiveUsageSeed({
          url: seed.url,
          method: seed.method,
          body: seed.body,
        }),
      ]
    }),
  )
}

export async function seedLiveUsageActivity(
  request: APIRequestContext,
  seed: LiveUsageSeed,
  projectId: string,
): Promise<{ ok: boolean; status: number }> {
  const validated = validateLiveUsageSeed(seed)
  const response = await request.fetch(validated.url, {
    method: validated.method,
    headers: {
      'X-Appwrite-Project': projectId,
      ...(validated.body === undefined
        ? {}
        : { 'content-type': 'application/json' }),
    },
    data: validated.body,
  })
  return { ok: response.ok(), status: response.status() }
}
