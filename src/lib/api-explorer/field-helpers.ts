import type { ResourceIdType } from '@/components/global/shared/EventEditor/EventResourceIdSelector'
import type { FilterColumn } from '@/lib/table-filters'
import {
  activitiesFilterColumns,
  bucketsFilterColumns,
  databasesFilterColumns,
  deploymentsFilterColumns,
  executionsFilterColumns,
  filesFilterColumns,
  functionsFilterColumns,
  sitesFilterColumns,
  teamsFilterColumns,
  usersFilterColumns,
} from '@/lib/table-filters'
import type {
  RequestFormField,
  RequestFormFieldKind,
} from './request-form'
import type { ApiExplorerMethod, OpenApiParameter, OpenApiSchema } from './types'

export type { ResourceIdType }

export type RequestFormFieldHelper =
  | { type: 'resource-id'; resourceType: ResourceIdType }
  | { type: 'permissions'; withCreate?: boolean; withWrite?: boolean; executeOnly?: boolean }
  | { type: 'queries' }

const CREATABLE_ID_DESCRIPTION =
  /(?:choose a custom .{0,40}? id|generate a random id|id\.unique\(\))/i

const RESOURCE_ID_PARAM_NAMES: Record<string, ResourceIdType> = {
  databaseid: 'database',
  bucketid: 'bucket',
  fileid: 'file',
  tableid: 'table',
  collectionid: 'table',
  documentid: 'row',
  rowid: 'row',
  columnid: 'column',
  attributeid: 'column',
  key: 'column',
  indexid: 'index',
  functionid: 'function',
  siteid: 'site',
  teamid: 'team',
  userid: 'user',
  topicid: 'topic',
  providerid: 'provider',
}

export function resolveResourceIdType(paramName: string): ResourceIdType | null {
  const normalized = paramName.trim().toLowerCase()
  return RESOURCE_ID_PARAM_NAMES[normalized] ?? null
}

export function shouldOfferResourceIdPicker(
  schema: OpenApiSchema,
  paramName: string,
  paramIn?: OpenApiParameter['in'],
): boolean {
  if (isCreatableIdReference(schema, paramIn)) return false
  return resolveResourceIdType(paramName) !== null
}

function isCreatableIdReference(
  schema: OpenApiSchema,
  paramIn?: OpenApiParameter['in'],
): boolean {
  if (paramIn === 'path' || paramIn === 'query') return false
  if (schema.type && schema.type !== 'string') return false
  const description = schema.description?.trim() ?? ''
  if (!description) return false
  return CREATABLE_ID_DESCRIPTION.test(description)
}

export function isPermissionsField(
  name: string,
  kind: RequestFormFieldKind,
  schema?: OpenApiSchema,
): boolean {
  if (!kind.startsWith('array')) return false
  if (name === 'permissions') return true
  if (/permissions?/i.test(name)) return true
  const description = schema?.description?.toLowerCase() ?? ''
  return description.includes('permission')
}

export function isQueriesField(name: string, schema?: OpenApiSchema): boolean {
  if (name !== 'queries') return false
  const type = schema?.type
  if (type === 'array' || type === 'string') return true
  const description = schema?.description?.toLowerCase() ?? ''
  return description.includes('query class') || description.includes('query strings')
}

function isStorageFilePermissionsContext(
  method: ApiExplorerMethod | undefined,
): boolean {
  if (!method || method.service !== 'storage') return false
  return /\/files(?:\/|$)/i.test(method.path)
}

function isResourceCreateMethod(method: ApiExplorerMethod | undefined): boolean {
  if (!method || method.httpMethod !== 'post') return false
  const summary = method.summary ?? ''
  if (!/create/i.test(summary)) return false

  if (method.service === 'databases') return true
  if (method.path.includes('/tables') || method.path.includes('/collections')) {
    return true
  }

  if (method.service === 'storage') {
    return (
      method.path.includes('/buckets') &&
      !isStorageFilePermissionsContext(method)
    )
  }

  return false
}

export function getPermissionsEditorOptions(
  method: ApiExplorerMethod | undefined,
  fieldName: string,
): Pick<
  RequestFormFieldHelper & { type: 'permissions' },
  'withCreate' | 'withWrite' | 'executeOnly'
> {
  if (fieldName === 'execute' && method?.service === 'functions') {
    return { executeOnly: true }
  }

  if (isStorageFilePermissionsContext(method)) {
    return { withCreate: false, withWrite: true }
  }

  if (isResourceCreateMethod(method)) {
    return { withCreate: true }
  }

  return { withCreate: false }
}

export function resolveFieldHelper(
  name: string,
  schema: OpenApiSchema,
  kind: RequestFormFieldKind,
  paramIn?: OpenApiParameter['in'],
  method?: ApiExplorerMethod,
): RequestFormFieldHelper | undefined {
  if (shouldOfferResourceIdPicker(schema, name, paramIn)) {
    const resourceType = resolveResourceIdType(name)
    if (resourceType) {
      return { type: 'resource-id', resourceType }
    }
  }

  if (isPermissionsField(name, kind, schema)) {
    return {
      type: 'permissions',
      ...getPermissionsEditorOptions(method, name),
    }
  }

  if (isQueriesField(name, schema)) {
    return { type: 'queries' }
  }

  return undefined
}

export function attachFieldHelper(
  field: RequestFormField,
  schema: OpenApiSchema,
  paramIn?: OpenApiParameter['in'],
  method?: ApiExplorerMethod,
): RequestFormField {
  const kind =
    isQueriesField(field.name, schema) && field.kind === 'string'
      ? 'array-string'
      : field.kind

  const normalizedField = kind === field.kind ? field : { ...field, kind }
  const helper = resolveFieldHelper(
    normalizedField.name,
    schema,
    normalizedField.kind,
    paramIn,
    method,
  )
  if (!helper) return normalizedField
  return { ...normalizedField, helper }
}

export function getQueryFilterColumnsForMethod(
  method: ApiExplorerMethod | undefined,
): FilterColumn[] {
  if (!method) return genericQueryFilterColumns()

  const path = method.path.toLowerCase()

  if (path.includes('/users')) return usersFilterColumns
  if (path.includes('/teams')) return teamsFilterColumns
  if (path.includes('/storage/buckets') && path.includes('/files')) {
    return filesFilterColumns
  }
  if (path.includes('/storage/buckets')) return bucketsFilterColumns
  if (
    path.includes('/documents') ||
    path.includes('/rows') ||
    path.includes('/collections')
  ) {
    return genericQueryFilterColumns()
  }
  if (path.includes('/databases')) return databasesFilterColumns
  if (path.includes('/functions') && path.includes('/executions')) {
    return executionsFilterColumns
  }
  if (path.includes('/functions') && path.includes('/deployments')) {
    return deploymentsFilterColumns
  }
  if (path.includes('/functions')) return functionsFilterColumns
  if (path.includes('/sites') && path.includes('/deployments')) {
    return deploymentsFilterColumns
  }
  if (path.includes('/sites')) return sitesFilterColumns
  if (path.includes('/activities')) return activitiesFilterColumns

  return genericQueryFilterColumns()
}

function genericQueryFilterColumns(): FilterColumn[] {
  return [
    { id: '$id', title: '$id', type: 'string' },
    { id: '$createdAt', title: '$createdAt', type: 'datetime' },
    { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
  ]
}

export type ResourceIdContext = {
  databaseId?: string
  tableId?: string
  bucketId?: string
}

export function resolveResourceIdContext(
  formValues: Record<string, string | boolean | number | string[] | File | null>,
): ResourceIdContext {
  const read = (...keys: string[]) => {
    for (const key of keys) {
      const raw = formValues[key]
      if (typeof raw === 'string' && raw.trim()) return raw.trim()
    }
    return undefined
  }

  return {
    databaseId: read('databaseId', 'database'),
    tableId: read('tableId', 'collectionId', 'table'),
    bucketId: read('bucketId', 'bucket'),
  }
}
