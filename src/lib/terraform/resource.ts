/**
 * Maps console resources and project SDK calls to the activity log `resource`
 * path (e.g. `function/api`, `database/main/table/posts`) Terraform writes to.
 */

export type TerraformResourceKind =
  | 'function'
  | 'site'
  | 'bucket'
  | 'database'
  | 'table'
  | 'collection'
  | 'topic'
  | 'provider'
  | 'webhook'
  | 'rule'
  | 'key'

/** Activity `resourceType` values for resources the provider manages. */
export const TERRAFORM_RESOURCE_TYPES = [
  'function',
  'site',
  'bucket',
  'database',
  'table',
  'collection',
  'topic',
  'provider',
  'webhook',
  'rule',
  'project.key',
] as const

export function getTerraformResourcePath(
  kind: TerraformResourceKind,
  id: string,
  databaseId?: string | null,
): string | null {
  if (!id) return null
  switch (kind) {
    case 'table':
    case 'collection':
      return databaseId ? `database/${databaseId}/${kind}/${id}` : null
    case 'key':
      return `project.key/${id}`
    default:
      return `${kind}/${id}`
  }
}

export function getTerraformResourceKind(
  path: string,
): TerraformResourceKind | null {
  const segments = path.split('/')
  const type = segments[segments.length - 2]
  if (type === 'project.key') return 'key'
  return (TERRAFORM_RESOURCE_TYPES as readonly string[]).includes(type)
    ? (type as TerraformResourceKind)
    : null
}

export function getTerraformResourceId(path: string): string {
  return path.split('/').pop() ?? path
}

const WRITE_METHOD = /^(create|update|upsert|delete)/

/** Writes to data inside a resource (rows, files, executions) are not configuration changes. */
const DATA_METHOD =
  /Execution|File|Row|Document|Transaction|Operation|Log|Subscriber|Message/

/**
 * Adding a new child does not drift the parent: Terraform only reverts the
 * children in its configuration, so an extra variable is left alone.
 */
const ADDITIVE_METHOD = /^createVariable$/

type SdkCallParams = Record<string, unknown>

function stringParam(params: SdkCallParams, key: string): string | null {
  const value = params[key]
  return typeof value === 'string' && value ? value : null
}

/**
 * Resource path a project SDK write targets, or null when the call does not
 * change the configuration of an existing resource Terraform could manage.
 */
export function getSdkCallResourcePath(
  service: string,
  method: string,
  params: unknown,
): string | null {
  if (
    !WRITE_METHOD.test(method) ||
    DATA_METHOD.test(method) ||
    ADDITIVE_METHOD.test(method)
  ) {
    return null
  }
  if (!params || typeof params !== 'object' || Array.isArray(params)) {
    return null
  }
  const values = params as SdkCallParams
  const functionId = stringParam(values, 'functionId')
  const siteId = stringParam(values, 'siteId')
  const bucketId = stringParam(values, 'bucketId')
  const databaseId = stringParam(values, 'databaseId')
  const tableId = stringParam(values, 'tableId')
  const collectionId = stringParam(values, 'collectionId')
  const topicId = stringParam(values, 'topicId')
  const providerId = stringParam(values, 'providerId')
  const webhookId = stringParam(values, 'webhookId')
  const ruleId = stringParam(values, 'ruleId')
  const keyId = stringParam(values, 'keyId')

  switch (service) {
    case 'functions':
      return functionId && getTerraformResourcePath('function', functionId)
    case 'sites':
      return siteId && getTerraformResourcePath('site', siteId)
    case 'storage':
      return bucketId && getTerraformResourcePath('bucket', bucketId)
    case 'tablesDB':
      if (tableId) return getTerraformResourcePath('table', tableId, databaseId)
      return databaseId && getTerraformResourcePath('database', databaseId)
    case 'documentsDB':
      if (collectionId) {
        return getTerraformResourcePath('collection', collectionId, databaseId)
      }
      return databaseId && getTerraformResourcePath('database', databaseId)
    case 'messaging':
      if (topicId) return getTerraformResourcePath('topic', topicId)
      return providerId && getTerraformResourcePath('provider', providerId)
    case 'webhooks':
      return webhookId && getTerraformResourcePath('webhook', webhookId)
    case 'proxy':
      return ruleId && getTerraformResourcePath('rule', ruleId)
    case 'project':
      return keyId && getTerraformResourcePath('key', keyId)
    default:
      return null
  }
}
