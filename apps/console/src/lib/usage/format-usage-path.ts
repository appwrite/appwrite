const OBJECT_ID_PATTERN = /^[a-f0-9]{20,}$/i
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

/** Known REST path tokens - not resource IDs. */
const STATIC_PATH_SEGMENTS = new Set([
  'account',
  'attributes',
  'auth',
  'avatars',
  'blocks',
  'buckets',
  'challenges',
  'collections',
  'columns',
  'console',
  'databases',
  'deployments',
  'documents',
  'download',
  'executions',
  'factors',
  'files',
  'functions',
  'graphql',
  'health',
  'identities',
  'identity',
  'indexes',
  'invites',
  'jwt',
  'keys',
  'labels',
  'locale',
  'logs',
  'memberships',
  'messages',
  'messaging',
  'mfa',
  'migrations',
  'oauth2',
  'platforms',
  'preferences',
  'preview',
  'project',
  'projects',
  'providers',
  'proxy',
  'realtime',
  'recovery',
  'rest',
  'rows',
  'rules',
  'sessions',
  'sites',
  'stats',
  'storage',
  'subscribers',
  'tables',
  'tablesdb',
  'targets',
  'teams',
  'tokens',
  'topics',
  'uploads',
  'usage',
  'users',
  'v1',
  'v2',
  'variables',
  'verification',
  'view',
  'webhooks',
])

function isPathIdSegment(segment: string): boolean {
  if (!segment || segment.length <= 4) return false
  if (segment.includes('{') || segment.includes('}')) return false
  if (STATIC_PATH_SEGMENTS.has(segment.toLowerCase())) return false
  if (OBJECT_ID_PATTERN.test(segment) || UUID_PATTERN.test(segment)) return true
  if (segment.length >= 16 && /^[a-zA-Z0-9_-]+$/.test(segment)) return true
  if (segment.length >= 8 && /^[a-f0-9]+$/i.test(segment)) return true
  return false
}

/** Shorten resource IDs in HTTP paths for compact breakdown display. */
export function compactUsagePathIds(
  path: string,
  visibleSuffix = 4,
): string {
  if (!path) return path

  return path
    .split('/')
    .map((segment) => {
      if (!segment || !isPathIdSegment(segment)) return segment
      if (segment.length <= visibleSuffix) return segment
      return `...${segment.slice(-visibleSuffix)}`
    })
    .join('/')
}
