/**
 * Human-readable descriptions for OAuth2 / OIDC scopes shown on the consent
 * screen.
 *
 * RAR contract v2: the `scope` parameter carries every privilege the client
 * requests. Identity scopes (`openid`/`profile`/`email`/`phone`) shape the OIDC
 * claims; project scopes (`project:*`, `project:users.read`, …) and organization
 * scopes (`organization:*`, `organization:projects.read`, …) grant tiers of
 * access that `authorization_details` then binds to concrete resources.
 */
import {
  User,
  Mail,
  IdCard,
  KeyRound,
  ShieldCheck,
  Smartphone,
  type LucideIcon,
} from 'lucide-react'

export interface ScopeDescriptor {
  id: string
  title: string
  description: string
  icon: LucideIcon
}

export const ALL_SCOPE = 'all'
export const PROJECT_ALL_SCOPE = 'project:all'
export const ORGANIZATION_ALL_SCOPE = 'organization:all'
export const PROJECT_SCOPE_PREFIX = 'project:'
export const ORGANIZATION_SCOPE_PREFIX = 'organization:'

/** OIDC identity scopes, in the order they should be listed. */
export const IDENTITY_SCOPES = ['openid', 'profile', 'email', 'phone'] as const

/**
 * This consent screen always authorizes against the Appwrite **console**
 * project. On the server, any OAuth2 access token issued for the console
 * project is granted the full `users` (member) role - the same access a
 * signed-in console session has - regardless of the OIDC scopes requested
 * (see app/init/resources/request.php and app/config/roles.php in the cloud
 * backend). The `openid`/`profile`/`email` scopes only shape the OIDC identity
 * claims; they do NOT limit what the application can do. So the consent screen
 * must lead with the full-access reality rather than implying read-only access.
 */
export const FULL_ACCESS_SCOPE: ScopeDescriptor = {
  id: ALL_SCOPE,
  title: 'Full access to your account',
  description:
    'Manage all your organizations, projects, and their resources on your behalf.',
  icon: ShieldCheck,
}

const BUILTIN_SCOPES: Record<string, Omit<ScopeDescriptor, 'id'>> = {
  openid: {
    title: 'Verify your identity',
    description: 'Confirm who you are using your Appwrite account.',
    icon: IdCard,
  },
  profile: {
    title: 'View your profile',
    description: 'Read your name and profile details.',
    icon: User,
  },
  email: {
    title: 'View your email address',
    description: "Read your account's email address.",
    icon: Mail,
  },
  phone: {
    title: 'View your phone number',
    description: "Read your account's phone number.",
    icon: Smartphone,
  },
  [ALL_SCOPE]: {
    title: FULL_ACCESS_SCOPE.title,
    description: FULL_ACCESS_SCOPE.description,
    icon: ShieldCheck,
  },
}

function titleizeScope(scope: string): string {
  const cleaned = scope.replace(/[._:-]+/g, ' ').trim()
  if (!cleaned) return scope
  return cleaned.charAt(0).toUpperCase() + cleaned.slice(1)
}

export function describeScope(scope: string): ScopeDescriptor {
  const builtin = BUILTIN_SCOPES[scope]
  if (builtin) {
    return { id: scope, ...builtin }
  }
  return {
    id: scope,
    title: titleizeScope(scope),
    description: `Access to ${scope}.`,
    icon: KeyRound,
  }
}

export function describeScopes(scopes: string[]): ScopeDescriptor[] {
  return scopes.map(describeScope)
}

// Identity scopes shown (in this order) as secondary detail beneath the
// full-access item. `openid` is intentionally omitted - identity verification
// is implied by full account access, so listing it separately is redundant.
const CONSENT_IDENTITY_SCOPES = ['profile', 'email'] as const

/**
 * Build the flat permission list (legacy). Leads with the full-access item,
 * followed by the identity scopes actually requested. Kept for back-compat.
 */
export function describeConsentScopes(scopes: string[]): ScopeDescriptor[] {
  const requested = new Set(scopes)
  const identity = CONSENT_IDENTITY_SCOPES.filter((scope) =>
    requested.has(scope),
  ).map(describeScope)
  return [FULL_ACCESS_SCOPE, ...identity]
}

/* -------------------------------------------------------------------------- */
/*  Consent scope model - split into identity / full / project / org tiers    */
/* -------------------------------------------------------------------------- */

export interface TierScopes {
  all: boolean
  scopes: string[]
}

export interface ConsentScopeModel {
  identity: ScopeDescriptor[]
  all: ScopeDescriptor | null
  project: TierScopes
  organization: TierScopes
}

export interface PermissionLine {
  title: string
  description?: string
  /** Stable, collision-free key: the underlying scope tokens joined by space. */
  token: string
  access?: string
  accessStrong?: boolean
}

export interface PermissionGroup {
  heading: string
  note?: string
  /** Whether the consent screen lets the user collapse this group. */
  collapsible?: boolean
  lines: PermissionLine[]
}

function collectTier(
  scopes: string[],
  prefix: string,
  allScope: string,
): TierScopes {
  let all = false
  const seen = new Set<string>()
  const collected: string[] = []
  for (const scope of scopes) {
    if (scope === allScope) {
      all = true
      continue
    }
    if (scope.startsWith(prefix)) {
      const rest = scope.slice(prefix.length)
      if (rest && !seen.has(rest)) {
        seen.add(rest)
        collected.push(rest)
      }
    }
  }
  return { all, scopes: collected }
}

/** Split a grant's scopes into the identity / full / project / org tiers. */
export function splitConsentScopes(scopes: string[]): ConsentScopeModel {
  const requested = new Set(scopes)
  const identity = IDENTITY_SCOPES.filter((scope) => requested.has(scope)).map(
    describeScope,
  )
  return {
    identity,
    all: requested.has(ALL_SCOPE) ? describeScope(ALL_SCOPE) : null,
    project: collectTier(scopes, PROJECT_SCOPE_PREFIX, PROJECT_ALL_SCOPE),
    organization: collectTier(
      scopes,
      ORGANIZATION_SCOPE_PREFIX,
      ORGANIZATION_ALL_SCOPE,
    ),
  }
}

/* -------------------------------------------------------------------------- */
/*  Per-resource copy - one readable line per resource a scope can touch       */
/* -------------------------------------------------------------------------- */

interface ResourceCopy {
  name: string
  desc: string
}

const PROJECT_RESOURCE_COPY: Record<string, ResourceCopy> = {
  project: {
    name: 'Project settings',
    desc: "This project's general settings, name, and configuration.",
  },
  keys: {
    name: 'API keys',
    desc: "API keys that grant server-side access to this project's resources.",
  },
  platforms: {
    name: 'Platforms',
    desc: 'The web, mobile, and native app platforms registered with this project.',
  },
  mocks: {
    name: 'Mock numbers',
    desc: 'Mock phone numbers used to test phone authentication flows.',
  },
  'project.policies': {
    name: 'Project policies',
    desc: "This project's security and access policies.",
  },
  policies: {
    name: 'Policies',
    desc: "Legacy access to this project's security and backup policies.",
  },
  'project.oauth2': {
    name: 'OAuth2 configuration',
    desc: "This project's OAuth2 authorization server configuration.",
  },
  templates: {
    name: 'Templates',
    desc: "The project's customizable email and SMS message templates.",
  },
  stages: {
    name: 'Stages',
    desc: 'Deployment stages used to promote changes across environments.',
  },
  oauth2: {
    name: 'OAuth2',
    desc: "This project's OAuth2 provider configuration and token introspection.",
  },
  users: {
    name: 'Users',
    desc: 'End-user accounts, including their profiles, preferences, and identifiers.',
  },
  sessions: {
    name: 'Sessions',
    desc: "Active login sessions belonging to this project's users.",
  },
  teams: {
    name: 'Teams',
    desc: 'Teams and their memberships, used to group and organize users.',
  },
  databases: {
    name: 'Databases',
    desc: 'Databases and their overall configuration within this project.',
  },
  tables: {
    name: 'Tables',
    desc: 'Database tables along with their columns, indexes, and structure.',
  },
  columns: {
    name: 'Columns',
    desc: 'The columns that define the structure of your database tables.',
  },
  indexes: {
    name: 'Indexes',
    desc: 'The indexes that speed up queries against your database tables.',
  },
  rows: {
    name: 'Rows',
    desc: 'The individual rows of data stored inside your database tables.',
  },
  collections: {
    name: 'Collections',
    desc: 'Legacy database collections and their structure, replaced by tables.',
  },
  attributes: {
    name: 'Attributes',
    desc: 'Legacy collection attributes that define document structure, replaced by columns.',
  },
  documents: {
    name: 'Documents',
    desc: 'Legacy documents stored inside your collections, replaced by rows.',
  },
  buckets: {
    name: 'Storage buckets',
    desc: 'Storage buckets and their file-level permission and security settings.',
  },
  files: {
    name: 'Files',
    desc: 'Files stored in your buckets, including uploads, downloads, and previews.',
  },
  tokens: {
    name: 'File tokens',
    desc: 'Access tokens that grant shareable links to individual storage files.',
  },
  functions: {
    name: 'Functions',
    desc: 'Serverless functions along with their code deployments and configuration.',
  },
  executions: {
    name: 'Executions',
    desc: 'The execution history and logs of your serverless functions.',
  },
  execution: {
    name: 'Executions (legacy)',
    desc: 'Legacy access to function executions, replaced by Executions.',
  },
  sites: {
    name: 'Sites',
    desc: 'Hosted sites and their deployments, builds, and configuration.',
  },
  log: {
    name: 'Site logs',
    desc: 'Runtime and build logs produced by your sites.',
  },
  providers: {
    name: 'Messaging providers',
    desc: 'Messaging providers used to send email, SMS, and push notifications.',
  },
  topics: {
    name: 'Topics',
    desc: 'Messaging topics that group subscribers for targeted broadcasts.',
  },
  subscribers: {
    name: 'Subscribers',
    desc: 'Subscribers enrolled in your messaging topics.',
  },
  targets: {
    name: 'Targets',
    desc: 'The delivery targets (email, phone, or device) attached to your users.',
  },
  messages: {
    name: 'Messages',
    desc: 'Email, SMS, and push messages, including drafts and delivery status.',
  },
  rules: {
    name: 'Proxy rules',
    desc: "Proxy rules that route custom domains to this project's resources.",
  },
  webhooks: {
    name: 'Webhooks',
    desc: 'Webhooks that notify external services when project events occur.',
  },
  locale: {
    name: 'Locale',
    desc: 'The Locale service for reading locale, language, and geo information.',
  },
  avatars: {
    name: 'Avatars',
    desc: 'The Avatars service for generating avatars, icons, flags, and QR codes.',
  },
  health: {
    name: 'Health',
    desc: "The health and operational status of this project's services.",
  },
  assistant: {
    name: 'AI Assistant',
    desc: 'Legacy AI Assistant scope. Prefer agent scopes for the console Agent.',
  },
  agent: {
    name: 'Agent',
    desc: 'The console Agent that suggests answers and configuration.',
  },
  migrations: {
    name: 'Migrations',
    desc: 'Data migrations that import from or export to other projects.',
  },
  schedules: {
    name: 'Schedules',
    desc: 'Scheduled tasks that run functions or messages at set times.',
  },
  vcs: {
    name: 'Git',
    desc: 'Connected Git repositories used to deploy functions and sites.',
  },
  insights: {
    name: 'Advisor insights',
    desc: 'Advisor insights that surface recommendations for your project.',
  },
  reports: {
    name: 'Advisor reports',
    desc: "Advisor reports generated from your project's activity.",
  },
  presences: {
    name: 'Presence',
    desc: 'Realtime presence data showing which users are currently online.',
  },
  'backups.policies': {
    name: 'Backup policies',
    desc: 'Policies that define when and how your data is backed up.',
  },
  archives: {
    name: 'Backup archives',
    desc: "Backup archives captured from this project's data.",
  },
  restorations: {
    name: 'Restorations',
    desc: 'Restore operations that recover data from backup archives.',
  },
  dedicatedDatabases: {
    name: 'Dedicated SQL',
    desc: 'Direct SQL access to run statements against dedicated databases.',
  },
  domains: {
    name: 'Domains',
    desc: 'Custom domains connected to this project.',
  },
  events: {
    name: 'Events',
    desc: 'The realtime and system events emitted by this project.',
  },
  apps: {
    name: 'OAuth2 apps',
    desc: 'OAuth2 applications registered to authorize against this project.',
  },
  usage: {
    name: 'Usage',
    desc: "Usage statistics and metrics for this project's resources.",
  },
}

const ORGANIZATION_RESOURCE_COPY: Record<string, ResourceCopy> = {
  projects: {
    name: 'Projects',
    desc: "The names, IDs, and settings of this organization's projects, but not the data inside them.",
  },
  'organization.keys': {
    name: 'Organization keys',
    desc: 'Organization-level API keys that authorize access across projects.',
  },
  keys: {
    name: 'Organization keys (legacy)',
    desc: 'Legacy access to organization API keys, replaced by Organization keys.',
  },
  devKeys: {
    name: 'Development keys',
    desc: 'Development keys used to bypass rate limits while building locally.',
  },
  'organization.memberships': {
    name: 'Organization memberships',
    desc: 'Memberships that control who belongs to this organization and their roles.',
  },
  organization: {
    name: 'Organization',
    desc: "This organization's name, settings, and other general configuration.",
  },
  domains: {
    name: 'Organization domains',
    desc: 'Custom domains owned and managed at the organization level.',
  },
}

/* -------------------------------------------------------------------------- */
/*  Grouping - collapse resource+action scopes into readable permission lines  */
/* -------------------------------------------------------------------------- */

function actionRank(action: string): number {
  if (action === 'read') return 0
  if (action === 'write') return 1
  return 2
}

/** The action of a bare scope token - `read` in `tables.read`. */
export function scopeAction(scope: string): string {
  const dot = scope.lastIndexOf('.')
  return dot === -1 ? scope : scope.slice(dot + 1)
}

/** The resource of a bare scope token - `tables` in `tables.read`. */
export function scopeResource(scope: string): string {
  const dot = scope.lastIndexOf('.')
  return dot === -1 ? scope : scope.slice(0, dot)
}

const actionOf = scopeAction
const resourceOf = scopeResource

function accessRank(actions: string[]): number {
  const set = new Set(actions)
  const read = set.has('read')
  const write = set.has('write')
  if (read && write) return 0
  if (write) return 1
  if (read) return 3
  return 2 // non-CRUD (e.g. execute)
}

interface ResourceDescription {
  title: string
  description?: string
  access: string
  accessStrong: boolean
}

function describeResource(
  resource: string,
  actions: string[],
  copyMap: Record<string, ResourceCopy>,
): ResourceDescription {
  const copy = copyMap[resource]
  const title = copy?.name ?? titleizeScope(resource)
  const description = copy?.desc
  const set = new Set(actions)
  const read = set.has('read')
  const write = set.has('write')

  let access: string
  let accessStrong: boolean
  if (read && write) {
    access = 'Read + Write'
    accessStrong = true
  } else if (write) {
    access = 'Write'
    accessStrong = true
  } else if (read) {
    access = 'Read'
    accessStrong = false
  } else {
    access = actions[0] ? titleizeScope(actions[0]) : 'Access'
    accessStrong = true
  }
  return { title, description, access, accessStrong }
}

function tierLines(
  tier: TierScopes,
  prefix: string,
  allScope: string,
  copyMap: Record<string, ResourceCopy>,
): PermissionLine[] {
  const lines: PermissionLine[] = []

  if (tier.all) {
    const isProject = prefix === PROJECT_SCOPE_PREFIX
    lines.push({
      title: isProject ? 'Full project access' : 'Full organization access',
      description: `Grant every available permission on the selected ${
        isProject ? 'projects' : 'organizations'
      }.`,
      token: allScope,
    })
  }

  // Group tier scopes by their resource so tables.read + tables.write collapse
  // into a single "Tables" row.
  const groups = new Map<string, string[]>()
  const order: string[] = []
  for (const scope of tier.scopes) {
    const resource = resourceOf(scope)
    if (!groups.has(resource)) {
      groups.set(resource, [])
      order.push(resource)
    }
    groups.get(resource)!.push(scope)
  }

  const rows = order.map((resource) => {
    const scopes = groups
      .get(resource)!
      .slice()
      .sort((a, b) => actionRank(actionOf(a)) - actionRank(actionOf(b)))
    const actions = scopes.map(actionOf)
    const described = describeResource(resource, actions, copyMap)
    return {
      resource,
      actions,
      line: {
        title: described.title,
        description: described.description,
        token: scopes.map((scope) => prefix + scope).join(' '),
        access: described.access,
        accessStrong: described.accessStrong,
      } as PermissionLine,
    }
  })

  // Strongest grants lead; stable sort preserves requested order for ties.
  rows.sort((a, b) => accessRank(a.actions) - accessRank(b.actions))
  for (const row of rows) lines.push(row.line)
  return lines
}

/** Build the grouped permission list for the console OAuth2 consent screen. */
export function buildConsentPermissions(
  model: ConsentScopeModel,
): PermissionGroup[] {
  const groups: PermissionGroup[] = []

  // Account group.
  const accountLines: PermissionLine[] = []
  if (model.identity.length > 0) {
    accountLines.push({
      title: 'View your identity',
      description: 'Confirm who you are and read your basic profile details.',
      token: model.identity.map((scope) => scope.id).join(' '),
    })
  }
  if (model.all) {
    accountLines.push({
      title: model.all.title,
      description: model.all.description,
      token: model.all.id,
    })
  }
  if (accountLines.length > 0) {
    groups.push({ heading: 'Account', lines: accountLines })
  }

  // Projects group.
  const projectLines = tierLines(
    model.project,
    PROJECT_SCOPE_PREFIX,
    PROJECT_ALL_SCOPE,
    PROJECT_RESOURCE_COPY,
  )
  if (projectLines.length > 0) {
    groups.push({
      heading: 'Projects',
      note: 'Applies only to the projects you select below.',
      collapsible: true,
      lines: projectLines,
    })
  }

  // Organizations group.
  const organizationLines = tierLines(
    model.organization,
    ORGANIZATION_SCOPE_PREFIX,
    ORGANIZATION_ALL_SCOPE,
    ORGANIZATION_RESOURCE_COPY,
  )
  if (organizationLines.length > 0) {
    groups.push({
      heading: 'Organizations',
      note: 'Applies only to the organizations you select below.',
      collapsible: true,
      lines: organizationLines,
    })
  }

  return groups
}

/* -------------------------------------------------------------------------- */
/*  Editor rows - per-resource rows for the MCP consent narrowing editor      */
/* -------------------------------------------------------------------------- */

export interface EditorRow {
  /** Resource key (everything before the last dot), stable editor identity. */
  resource: string
  /** Display title from the resource catalog, e.g. "Tables". */
  title: string
  /** One-line description of what the resource covers. */
  description?: string
  /** Requested actions for this resource, `read` before `write` before others. */
  actions: string[]
  hasRead: boolean
  hasWrite: boolean
  /** Access chip label for the full requested access, e.g. "Read + Write". */
  access: string
  /** Whether the chip should read as elevated (write access). */
  accessStrong: boolean
}

/**
 * Group one tier's requested scopes into per-resource editor rows for the MCP
 * consent narrowing editor. Same grouping and copy as the read-only summary,
 * but keyed by resource so each row can be toggled and its access level set.
 */
export function buildTierEditorRows(
  tier: TierScopes,
  prefix: string,
): EditorRow[] {
  const copyMap =
    prefix === PROJECT_SCOPE_PREFIX
      ? PROJECT_RESOURCE_COPY
      : ORGANIZATION_RESOURCE_COPY
  const groups = new Map<string, string[]>()
  const order: string[] = []
  for (const scope of tier.scopes) {
    const resource = resourceOf(scope)
    if (!groups.has(resource)) {
      groups.set(resource, [])
      order.push(resource)
    }
    groups.get(resource)!.push(scope)
  }
  return order.map((resource) => {
    const scopes = groups
      .get(resource)!
      .slice()
      .sort((a, b) => actionRank(actionOf(a)) - actionRank(actionOf(b)))
    const actions = scopes.map(actionOf)
    const { title, description, access, accessStrong } = describeResource(
      resource,
      actions,
      copyMap,
    )
    return {
      resource,
      title,
      description,
      actions,
      hasRead: actions.includes('read'),
      hasWrite: actions.includes('write'),
      access,
      accessStrong,
    }
  })
}
