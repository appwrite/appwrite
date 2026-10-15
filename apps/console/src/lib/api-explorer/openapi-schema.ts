import type { OpenApiSchema } from './types'

export type OpenApiEnumMember = {
  /** SDK-facing name (branch title / x-enum-keys). */
  key: string
  /** Serialized API value. */
  value: string
}

export type OpenApiEnumInfo = {
  /** Schema title or legacy x-enum-name. */
  name?: string
  members: OpenApiEnumMember[]
  values: string[]
  /** True when unknown strings are allowed (anyOf + unrestricted string branch). */
  open: boolean
}

function singletonEnumValue(schema: OpenApiSchema): string | undefined {
  if (schema.enum?.length === 1) return String(schema.enum[0])
  if (schema.const !== undefined && schema.const !== null) {
    return String(schema.const)
  }
  return undefined
}

function isModelRefBranch(schema: OpenApiSchema): boolean {
  return Boolean(schema.$ref)
}

function isSingletonStringEnumBranch(schema: OpenApiSchema): boolean {
  if (isModelRefBranch(schema)) return false
  if (schema.oneOf?.length || schema.anyOf?.length || schema.allOf?.length) {
    return false
  }
  if (schema.type && schema.type !== 'string') return false
  if (schema.format === 'binary') return false
  return singletonEnumValue(schema) !== undefined
}

function isUnrestrictedStringBranch(schema: OpenApiSchema): boolean {
  if (isModelRefBranch(schema)) return false
  if (schema.oneOf?.length || schema.anyOf?.length || schema.allOf?.length) {
    return false
  }
  if (schema.enum?.length || schema.const !== undefined) return false
  if (schema.format === 'binary') return false
  if (schema.properties || schema.items) return false
  return !schema.type || schema.type === 'string'
}

function memberFromBranch(
  schema: OpenApiSchema,
): OpenApiEnumMember | undefined {
  const value = singletonEnumValue(schema)
  if (value === undefined) return undefined
  const key = schema.title?.trim() || value
  return { key, value }
}

function parseAnnotatedEnum(schema: OpenApiSchema): OpenApiEnumInfo | null {
  const closedBranches = schema.oneOf
  const openBranches = schema.anyOf
  const branches = closedBranches?.length
    ? closedBranches
    : openBranches?.length
      ? openBranches
      : undefined
  if (!branches?.length) return null

  const members: OpenApiEnumMember[] = []
  let unrestrictedCount = 0

  for (const branch of branches) {
    if (isSingletonStringEnumBranch(branch)) {
      const member = memberFromBranch(branch)
      if (!member) return null
      members.push(member)
      continue
    }
    if (isUnrestrictedStringBranch(branch)) {
      unrestrictedCount += 1
      continue
    }
    return null
  }

  if (members.length === 0) return null
  if (closedBranches?.length && unrestrictedCount > 0) return null

  const values = members.map((member) => member.value)
  const name =
    schema.title?.trim() || schema['x-enum-name']?.trim() || undefined

  return {
    name,
    members,
    values,
    open: unrestrictedCount > 0,
  }
}

function parseClassicEnum(schema: OpenApiSchema): OpenApiEnumInfo | null {
  if (!schema.enum?.length) return null

  const values = schema.enum.map(String)
  const keys = schema['x-enum-keys']
  const members = values.map((value, index) => ({
    key: keys?.[index]?.trim() || value,
    value,
  }))

  return {
    name: schema.title?.trim() || schema['x-enum-name']?.trim() || undefined,
    members,
    values,
    open: false,
  }
}

/** Closed oneOf / open anyOf annotated enums, with classic enum + x-enum-* fallback. */
export function getOpenApiEnumInfo(
  schema: OpenApiSchema | undefined,
): OpenApiEnumInfo | null {
  if (!schema) return null
  return parseAnnotatedEnum(schema) ?? parseClassicEnum(schema)
}

export function getOpenApiEnumValues(
  schema: OpenApiSchema | undefined,
): string[] {
  return getOpenApiEnumInfo(schema)?.values ?? []
}

function isBinaryProperty(schema: OpenApiSchema): boolean {
  return schema.format === 'binary' || schema.type === 'file'
}

function isStringLikeProperty(schema: OpenApiSchema): boolean {
  return !schema.type || schema.type === 'string'
}

/**
 * Resumable upload IDs are inferred from multipart shape:
 * one binary property `file` plus `fileId` → `fileId`.
 * `code` with no `codeId` yields nothing. Multiple binaries are ambiguous.
 * Legacy `x-upload-id` is still honored when present.
 */
export function inferResumableUploadIdPropertyName(
  schema: OpenApiSchema | undefined,
): string | undefined {
  const properties = schema?.properties
  if (!properties) return undefined

  for (const [name, property] of Object.entries(properties)) {
    if (property['x-upload-id']) return name
  }

  const binaryNames = Object.entries(properties)
    .filter(([, property]) => isBinaryProperty(property))
    .map(([name]) => name)

  if (binaryNames.length !== 1) return undefined

  const binaryName = binaryNames[0]!
  const idName = `${binaryName}Id`
  const idProperty = properties[idName]
  if (!idProperty || isBinaryProperty(idProperty)) return undefined
  if (!isStringLikeProperty(idProperty)) return undefined
  return idName
}

function uniqueStrings(values: string[]): string[] {
  return [...new Set(values.filter(Boolean))]
}

function schemaIdFromRef(ref: string): string {
  return ref.replace('#/components/schemas/', '')
}

type ConditionValue = string | number | boolean

/** Only interpret reference + required literal constraints, not arbitrary allOf models. */
function conditionalReferences(
  schema: OpenApiSchema | undefined,
): Map<string, Map<string, ConditionValue>> {
  const cases = new Map<string, Map<string, ConditionValue>>()
  if (
    !schema ||
    schema.nullable ||
    schema.not ||
    schema.enum?.length ||
    schema.const !== undefined
  )
    return cases
  const branches = schema.oneOf ?? schema.anyOf ?? []
  for (const branch of branches) {
    let ref: string | undefined
    const conditions = new Map<string, ConditionValue>()
    const pending = [branch]
    while (pending.length) {
      const member = pending.pop()!
      if (member.$ref) {
        if (ref) return new Map()
        ref = member.$ref
        continue
      }
      if (
        member.nullable ||
        member.not ||
        member.enum?.length ||
        member.const !== undefined
      )
        return new Map()
      if (member.oneOf || member.anyOf) return new Map()
      if (member.allOf) {
        if (!member.allOf.length) return new Map()
        pending.push(...member.allOf.slice().reverse())
        continue
      }
      if (
        (member.type && member.type !== 'object') ||
        !member.properties ||
        !Object.keys(member.properties).length ||
        member.additionalProperties !== undefined ||
        member.minProperties !== undefined ||
        member.maxProperties !== undefined ||
        member.required?.some(
          (name) => !Object.hasOwn(member.properties!, name),
        )
      )
        return new Map()
      for (const [name, property] of Object.entries(member.properties)) {
        const values =
          property.enum ??
          (property.const !== undefined ? [property.const] : [])
        const value = values[0]
        if (
          !member.required?.includes(name) ||
          property.nullable ||
          property.$ref ||
          property.not ||
          property.oneOf ||
          property.anyOf ||
          property.allOf ||
          property.properties ||
          property.items ||
          (property.type &&
            !['string', 'integer', 'number', 'boolean'].includes(
              property.type,
            )) ||
          values.length !== 1 ||
          (typeof value !== 'string' &&
            typeof value !== 'number' &&
            typeof value !== 'boolean')
        )
          return new Map()
        if (conditions.has(name) && conditions.get(name) !== value)
          return new Map()
        conditions.set(name, value)
      }
    }
    if (!ref || !conditions.size || cases.has(ref)) return new Map()
    cases.set(ref, conditions)
  }
  return cases
}

/** Standard required conditions first, with legacy discriminator fallbacks. */
export function getDiscriminatorPropertyNames(
  schema: OpenApiSchema | undefined,
): string[] {
  const cases = conditionalReferences(schema)
  if (cases.size) {
    return uniqueStrings(
      [...cases.values()].flatMap((conditions) => [...conditions.keys()]),
    )
  }

  const discriminator = schema?.discriminator
  if (!discriminator) return []

  const legacy = discriminator['x-propertyNames']
  if (legacy?.length) return uniqueStrings(legacy)

  const names = new Set<string>()
  if (discriminator.propertyName) names.add(discriminator.propertyName)
  for (const condition of Object.values(discriminator['x-mapping'] ?? {})) {
    for (const key of Object.keys(condition)) names.add(key)
  }
  return [...names]
}

/** Model identities from constrained union branches, direct refs, or legacy mappings. */
export function getPolymorphicModelRefs(
  schema: OpenApiSchema | undefined,
): string[] {
  if (!schema) return []

  const cases = conditionalReferences(schema)
  if (cases.size) return uniqueStrings([...cases.keys()].map(schemaIdFromRef))

  const refs: string[] = []
  for (const branch of [...(schema.oneOf ?? []), ...(schema.anyOf ?? [])]) {
    if (branch.$ref) refs.push(branch.$ref)
  }

  const discriminator = schema.discriminator
  if (discriminator?.mapping) {
    refs.push(...Object.values(discriminator.mapping))
  }
  if (discriminator?.['x-mapping']) {
    refs.push(...Object.keys(discriminator['x-mapping']))
  }

  return uniqueStrings(refs.map(schemaIdFromRef))
}
