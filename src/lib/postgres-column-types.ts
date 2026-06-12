import type { PostgresTableColumnRow } from '@/lib/postgres-sql'

export type PostgresColumnTypeGroup =
  | 'Text'
  | 'Integer'
  | 'Decimal'
  | 'Boolean'
  | 'Date & time'
  | 'Structured'
  | 'Network'

export type PostgresColumnTypeId =
  | 'text'
  | 'varchar'
  | 'char'
  | 'smallint'
  | 'integer'
  | 'bigint'
  | 'smallserial'
  | 'serial'
  | 'bigserial'
  | 'real'
  | 'double precision'
  | 'numeric'
  | 'boolean'
  | 'date'
  | 'time'
  | 'time with time zone'
  | 'timestamp'
  | 'timestamp with time zone'
  | 'interval'
  | 'uuid'
  | 'json'
  | 'jsonb'
  | 'bytea'
  | 'bit'
  | 'bit varying'
  | 'inet'
  | 'cidr'
  | 'macaddr'

export type PostgresColumnTypePropertyKey =
  | 'length'
  | 'numericPrecision'
  | 'numericScale'
  | 'datetimePrecision'

export type PostgresColumnTypePropertyUnit =
  | 'characters'
  | 'bits'
  | 'digits'
  | 'decimal places'

export type PostgresColumnTypeProperty = {
  key: PostgresColumnTypePropertyKey
  label: string
  hint?: string
  unit?: PostgresColumnTypePropertyUnit
  min: number
  max: number
  defaultValue?: number
  optional?: boolean
  /** Shown when optional and value is empty (e.g. "unlimited"). */
  optionalEmptyLabel?: string
}

export type PostgresColumnTypeDefinition = {
  id: PostgresColumnTypeId
  label: string
  group: PostgresColumnTypeGroup
  properties: PostgresColumnTypeProperty[]
  createOnly?: boolean
  /** Included in type search matching (e.g. SQL aliases). */
  searchTerms?: string[]
}

export type PostgresColumnTypeState = {
  typeId: PostgresColumnTypeId
  length?: number
  numericPrecision?: number
  numericScale?: number
  datetimePrecision?: number
}

const LENGTH_PROPERTY = (
  defaults: Omit<PostgresColumnTypeProperty, 'key' | 'label'> & {
    label?: string
  },
): PostgresColumnTypeProperty => ({
  key: 'length',
  label: defaults.label ?? 'Length',
  hint: defaults.hint,
  unit: defaults.unit,
  min: defaults.min,
  max: defaults.max,
  defaultValue: defaults.defaultValue,
  optional: defaults.optional,
  optionalEmptyLabel: defaults.optionalEmptyLabel,
})

const NUMERIC_PRECISION_PROPERTY = (
  defaultValue = 10,
): PostgresColumnTypeProperty => ({
  key: 'numericPrecision',
  label: 'Precision',
  hint: 'Total number of digits.',
  unit: 'digits',
  min: 1,
  max: 1000,
  defaultValue,
})

const NUMERIC_SCALE_PROPERTY = (defaultValue = 0): PostgresColumnTypeProperty => ({
  key: 'numericScale',
  label: 'Scale',
  hint: 'Digits after the decimal point.',
  unit: 'digits',
  min: 0,
  max: 1000,
  defaultValue,
})

const DATETIME_PRECISION_PROPERTY: PostgresColumnTypeProperty = {
  key: 'datetimePrecision',
  label: 'Fractional seconds',
  hint: 'Number of digits after the decimal point in seconds.',
  unit: 'decimal places',
  min: 0,
  max: 6,
  optional: true,
  optionalEmptyLabel: 'default (6)',
}

export const POSTGRES_COLUMN_TYPE_DEFINITIONS: PostgresColumnTypeDefinition[] =
  [
    {
      id: 'text',
      label: 'Text',
      group: 'Text',
      properties: [],
      searchTerms: ['text', 'string'],
    },
    {
      id: 'varchar',
      label: 'Varchar',
      group: 'Text',
      properties: [
        LENGTH_PROPERTY({
          hint: 'Maximum number of characters stored in this column.',
          unit: 'characters',
          min: 1,
          max: 10_485_760,
          defaultValue: 255,
        }),
      ],
      searchTerms: ['character varying', 'varchar'],
    },
    {
      id: 'char',
      label: 'Char',
      group: 'Text',
      properties: [
        LENGTH_PROPERTY({
          hint: 'Fixed number of characters. Values are padded or truncated to this length.',
          unit: 'characters',
          min: 1,
          max: 10_485_760,
          defaultValue: 1,
        }),
      ],
      searchTerms: ['character', 'bpchar', 'char'],
    },
    {
      id: 'smallint',
      label: 'Smallint',
      group: 'Integer',
      properties: [],
      searchTerms: ['int2', 'smallint'],
    },
    {
      id: 'integer',
      label: 'Integer',
      group: 'Integer',
      properties: [],
      searchTerms: ['int4', 'integer', 'int'],
    },
    {
      id: 'bigint',
      label: 'Bigint',
      group: 'Integer',
      properties: [],
      searchTerms: ['int8', 'bigint'],
    },
    {
      id: 'smallserial',
      label: 'Smallserial',
      group: 'Integer',
      properties: [],
      createOnly: true,
      searchTerms: ['smallserial', 'serial2'],
    },
    {
      id: 'serial',
      label: 'Serial',
      group: 'Integer',
      properties: [],
      createOnly: true,
      searchTerms: ['serial', 'serial4'],
    },
    {
      id: 'bigserial',
      label: 'Bigserial',
      group: 'Integer',
      properties: [],
      createOnly: true,
      searchTerms: ['bigserial', 'serial8'],
    },
    {
      id: 'real',
      label: 'Real',
      group: 'Decimal',
      properties: [],
      searchTerms: ['float4', 'real'],
    },
    {
      id: 'double precision',
      label: 'Double precision',
      group: 'Decimal',
      properties: [],
      searchTerms: ['float8', 'double precision', 'float'],
    },
    {
      id: 'numeric',
      label: 'Numeric',
      group: 'Decimal',
      properties: [NUMERIC_PRECISION_PROPERTY(), NUMERIC_SCALE_PROPERTY()],
      searchTerms: ['numeric', 'decimal'],
    },
    {
      id: 'boolean',
      label: 'Boolean',
      group: 'Boolean',
      properties: [],
      searchTerms: ['bool', 'boolean'],
    },
    { id: 'date', label: 'Date', group: 'Date & time', properties: [] },
    {
      id: 'time',
      label: 'Time',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['time without time zone'],
    },
    {
      id: 'time with time zone',
      label: 'Time with time zone',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['timetz', 'time with time zone'],
    },
    {
      id: 'timestamp',
      label: 'Timestamp',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['timestamp without time zone'],
    },
    {
      id: 'timestamp with time zone',
      label: 'Timestamp with time zone',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['timestamptz', 'timestamp with time zone'],
    },
    { id: 'interval', label: 'Interval', group: 'Date & time', properties: [] },
    {
      id: 'uuid',
      label: 'UUID',
      group: 'Structured',
      properties: [],
      searchTerms: ['uuid'],
    },
    { id: 'json', label: 'JSON', group: 'Structured', properties: [] },
    { id: 'jsonb', label: 'JSONB', group: 'Structured', properties: [] },
    { id: 'bytea', label: 'Bytea', group: 'Structured', properties: [] },
    {
      id: 'bit',
      label: 'Bit',
      group: 'Structured',
      properties: [
        LENGTH_PROPERTY({
          label: 'Length (bits)',
          hint: 'Number of bits stored in this column.',
          unit: 'bits',
          min: 1,
          max: 83_886_080,
          defaultValue: 1,
          optional: true,
          optionalEmptyLabel: '1 bit',
        }),
      ],
      searchTerms: ['bit'],
    },
    {
      id: 'bit varying',
      label: 'Bit varying',
      group: 'Structured',
      properties: [
        LENGTH_PROPERTY({
          label: 'Max length (bits)',
          hint: 'Maximum number of bits stored in this column.',
          unit: 'bits',
          min: 1,
          max: 83_886_080,
          optional: true,
          optionalEmptyLabel: 'unlimited',
        }),
      ],
      searchTerms: ['varbit', 'bit varying'],
    },
    { id: 'inet', label: 'Inet', group: 'Network', properties: [] },
    { id: 'cidr', label: 'CIDR', group: 'Network', properties: [] },
    { id: 'macaddr', label: 'MAC address', group: 'Network', properties: [] },
  ]

const POSTGRES_COLUMN_TYPE_BY_ID = new Map(
  POSTGRES_COLUMN_TYPE_DEFINITIONS.map((definition) => [
    definition.id,
    definition,
  ]),
)

export const POSTGRES_COLUMN_TYPE_GROUPS: PostgresColumnTypeGroup[] = [
  'Text',
  'Integer',
  'Decimal',
  'Boolean',
  'Date & time',
  'Structured',
  'Network',
]

const DATETIME_TYPE_IDS = new Set<PostgresColumnTypeId>([
  'time',
  'time with time zone',
  'timestamp',
  'timestamp with time zone',
])

function parseOptionalInt(
  value: number | string | null | undefined,
): number | undefined {
  if (value == null || value === '') return undefined
  const parsed =
    typeof value === 'number' ? value : Number.parseInt(String(value), 10)
  return Number.isFinite(parsed) ? parsed : undefined
}

export function getPostgresColumnTypeDefinition(
  typeId: PostgresColumnTypeId,
): PostgresColumnTypeDefinition {
  return POSTGRES_COLUMN_TYPE_BY_ID.get(typeId)!
}

export function getPostgresColumnTypePropertyValue(
  state: PostgresColumnTypeState,
  key: PostgresColumnTypePropertyKey,
): number | undefined {
  return state[key]
}

function formatLimitNumber(value: number): string {
  return value.toLocaleString()
}

export function formatPostgresColumnTypePropertyLimits(
  property: PostgresColumnTypeProperty,
): string {
  const min = formatLimitNumber(property.min)
  const max = formatLimitNumber(property.max)
  const unit = property.unit ? ` ${property.unit}` : ''

  if (property.min === property.max) {
    return `${min}${unit}`
  }

  return `${min} to ${max}${unit}`
}

export function getPostgresColumnTypePropertyPlaceholder(
  property: PostgresColumnTypeProperty,
): string | undefined {
  if (!property.optional) {
    return property.defaultValue != null
      ? String(property.defaultValue)
      : undefined
  }

  return property.optionalEmptyLabel ?? 'Default'
}

export function getPostgresColumnTypePropertyRangeError(
  property: PostgresColumnTypeProperty,
  value: number | undefined,
): string | null {
  if (value == null) {
    return property.optional ? null : `${property.label} is required.`
  }

  if (!Number.isInteger(value)) {
    return `${property.label} must be a whole number.`
  }

  if (value < property.min || value > property.max) {
    return `${property.label} must be between ${formatLimitNumber(property.min)} and ${formatLimitNumber(property.max)}${property.unit ? ` ${property.unit}` : ''}.`
  }

  return null
}

export function createDefaultPostgresColumnTypeState(
  typeId: PostgresColumnTypeId = 'text',
): PostgresColumnTypeState {
  const definition = getPostgresColumnTypeDefinition(typeId)
  const state: PostgresColumnTypeState = { typeId }

  for (const property of definition.properties) {
    if (property.defaultValue != null) {
      state[property.key] = property.defaultValue
    }
  }

  return state
}

function appendPrecisionSuffix(
  baseType: string,
  datetimePrecision?: number,
): string {
  if (datetimePrecision == null) return baseType
  return `${baseType}(${datetimePrecision})`
}

export function buildPostgresColumnTypeSql(state: PostgresColumnTypeState): string {
  switch (state.typeId) {
    case 'varchar':
      return `varchar(${state.length ?? 255})`
    case 'char':
      return `char(${state.length ?? 1})`
    case 'numeric': {
      const precision = state.numericPrecision
      const scale = state.numericScale
      if (precision != null && scale != null) {
        return `numeric(${precision},${scale})`
      }
      if (precision != null) {
        return `numeric(${precision})`
      }
      return 'numeric'
    }
    case 'time':
      return appendPrecisionSuffix('time', state.datetimePrecision)
    case 'time with time zone':
      return appendPrecisionSuffix('time with time zone', state.datetimePrecision)
    case 'timestamp':
      return appendPrecisionSuffix('timestamp', state.datetimePrecision)
    case 'timestamp with time zone':
      return appendPrecisionSuffix(
        'timestamp with time zone',
        state.datetimePrecision,
      )
    case 'bit':
      return state.length != null ? `bit(${state.length})` : 'bit'
    case 'bit varying':
      return state.length != null ? `bit varying(${state.length})` : 'bit varying'
    default:
      return state.typeId
  }
}

export function formatPostgresColumnTypeLabel(
  state: PostgresColumnTypeState,
): string {
  const definition = getPostgresColumnTypeDefinition(state.typeId)
  const sql = buildPostgresColumnTypeSql(state)
  if (sql === state.typeId) return definition.label

  const paramsMatch = sql.match(/\((.+)\)$/)
  if (paramsMatch) {
    return `${definition.label} (${paramsMatch[1]})`
  }

  return definition.label
}

function normalizeDataTypeLabel(value: string): string {
  return value.trim().toLowerCase().replace(/\s+/g, ' ')
}

function parsePrecisionFromDataType(dataType: string): number | undefined {
  const match = dataType.match(/\((\d+)\)$/)
  if (!match) return undefined
  return parseOptionalInt(match[1])
}

export function parsePostgresColumnTypeFromRow(
  row: Pick<
    PostgresTableColumnRow,
    | 'data_type'
    | 'udt_name'
    | 'character_maximum_length'
    | 'numeric_precision'
    | 'numeric_scale'
    | 'datetime_precision'
  >,
): PostgresColumnTypeState {
  const udt = row.udt_name.toLowerCase()
  const dataType = normalizeDataTypeLabel(row.data_type)
  const length = parseOptionalInt(row.character_maximum_length)
  const numericPrecision = parseOptionalInt(row.numeric_precision)
  const numericScale = parseOptionalInt(row.numeric_scale)
  const datetimePrecision =
    parseOptionalInt(row.datetime_precision) ??
    parsePrecisionFromDataType(dataType)

  if (udt === 'varchar' || dataType.startsWith('character varying')) {
    return {
      typeId: 'varchar',
      length: length ?? parsePrecisionFromDataType(dataType) ?? 255,
    }
  }
  if (udt === 'bpchar' || dataType === 'character') {
    return {
      typeId: 'char',
      length: length ?? parsePrecisionFromDataType(dataType) ?? 1,
    }
  }
  if (udt === 'int2' || dataType === 'smallint') {
    return { typeId: 'smallint' }
  }
  if (udt === 'int4' || dataType === 'integer') {
    return { typeId: 'integer' }
  }
  if (udt === 'int8' || dataType === 'bigint') {
    return { typeId: 'bigint' }
  }
  if (udt === 'float4' || dataType === 'real') {
    return { typeId: 'real' }
  }
  if (udt === 'float8' || dataType === 'double precision') {
    return { typeId: 'double precision' }
  }
  if (udt === 'numeric' || dataType === 'numeric') {
    return { typeId: 'numeric', numericPrecision, numericScale }
  }
  if (udt === 'bool' || dataType === 'boolean') {
    return { typeId: 'boolean' }
  }
  if (dataType === 'timestamp with time zone' || udt === 'timestamptz') {
    return {
      typeId: 'timestamp with time zone',
      datetimePrecision,
    }
  }
  if (dataType.startsWith('timestamp without time zone') || udt === 'timestamp') {
    return { typeId: 'timestamp', datetimePrecision }
  }
  if (dataType === 'time with time zone' || udt === 'timetz') {
    return { typeId: 'time with time zone', datetimePrecision }
  }
  if (dataType.startsWith('time without time zone') || udt === 'time') {
    return { typeId: 'time', datetimePrecision }
  }
  if (udt === 'bit') {
    return {
      typeId: 'bit',
      length: length ?? parsePrecisionFromDataType(dataType),
    }
  }
  if (udt === 'varbit' || dataType.startsWith('bit varying')) {
    return {
      typeId: 'bit varying',
      length: length ?? parsePrecisionFromDataType(dataType),
    }
  }

  const directMatch = POSTGRES_COLUMN_TYPE_BY_ID.get(
    (udt || dataType) as PostgresColumnTypeId,
  )
  if (directMatch) {
    return createDefaultPostgresColumnTypeState(directMatch.id)
  }

  return createDefaultPostgresColumnTypeState('text')
}

export function postgresColumnTypeStatesEqual(
  a: PostgresColumnTypeState,
  b: PostgresColumnTypeState,
): boolean {
  return (
    buildPostgresColumnTypeSql(a).toLowerCase() ===
    buildPostgresColumnTypeSql(b).toLowerCase()
  )
}

export function validatePostgresColumnTypeState(
  state: PostgresColumnTypeState,
): string | null {
  const definition = getPostgresColumnTypeDefinition(state.typeId)

  for (const property of definition.properties) {
    const value = getPostgresColumnTypePropertyValue(state, property.key)
    const rangeError = getPostgresColumnTypePropertyRangeError(property, value)
    if (rangeError) return rangeError

    if (
      property.key === 'numericScale' &&
      value != null &&
      state.numericPrecision != null &&
      value > state.numericPrecision
    ) {
      return 'Scale cannot be greater than precision.'
    }
  }

  return null
}

export function getPostgresColumnDefaultPlaceholder(
  typeId: PostgresColumnTypeId,
): string {
  switch (typeId) {
    case 'boolean':
      return 'e.g. true or false'
    case 'uuid':
      return 'e.g. gen_random_uuid()'
    case 'timestamp with time zone':
    case 'timestamp':
      return 'e.g. now()'
    case 'json':
    case 'jsonb':
      return "e.g. '{}'::jsonb"
    default:
      return "e.g. 'draft' or 0"
  }
}

export function getPostgresColumnTypeSearchValue(
  definition: PostgresColumnTypeDefinition,
): string {
  return [
    definition.label,
    definition.id,
    definition.group,
    ...(definition.searchTerms ?? []),
  ].join(' ')
}

export function isPostgresDatetimeType(typeId: PostgresColumnTypeId): boolean {
  return DATETIME_TYPE_IDS.has(typeId)
}
