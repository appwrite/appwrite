import { buildSampleValue, isOpenApiPlaceholderExample } from './parse-spec'
import { attachFieldHelper } from './field-helpers'
import type { RequestFormFieldHelper } from './field-helpers'
import type {
  ApiExplorerMethod,
  OpenApiParameter,
  OpenApiSchema,
} from './types'

export type RequestFormFieldKind =
  | 'string'
  | 'password'
  | 'email'
  | 'url'
  | 'phone'
  | 'datetime'
  | 'ip'
  | 'integer'
  | 'number'
  | 'boolean'
  | 'enum'
  | 'array-string'
  | 'array-enum'
  | 'array-number'
  | 'json'
  | 'binary'
  | 'id'

/** OpenAPI string formats mapped to form field kinds (Appwrite specs). */
const OPENAPI_STRING_FORMAT_KIND: Record<string, RequestFormFieldKind> = {
  password: 'password',
  email: 'email',
  url: 'url',
  phone: 'phone',
  datetime: 'datetime',
  binary: 'binary',
  ip: 'ip',
}

const OPENAPI_NUMBER_FORMATS = new Set(['float', 'double'])

/** String formats and other aliases that should display as base OpenAPI primitives. */
const OPENAPI_STRING_FORMATS = new Set([
  'password',
  'email',
  'url',
  'phone',
  'datetime',
  'ip',
  'id',
  'binary',
  'uuid',
])

const OPENAPI_PRIMITIVE_TYPES = new Set([
  'string',
  'integer',
  'number',
  'boolean',
  'array',
  'object',
  'enum',
])

export function normalizeOpenApiPrimitiveType(type: string): string {
  const trimmed = type.trim()
  const normalized = trimmed.toLowerCase()

  // Only map OpenAPI format aliases when the label is already lowercase (e.g. "password").
  // Preserve model names like "File" that would otherwise match "file" in format sets.
  if (trimmed === normalized) {
    if (OPENAPI_STRING_FORMATS.has(normalized)) return 'string'
    if (OPENAPI_NUMBER_FORMATS.has(normalized)) return 'number'
  }

  if (OPENAPI_PRIMITIVE_TYPES.has(normalized)) return normalized

  return trimmed
}

export function getFormFieldTypeLabel(kind: RequestFormFieldKind): string {
  return getFormFieldOpenApiTypeLabel(kind)
}

/** Base OpenAPI primitive for API reference type badges and tables. */
export function getFormFieldOpenApiTypeLabel(kind: RequestFormFieldKind): string {
  switch (kind) {
    case 'boolean':
      return 'boolean'
    case 'integer':
      return 'integer'
    case 'number':
      return 'number'
    case 'enum':
    case 'array-enum':
      return 'enum'
    case 'array-string':
    case 'array-number':
      return 'array'
    case 'json':
      return 'object'
    case 'binary':
      return 'file'
    case 'password':
    case 'email':
    case 'url':
    case 'phone':
    case 'datetime':
    case 'ip':
    case 'id':
    case 'string':
    default:
      return 'string'
  }
}

export function getFormFieldPlaceholder(
  kind: RequestFormFieldKind,
  options?: { required?: boolean },
): string {
  switch (kind) {
    case 'password':
      return '// enter password'
    case 'email':
      return '// user@example.com'
    case 'url':
      return '// https://example.com'
    case 'phone':
      return '// +1234567890'
    case 'datetime':
      return '// select date and time'
    case 'ip':
      return '// 127.0.0.1'
    case 'id':
      return options?.required ? '// required custom ID' : '// optional custom ID'
    case 'json':
      return '// enter JSON object'
    case 'integer':
    case 'number':
    default:
      return '// enter value'
  }
}

/** Normalize API datetime values to ISO for DateTimePicker. */
export function formatDatetimeInputValue(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return ''
  if (/[zZ]|[+-]\d{2}:\d{2}$/.test(trimmed)) return trimmed
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(trimmed)) {
    const date = new Date(trimmed)
    if (!Number.isNaN(date.getTime())) return date.toISOString()
    return trimmed
  }
  const date = new Date(trimmed)
  if (Number.isNaN(date.getTime())) return trimmed
  return date.toISOString()
}

/** Serialize a datetime form value to an ISO 8601 string for the API. */
export function serializeDatetimeApiValue(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return ''
  if (/[zZ]|[+-]\d{2}:\d{2}$/.test(trimmed)) return trimmed
  const date = new Date(trimmed)
  if (Number.isNaN(date.getTime())) return trimmed
  return date.toISOString()
}

function kindFromOpenApiStringFormat(format?: string): RequestFormFieldKind {
  if (!format) return 'string'
  return OPENAPI_STRING_FORMAT_KIND[format] ?? 'string'
}

export type RequestFormField = {
  name: string
  label: string
  description?: string
  required: boolean
  kind: RequestFormFieldKind
  enumValues?: string[]
  nullable?: boolean
  helper?: RequestFormFieldHelper
}

export type FormValue = string | boolean | number | string[] | File | null

/** Appwrite creatable resource IDs mention ID.unique() or "choose a custom … id" in descriptions. */
const CREATABLE_ID_DESCRIPTION =
  /(?:choose a custom .{0,40}? id|generate a random id|id\.unique\(\))/i

/**
 * True when the OpenAPI field describes a new resource ID (optional custom or auto-generated),
 * not a reference to an existing resource.
 */
export function isCreatableIdSchema(schema: OpenApiSchema): boolean {
  const description = schema.description?.trim() ?? ''
  if (!description) return false
  return CREATABLE_ID_DESCRIPTION.test(description)
}

/**
 * Path and query parameters are always existing resource references.
 * Request body fields use {@link isCreatableIdSchema} on their description.
 */
export function isCreatableIdField(
  schema: OpenApiSchema,
  paramIn?: OpenApiParameter['in'],
): boolean {
  if (paramIn === 'path' || paramIn === 'query') return false
  if (schema.type && schema.type !== 'string') return false
  return isCreatableIdSchema(schema)
}

export function isWideFormField(field: RequestFormField): boolean {
  return (
    field.kind === 'json' ||
    field.kind === 'binary' ||
    field.kind === 'array-string' ||
    field.kind === 'array-number' ||
    field.kind === 'array-enum'
  )
}

export function getRequestBodyJsonSchema(
  method: ApiExplorerMethod,
): OpenApiSchema | undefined {
  return method.requestBody?.content?.['application/json']?.schema
}

export function getRequestBodyContentSchema(
  method: ApiExplorerMethod,
): OpenApiSchema | undefined {
  const content = method.requestBody?.content
  if (!content) return undefined
  return (
    content['application/json']?.schema ??
    content['multipart/form-data']?.schema
  )
}

export function hasRequestBodyForMethod(method: ApiExplorerMethod): boolean {
  return getRequestBodyFormFields(method).length > 0
}

export function getRequestBodyFormFields(
  method: ApiExplorerMethod,
): RequestFormField[] {
  const schema = getRequestBodyContentSchema(method)
  if (!schema?.properties) return []

  const requiredSet = new Set(schema.required ?? [])
  return Object.entries(schema.properties).map(([name, propertySchema]) =>
    attachFieldHelper(
      schemaToFormField(name, propertySchema, requiredSet.has(name)),
      propertySchema,
      undefined,
      method,
    ),
  )
}

export function parameterToFormField(
  param: OpenApiParameter,
  method?: ApiExplorerMethod,
): RequestFormField {
  const schema = param.schema ?? { type: 'string' }
  return attachFieldHelper(
    schemaToFormField(
      param.name,
      schema,
      Boolean(param.required),
      param.in,
      param.description,
    ),
    schema,
    param.in,
    method,
  )
}

function schemaToFormField(
  name: string,
  schema: OpenApiSchema,
  required: boolean,
  paramIn?: OpenApiParameter['in'],
  paramDescription?: string,
): RequestFormField {
  const base = {
    name,
    label: name,
    description: paramDescription?.trim() || schema.description,
    required,
    nullable: schema['x-nullable'] === true,
  }

  if (isCreatableIdField(schema, paramIn)) {
    return { ...base, kind: 'id' }
  }

  if (schema.type === 'boolean') {
    return { ...base, kind: 'boolean' }
  }

  if (
    schema.type === 'integer' ||
    schema.format === 'int32' ||
    schema.format === 'int64'
  ) {
    return { ...base, kind: 'integer' }
  }

  if (
    schema.type === 'number' ||
    (schema.format && OPENAPI_NUMBER_FORMATS.has(schema.format))
  ) {
    return { ...base, kind: 'number' }
  }

  if (schema.type === 'array') {
    const items = schema.items ?? {}
    if (items.enum?.length) {
      return {
        ...base,
        kind: 'array-enum',
        enumValues: items.enum.map(String),
      }
    }
    if (items.type === 'integer' || items.type === 'number') {
      return { ...base, kind: 'array-number' }
    }
    if (items.type === 'string') {
      return { ...base, kind: 'array-string' }
    }
    return { ...base, kind: 'json' }
  }

  if (schema.type === 'object' || (!schema.type && !schema.enum?.length)) {
    return { ...base, kind: 'json' }
  }

  if (schema.enum?.length) {
    return {
      ...base,
      kind: 'enum',
      enumValues: schema.enum.map(String),
    }
  }

  if (schema.type === 'string' || (!schema.type && schema.format)) {
    return { ...base, kind: kindFromOpenApiStringFormat(schema.format) }
  }

  return { ...base, kind: 'string' }
}

function defaultValueForField(field: RequestFormField, schema?: OpenApiSchema): FormValue {
  if (schema) {
    const sample = buildSampleValue(schema)
    return valueFromParsed(field, sample)
  }

  switch (field.kind) {
    case 'boolean':
      return false
    case 'integer':
    case 'number':
      return 0
    case 'array-string':
    case 'array-enum':
    case 'array-number':
      return []
    case 'json':
      return '{}'
    case 'binary':
      return null
    default:
      return ''
  }
}

function valueFromParsed(field: RequestFormField, value: unknown): FormValue {
  if (value === null || value === undefined) {
    return field.nullable ? null : defaultValueForField(field)
  }

  switch (field.kind) {
    case 'boolean':
      return Boolean(value)
    case 'integer':
      return typeof value === 'number' ? Math.trunc(value) : Number(value) || 0
    case 'number':
      return typeof value === 'number' ? value : Number(value) || 0
    case 'enum':
    case 'string':
    case 'id':
    case 'password':
    case 'email':
    case 'url':
    case 'phone':
    case 'ip':
    case 'datetime':
    case 'binary':
      return null
    case 'array-string':
    case 'array-enum':
    case 'array-number':
      return Array.isArray(value) ? value.map(String) : []
    case 'json':
      return typeof value === 'string'
        ? value
        : JSON.stringify(value, null, 2)
    default:
      return String(value)
  }
}

export function buildDefaultFormValues(
  fields: RequestFormField[],
  schemaProperties?: Record<string, OpenApiSchema>,
): Record<string, FormValue> {
  const values: Record<string, FormValue> = {}
  for (const field of fields) {
    values[field.name] = defaultValueForField(field, schemaProperties?.[field.name])
  }
  return values
}

export function buildDefaultBodyFormValues(
  method: ApiExplorerMethod,
): Record<string, FormValue> {
  const schema = getRequestBodyContentSchema(method)
  const fields = getRequestBodyFormFields(method)
  return buildDefaultFormValues(fields, schema?.properties)
}

function isEmptyFormValue(value: FormValue | undefined): boolean {
  if (value instanceof File) return false
  if (value === null || value === undefined) return true
  if (typeof value === 'string') return value.trim() === ''
  if (Array.isArray(value)) return value.length === 0
  return false
}

export function getMissingRequiredFormField(
  fields: RequestFormField[],
  values: Record<string, FormValue>,
): RequestFormField | undefined {
  return fields.find(
    (field) => field.required && isEmptyFormValue(values[field.name]),
  )
}

function isEmptyCreatableIdValue(value: unknown): boolean {
  return (
    value === undefined ||
    value === null ||
    (typeof value === 'string' && value.trim() === '')
  )
}

export function getMissingRequiredFieldInJsonBody(
  fields: RequestFormField[],
  json: string,
): RequestFormField | undefined {
  const trimmed = json.trim()
  if (!trimmed) {
    return fields.find((field) => field.required)
  }

  let parsed: Record<string, unknown>
  try {
    parsed = JSON.parse(trimmed) as Record<string, unknown>
  } catch {
    return undefined
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return fields.find((field) => field.required)
  }

  return fields.find((field) => {
    if (!field.required) return false
    if (!(field.name in parsed)) return true
    return isEmptyFormValue(valueFromParsed(field, parsed[field.name]))
  })
}

/** Remove empty creatable ID values so the API is not sent invalid empty strings. */
export function stripEmptyCreatableIdFieldsFromJson(
  fields: RequestFormField[],
  json: string,
): string {
  const trimmed = json.trim()
  if (!trimmed) return trimmed

  const parsed = JSON.parse(trimmed) as Record<string, unknown>
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return trimmed
  }

  const next = { ...parsed }
  for (const field of fields) {
    if (field.kind !== 'id') continue
    if (isEmptyCreatableIdValue(next[field.name])) {
      delete next[field.name]
    }
  }

  return JSON.stringify(next, null, 2)
}

export function buildMultipartFormData(
  fields: RequestFormField[],
  values: Record<string, FormValue>,
): FormData {
  const formData = new FormData()

  for (const field of fields) {
    const value = values[field.name]
    if (isEmptyFormValue(value)) continue

    switch (field.kind) {
      case 'binary':
        if (value instanceof File) {
          formData.append(field.name, value, value.name)
        }
        break
      case 'array-string':
      case 'array-enum':
      case 'array-number': {
        const items = Array.isArray(value) ? value : []
        for (const item of items) {
          const itemValue = String(item).trim()
          if (itemValue) {
            formData.append(`${field.name}[]`, itemValue)
          }
        }
        break
      }
      case 'boolean':
        formData.append(field.name, value ? 'true' : 'false')
        break
      case 'integer':
      case 'number':
        formData.append(field.name, String(value))
        break
      case 'json':
        formData.append(field.name, String(value))
        break
      case 'datetime':
        formData.append(
          field.name,
          serializeDatetimeApiValue(String(value)),
        )
        break
      default:
        formData.append(field.name, String(value))
    }
  }

  return formData
}

function parseJsonFieldValue(raw: string): unknown {
  const trimmed = raw.trim()
  if (!trimmed) return undefined
  return JSON.parse(trimmed)
}

function serializeFieldValue(field: RequestFormField, value: FormValue): unknown {
  if (value === null) return null

  switch (field.kind) {
    case 'boolean':
      return Boolean(value)
    case 'integer':
      return typeof value === 'number' ? Math.trunc(value) : parseInt(String(value), 10) || 0
    case 'number':
      return typeof value === 'number' ? value : Number(value) || 0
    case 'array-string':
    case 'array-enum':
    case 'array-number':
      return Array.isArray(value) ? value : []
    case 'json':
      return parseJsonFieldValue(String(value))
    case 'datetime':
      return serializeDatetimeApiValue(String(value))
    default:
      return String(value)
  }
}

export function serializeBodyFromForm(
  fields: RequestFormField[],
  values: Record<string, FormValue>,
): string {
  const payload: Record<string, unknown> = {}

  for (const field of fields) {
    if (field.kind === 'binary') continue

    const value = values[field.name]
    if (isEmptyFormValue(value)) {
      if (field.kind === 'id') {
        continue
      }
      if (field.required) {
        payload[field.name] = serializeFieldValue(field, value ?? defaultValueForField(field))
      }
      continue
    }
    payload[field.name] = serializeFieldValue(field, value!)
  }

  return JSON.stringify(payload, null, 2)
}

export function parseBodyToFormValues(
  fields: RequestFormField[],
  json: string,
): Record<string, FormValue> {
  const trimmed = json.trim()
  if (!trimmed) return buildDefaultFormValues(fields)

  const parsed = JSON.parse(trimmed) as Record<string, unknown>
  const values: Record<string, FormValue> = {}

  for (const field of fields) {
    if (!(field.name in parsed)) {
      values[field.name] = defaultValueForField(field)
      continue
    }
    values[field.name] = valueFromParsed(field, parsed[field.name])
  }

  return values
}

export function serializeParamFormValue(
  field: RequestFormField,
  value: FormValue,
): string {
  if (isEmptyFormValue(value)) return ''

  switch (field.kind) {
    case 'boolean':
      return value ? 'true' : 'false'
    case 'integer':
    case 'number':
      return String(value)
    case 'array-string':
    case 'array-enum':
    case 'array-number':
      return JSON.stringify(Array.isArray(value) ? value : [])
    case 'json':
      return String(value)
    default: {
      const stringValue = String(value)
      if (isOpenApiPlaceholderExample(stringValue)) return ''
      return stringValue
    }
  }
}

export function parseParamFormValue(
  field: RequestFormField,
  raw: string,
): FormValue {
  if (!raw.trim()) return defaultValueForField(field)

  switch (field.kind) {
    case 'boolean':
      return raw === 'true'
    case 'integer':
      return parseInt(raw, 10) || 0
    case 'number':
      return Number(raw) || 0
    case 'array-string':
    case 'array-enum':
    case 'array-number':
      try {
        const parsed = JSON.parse(raw)
        return Array.isArray(parsed) ? parsed.map(String) : []
      } catch {
        return raw
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean)
      }
    case 'json':
      return raw
    case 'datetime':
      return serializeDatetimeApiValue(raw)
    default:
      return raw
  }
}

export function buildInitialParamFormValues(
  parameters: OpenApiParameter[],
  method?: ApiExplorerMethod,
): Record<string, FormValue> {
  const values: Record<string, FormValue> = {}
  for (const param of parameters) {
    const field = parameterToFormField(param, method)
    const raw = param.schema
      ? getSchemaDefaultString(param.schema)
      : ''
    values[param.name] = raw
      ? parseParamFormValue(field, raw)
      : defaultValueForField(field, param.schema)
  }
  return values
}

function getSchemaDefaultString(schema: OpenApiSchema): string {
  if (schema.default !== undefined && schema.default !== null) {
    if (Array.isArray(schema.default) || typeof schema.default === 'object') {
      return JSON.stringify(schema.default)
    }
    return String(schema.default)
  }
  const example = schema.example ?? schema['x-example']
  if (example !== undefined && example !== null) {
    if (typeof example === 'string' && isOpenApiPlaceholderExample(example)) {
      return ''
    }
    if (typeof example === 'object') return JSON.stringify(example)
    return String(example)
  }
  if (schema.enum?.length) return String(schema.enum[0])
  return ''
}

export function paramFormValuesToStrings(
  parameters: OpenApiParameter[],
  values: Record<string, FormValue>,
): Record<string, string> {
  const result: Record<string, string> = {}
  for (const param of parameters) {
    const field = parameterToFormField(param)
    result[param.name] = serializeParamFormValue(field, values[param.name])
  }
  return result
}
