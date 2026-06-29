/**
 * Events editor – shared data model and utilities
 * Used for building Appwrite event strings: service.[id].resource.[id].action.[attribute?]
 */

export type EventAction = {
  name: string
  columns?: string[]
}

export type EventResource = {
  name: string
  subResources?: EventResource[]
  actions?: EventAction[]
}

export type EventService = {
  name: string
  resources?: EventResource[]
  actions?: EventAction[]
}

export const EVENT_SERVICES: EventService[] = [
  {
    name: 'buckets',
    resources: [
      {
        name: 'files',
        actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
      },
    ],
    actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
  },
  {
    name: 'databases',
    resources: [
      {
        name: 'tables',
        actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
      },
      {
        name: 'columns',
        actions: [{ name: 'create' }, { name: 'delete' }],
      },
      {
        name: 'rows',
        actions: [
          { name: 'create' },
          { name: 'update' },
          { name: 'delete' },
          { name: 'upsert' },
        ],
      },
      {
        name: 'indexes',
        actions: [{ name: 'create' }, { name: 'delete' }],
      },
    ],
    actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
  },
  {
    name: 'functions',
    resources: [
      {
        name: 'deployments',
        actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
      },
      {
        name: 'executions',
        actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
      },
    ],
    actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
  },
  {
    name: 'teams',
    resources: [
      {
        name: 'memberships',
        actions: [
          { name: 'create' },
          { name: 'update', columns: ['status'] },
          { name: 'delete' },
        ],
      },
    ],
    actions: [
      { name: 'create' },
      { name: 'update', columns: ['prefs'] },
      { name: 'delete' },
    ],
  },
  {
    name: 'users',
    resources: [
      { name: 'recovery', actions: [{ name: 'create' }, { name: 'update' }] },
      { name: 'sessions', actions: [{ name: 'create' }, { name: 'delete' }] },
      {
        name: 'verification',
        actions: [{ name: 'create' }, { name: 'update' }],
      },
    ],
    actions: [
      { name: 'create' },
      {
        name: 'update',
        columns: ['email', 'name', 'password', 'status', 'prefs'],
      },
      { name: 'delete' },
    ],
  },
  {
    name: 'providers',
    actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
  },
  {
    name: 'topics',
    resources: [
      {
        name: 'subscribers',
        actions: [{ name: 'create' }, { name: 'delete' }],
      },
    ],
    actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
  },
  {
    name: 'messages',
    actions: [{ name: 'create' }, { name: 'update' }, { name: 'delete' }],
  },
]

export type EventBuilderSelection = {
  service: string | null
  resource: string | null // e.g. rows, files; for databases.rows we inject tables.* when building
  action: string | null
  attribute: string | null
  /** Specific IDs instead of * (when projectId available for API lookup) */
  databaseId?: string | '*'
  tableId?: string | '*'
  bucketId?: string | '*'
  functionId?: string | '*'
  teamId?: string | '*'
  userId?: string | '*'
  topicId?: string | '*'
  providerId?: string | '*'
  /** Resource instance IDs (file in bucket, row in table, column, index) */
  fileId?: string | '*'
  rowId?: string | '*'
  columnId?: string | '*'
  indexId?: string | '*'
}

export function buildEventString(sel: EventBuilderSelection): string {
  const parts: string[] = []
  if (!sel.service) return ''
  parts.push(sel.service)
  if (sel.service === 'databases' && sel.databaseId) {
    parts.push(sel.databaseId)
  } else if (sel.service === 'buckets' && sel.bucketId) {
    parts.push(sel.bucketId)
  } else if (sel.service === 'functions' && sel.functionId) {
    parts.push(sel.functionId)
  } else if (sel.service === 'teams' && sel.teamId) {
    parts.push(sel.teamId)
  } else if (sel.service === 'users' && sel.userId) {
    parts.push(sel.userId)
  } else if (sel.service === 'topics' && sel.topicId) {
    parts.push(sel.topicId)
  } else if (sel.service === 'providers' && sel.providerId) {
    parts.push(sel.providerId)
  } else {
    parts.push('*')
  }

  // databases: tables, columns, rows, indexes all require tables.[id] prefix
  if (sel.service === 'databases' && sel.resource === 'rows') {
    parts.push('tables')
    parts.push(sel.tableId ?? '*')
    parts.push('rows', sel.rowId ?? '*')
  } else if (sel.service === 'databases' && sel.resource === 'columns') {
    parts.push('tables')
    parts.push(sel.tableId ?? '*')
    parts.push('columns', sel.columnId ?? '*')
  } else if (sel.service === 'databases' && sel.resource === 'indexes') {
    parts.push('tables')
    parts.push(sel.tableId ?? '*')
    parts.push('indexes', sel.indexId ?? '*')
  } else if (sel.service === 'databases' && sel.resource === 'tables') {
    parts.push('tables')
    parts.push(sel.tableId ?? '*')
  } else if (sel.service === 'buckets' && sel.resource === 'files') {
    parts.push('files', sel.fileId ?? '*')
  } else if (sel.resource) {
    parts.push(sel.resource)
    parts.push('*') // resource ID - always * for other resources
  }

  if (sel.action) {
    parts.push(sel.action)
  }
  if (sel.attribute) {
    parts.push(sel.attribute)
  }
  return parts.join('.')
}

export function parseEventString(str: string): EventBuilderSelection | null {
  if (!str || typeof str !== 'string') return null
  const trimmed = str.trim()
  if (!trimmed) return null
  const parts = trimmed.split('.')
  const sel: EventBuilderSelection = {
    service: null,
    resource: null,
    action: null,
    attribute: null,
  }

  let i = 0
  if (parts.length <= i) return sel
  const svc = EVENT_SERVICES.find((s) => s.name === parts[i])
  if (!svc) return null
  sel.service = svc.name
  i++
  // First ID segment (database, bucket, function, team, user, topic, provider)
  if (parts[i] === '*') {
    i++
  } else if (parts[i] && /^[a-zA-Z0-9_-]+$/.test(parts[i])) {
    if (svc.name === 'databases') sel.databaseId = parts[i]
    else if (svc.name === 'buckets') sel.bucketId = parts[i]
    else if (svc.name === 'functions') sel.functionId = parts[i]
    else if (svc.name === 'teams') sel.teamId = parts[i]
    else if (svc.name === 'users') sel.userId = parts[i]
    else if (svc.name === 'topics') sel.topicId = parts[i]
    else if (svc.name === 'providers') sel.providerId = parts[i]
    i++
  }

  if (i >= parts.length) return sel

  // Check for resource
  if (svc.name === 'databases' && parts[i] === 'tables') {
    // tables.[id|*] or tables.[id|*].rows|columns|indexes.[id|*]
    i++ // skip 'tables'
    if (parts[i] && parts[i] !== '*') sel.tableId = parts[i]
    i++ // skip tableId or *
    if (parts[i] === 'rows') {
      sel.resource = 'rows'
      i++
      if (parts[i] && parts[i] !== '*') sel.rowId = parts[i]
      i++
    } else if (parts[i] === 'columns') {
      sel.resource = 'columns'
      i++
      if (parts[i] && parts[i] !== '*') sel.columnId = parts[i]
      i++
    } else if (parts[i] === 'indexes') {
      sel.resource = 'indexes'
      i++
      if (parts[i] && parts[i] !== '*') sel.indexId = parts[i]
      i++
    } else {
      sel.resource = 'tables'
    }
  } else if (svc.name === 'buckets' && parts[i] === 'files') {
    sel.resource = 'files'
    i++ // skip 'files'
    if (parts[i] && parts[i] !== '*') sel.fileId = parts[i]
    i++ // skip fileId or *
  } else if (svc.resources) {
    for (const r of svc.resources) {
      if (parts[i] === r.name) {
        sel.resource = r.name
        i += 2 // skip resource, *
        break
      }
    }
  }

  if (i >= parts.length) return sel

  // Action
  const actions = getActionsForSelection(sel)
  const actionNames = actions.map((a) => a.name)
  if (actionNames.includes(parts[i])) {
    sel.action = parts[i]
    i++
  }

  if (i >= parts.length) return sel

  // Attribute
  const act = actions.find((a) => a.name === sel.action)
  if (act?.columns?.includes(parts[i])) {
    sel.attribute = parts[i]
  }
  return sel
}

export function getResources(serviceName: string): EventResource[] {
  const svc = EVENT_SERVICES.find((s) => s.name === serviceName)
  return svc?.resources ?? []
}

export function getActions(serviceName: string): EventAction[] {
  const svc = EVENT_SERVICES.find((s) => s.name === serviceName)
  return svc?.actions ?? []
}

export function getResourceActions(
  serviceName: string,
  resourceName: string,
): EventAction[] {
  const svc = EVENT_SERVICES.find((s) => s.name === serviceName)
  const res = svc?.resources?.find((r) => r.name === resourceName)
  return res?.actions ?? []
}

export function getActionsForSelection(
  sel: EventBuilderSelection,
): EventAction[] {
  if (!sel.service) return []
  if (sel.resource) return getResourceActions(sel.service, sel.resource)
  return getActions(sel.service)
}

export const DOCS_LINK = '/docs/advanced/platform/events'
