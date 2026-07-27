/**
 * Parses Appwrite audit `resource` path strings (slash-separated) used across
 * activity logs. Supported shapes mirror audit templates, for example:
 *
 * - `bucket/{id}`, `file/{id}`, `function/{id}`, `site/{id}`, `user/{id}`, `team/{id}`
 * - `database/{databaseId}`, `database/.../collection/...`, `.../document/...`,
 *   `.../table/...`, `.../row/...` (also `collections` → `collection`)
 * - `project/{projectId}`, `project.key/{id}`, `project.platform/{id}`, `project.variable/{id}`, …
 * - `message/{id}`, `topic/{id}`, `webhook/{id}`, `token/{id}`, `tokens/{id}`, `migrations/{id}`, …
 * - `vectorsdb/embeddings/text`, `identity/{id}`, `provider/{id}`, `subscriber/{id}`, …
 *
 * At runtime, `{request.*}` / `{response.*}` placeholders are real ids. Unknown
 * roots still return a parse object with `isRecognizedPattern: false`.
 *
 * Use {@link parseActivityResourcePath} everywhere we need structured access
 * to a `Models.ActivityEvent.resource` value (activity table, drawer, filters).
 */

/** One `key/value` segment pair after the root (or after `database/<id>`). */
export type ActivityResourcePathPair = {
  key: string
  value: string
}

export type ParsedActivityResource = {
  readonly raw: string
  /**
   * Normalized first-path namespace (`sites` → `site`). For `project.key/…`
   * this is still `project`.
   */
  readonly root: string
  /** `project.key` → `key`; `project.platform` → `platform`. Null for `project/<id>`. */
  readonly projectScope: string | null
  /** Present only for `database/<id>/…` trees. */
  readonly databaseId: string | null
  /** Remaining hierarchy (e.g. collection → document) after database id. */
  readonly pairs: readonly ActivityResourcePathPair[]
  /** Deepest id (last pair value, or single id after root / project scope). */
  readonly leafId: string | null
  /** Whether the path matched a known audit prefix shape. */
  readonly isRecognizedPattern: boolean
}

/** Activity log table / drawer / filter buckets (normalized from API + paths). */
export type ActivityUiResourceType =
  | 'document'
  | 'collection'
  | 'database'
  | 'file'
  | 'bucket'
  | 'function'
  | 'user'
  | 'team'
  | 'site'
  | 'rule'
  | 'project'

const SIMPLE_ROOTS = new Set([
  'bucket',
  'database',
  'file',
  'function',
  'identity',
  'message',
  'migrations',
  'provider',
  'report',
  'rule',
  'schedule',
  'site',
  'sites',
  'subscriber',
  'target',
  'team',
  'token',
  'tokens',
  'topic',
  'user',
  'vectorsdb',
  'webhook',
])

function normalizeRootSegment(segment: string): string {
  const s = segment.trim().toLowerCase()
  if (s === 'sites') return 'site'
  return s
}

function normalizePairKey(key: string): string {
  const k = key.trim().toLowerCase()
  if (k === 'collections') return 'collection'
  return k
}

function isProjectDottedScope(first: string): boolean {
  return (
    first.startsWith('project.') &&
    first.length > 'project.'.length &&
    first !== 'projections'
  )
}

/**
 * Parses `activity.resource` (and any future slash-delimited audit resource
 * strings) into a stable structure.
 */
export function parseActivityResourcePath(
  input: string | null | undefined,
): ParsedActivityResource | null {
  if (input == null) return null
  const raw = input.trim()
  if (!raw) return null

  const segments = raw
    .split('/')
    .map((s) => s.trim())
    .filter((s) => s.length > 0)
  if (segments.length === 0) return null

  const [first, ...rest] = segments
  const rootNorm = normalizeRootSegment(first)

  // project.<scope>/<id>…
  if (isProjectDottedScope(first)) {
    const scope = first.slice('project.'.length)
    const id = rest[0] ?? null
    const pairs: ActivityResourcePathPair[] =
      scope && id != null && id !== ''
        ? [{ key: scope, value: id }]
        : []
    return {
      raw,
      root: 'project',
      projectScope: scope || null,
      databaseId: null,
      pairs,
      leafId: id,
      isRecognizedPattern: true,
    }
  }

  // project/<id> (project resource itself)
  if (rootNorm === 'project' && rest.length >= 1) {
    const id = rest.join('/')
    return {
      raw,
      root: 'project',
      projectScope: null,
      databaseId: null,
      pairs: [],
      leafId: id || null,
      isRecognizedPattern: true,
    }
  }

  // database/<dbId>/[key/value]*
  if (rootNorm === 'database') {
    const databaseId = rest[0] ?? null
    const pairs: ActivityResourcePathPair[] = []
    for (let i = 1; i + 1 < rest.length; i += 2) {
      const key = normalizePairKey(rest[i]!)
      const value = rest[i + 1]!
      pairs.push({ key, value })
    }
    const leafId =
      pairs.length > 0 ? pairs[pairs.length - 1]!.value : databaseId
    return {
      raw,
      root: 'database',
      projectScope: null,
      databaseId,
      pairs,
      leafId: leafId ?? null,
      isRecognizedPattern: true,
    }
  }

  // vectorsdb/embeddings/text (no trailing ids)
  if (rootNorm === 'vectorsdb') {
    const pairs: ActivityResourcePathPair[] = []
    for (let i = 0; i + 1 < rest.length; i += 2) {
      pairs.push({
        key: normalizePairKey(rest[i]!),
        value: rest[i + 1]!,
      })
    }
    const leafId =
      pairs.length > 0 ? pairs[pairs.length - 1]!.value : rest[0] ?? null
    return {
      raw,
      root: 'vectorsdb',
      projectScope: null,
      databaseId: null,
      pairs,
      leafId,
      isRecognizedPattern: true,
    }
  }

  // Simple root / … (e.g. `bucket/id`, `user/id`, `target/a/b/c` with leaf `c`)
  if (SIMPLE_ROOTS.has(rootNorm)) {
    const pairs: ActivityResourcePathPair[] = []
    if (rest.length >= 2) {
      for (let i = 0; i + 1 < rest.length - 1; i += 2) {
        pairs.push({
          key: normalizePairKey(rest[i]!),
          value: rest[i + 1]!,
        })
      }
    }
    const leafId =
      rest.length > 0 ? rest[rest.length - 1]! : null
    return {
      raw,
      root: rootNorm,
      projectScope: null,
      databaseId: null,
      pairs,
      leafId,
      isRecognizedPattern: true,
    }
  }

  // Unknown root - still expose a best-effort parse for tooling.
  const id = rest[0] ?? null
  return {
    raw,
    root: rootNorm,
    projectScope: null,
    databaseId: null,
    pairs: [],
    leafId: id,
    isRecognizedPattern: false,
  }
}

/**
 * Maps a parsed audit `resource` path to the activity table / filter resource
 * buckets (same labels as the activity UI).
 */
export function inferActivityUiResourceTypeFromPath(
  parsed: ParsedActivityResource,
): ActivityUiResourceType {
  const { root, pairs } = parsed

  if (root === 'site') return 'site'
  if (root === 'rule') return 'rule'
  if (root === 'bucket') return 'bucket'
  if (root === 'file') return 'file'
  if (root === 'function') return 'function'
  if (root === 'user') return 'user'
  if (root === 'team') return 'team'

  if (root === 'database') {
    const keys = new Set(pairs.map((p) => p.key))
    if (keys.has('document')) return 'document'
    if (keys.has('collection')) return 'collection'
    return 'database'
  }

  if (root === 'project') {
    return 'project'
  }

  // Messaging, rules, tokens, sites infra, etc. - single “console” bucket in UI.
  return 'project'
}
