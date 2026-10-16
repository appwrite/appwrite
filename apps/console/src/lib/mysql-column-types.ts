import { buildMysqlInlineEnumTypeSql } from '@/lib/mysql-enum-ddl'
import {
  normalizeMysqlEnumValues,
  parseMysqlEnumValues,
  validateMysqlEnumValues,
} from '@/lib/mysql-enum-metadata'
import type { MysqlTableColumnRow } from '@/lib/mysql-sql'

export type MysqlColumnTypeGroup =
  | 'Text'
  | 'Integer'
  | 'Decimal'
  | 'Boolean'
  | 'Date & time'
  | 'Structured'
  | 'Network'

export type MysqlColumnTypeId =
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
  | 'enum'
  | 'json'
  | 'jsonb'
  | 'bytea'
  | 'bit'
  | 'bit varying'
  | 'inet'
  | 'cidr'
  | 'macaddr'

export type MysqlColumnTypePropertyKey =
  | 'length'
  | 'numericPrecision'
  | 'numericScale'
  | 'datetimePrecision'

export type MysqlColumnTypePropertyUnit =
  | 'characters'
  | 'bits'
  | 'digits'
  | 'decimal places'

export type MysqlColumnTypeProperty = {
  key: MysqlColumnTypePropertyKey
  label: string
  hint?: string
  unit?: MysqlColumnTypePropertyUnit
  min: number
  max: number
  defaultValue?: number
  optional?: boolean
  /** Shown when optional and value is empty (e.g. "unlimited"). */
  optionalEmptyLabel?: string
}

export type MysqlColumnTypeDefinition = {
  id: MysqlColumnTypeId
  label: string
  /** Short helper shown next to the type in the picker. */
  description: string
  group: MysqlColumnTypeGroup
  properties: MysqlColumnTypeProperty[]
  createOnly?: boolean
  /** Included in type search matching (e.g. SQL aliases). */
  searchTerms?: string[]
}

export type MysqlColumnTypeState = {
  typeId: MysqlColumnTypeId
  /** When true, the SQL type is an array (e.g. `text[]`). */
  isArray?: boolean
  length?: number
  numericPrecision?: number
  numericScale?: number
  datetimePrecision?: number
  /** Allowed labels when typeId is `enum`. */
  enumValues?: string[]
}

const LENGTH_PROPERTY = (
  defaults: Omit<MysqlColumnTypeProperty, 'key' | 'label'> & {
    label?: string
  },
): MysqlColumnTypeProperty => ({
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
): MysqlColumnTypeProperty => ({
  key: 'numericPrecision',
  label: 'Precision',
  hint: 'Total number of digits.',
  unit: 'digits',
  min: 1,
  max: 1000,
  defaultValue,
})

const NUMERIC_SCALE_PROPERTY = (defaultValue = 0): MysqlColumnTypeProperty => ({
  key: 'numericScale',
  label: 'Scale',
  hint: 'Digits after the decimal point.',
  unit: 'digits',
  min: 0,
  max: 1000,
  defaultValue,
})

const DATETIME_PRECISION_PROPERTY: MysqlColumnTypeProperty = {
  key: 'datetimePrecision',
  label: 'Fractional seconds',
  hint: 'Number of digits after the decimal point in seconds.',
  unit: 'decimal places',
  min: 0,
  max: 6,
  optional: true,
  optionalEmptyLabel: 'default (6)',
}

export const MYSQL_COLUMN_TYPE_DEFINITIONS: MysqlColumnTypeDefinition[] =
  [
    {
      id: 'text',
      label: 'Text',
      description: 'Unlimited length text',
      group: 'Text',
      properties: [],
      searchTerms: ['text', 'string'],
    },
    {
      id: 'varchar',
      label: 'Varchar',
      description: 'Variable-length text',
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
      description: 'Fixed-length text',
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
      description: '2-byte integer',
      group: 'Integer',
      properties: [],
      searchTerms: ['int2', 'smallint'],
    },
    {
      id: 'integer',
      label: 'Integer',
      description: '4-byte integer',
      group: 'Integer',
      properties: [],
      searchTerms: ['int4', 'integer', 'int'],
    },
    {
      id: 'bigint',
      label: 'Bigint',
      description: '8-byte integer',
      group: 'Integer',
      properties: [],
      searchTerms: ['int8', 'bigint'],
    },
    {
      id: 'smallserial',
      label: 'Smallserial',
      description: 'Auto-incrementing smallint',
      group: 'Integer',
      properties: [],
      createOnly: true,
      searchTerms: ['smallserial', 'serial2'],
    },
    {
      id: 'serial',
      label: 'Serial',
      description: 'Auto-incrementing integer',
      group: 'Integer',
      properties: [],
      createOnly: true,
      searchTerms: ['serial', 'serial4'],
    },
    {
      id: 'bigserial',
      label: 'Bigserial',
      description: 'Auto-incrementing bigint',
      group: 'Integer',
      properties: [],
      createOnly: true,
      searchTerms: ['bigserial', 'serial8'],
    },
    {
      id: 'real',
      label: 'Real',
      description: 'Single-precision float',
      group: 'Decimal',
      properties: [],
      searchTerms: ['float4', 'real'],
    },
    {
      id: 'double precision',
      label: 'Double precision',
      description: 'Double-precision float',
      group: 'Decimal',
      properties: [],
      searchTerms: ['float8', 'double precision', 'float'],
    },
    {
      id: 'numeric',
      label: 'Numeric',
      description: 'Exact decimal number',
      group: 'Decimal',
      properties: [NUMERIC_PRECISION_PROPERTY(), NUMERIC_SCALE_PROPERTY()],
      searchTerms: ['numeric', 'decimal'],
    },
    {
      id: 'boolean',
      label: 'Boolean',
      description: 'True or false',
      group: 'Boolean',
      properties: [],
      searchTerms: ['bool', 'boolean'],
    },
    {
      id: 'date',
      label: 'Date',
      description: 'Calendar date',
      group: 'Date & time',
      properties: [],
    },
    {
      id: 'time',
      label: 'Time',
      description: 'Time of day',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['time without time zone'],
    },
    {
      id: 'time with time zone',
      label: 'Time with time zone',
      description: 'Time with time zone',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['timetz', 'time with time zone'],
    },
    {
      id: 'timestamp',
      label: 'Timestamp',
      description: 'Date and time',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['timestamp without time zone'],
    },
    {
      id: 'timestamp with time zone',
      label: 'Timestamp with time zone',
      description: 'Date and time with time zone',
      group: 'Date & time',
      properties: [DATETIME_PRECISION_PROPERTY],
      searchTerms: ['timestamptz', 'timestamp with time zone'],
    },
    {
      id: 'interval',
      label: 'Interval',
      description: 'Time span',
      group: 'Date & time',
      properties: [],
    },
    {
      id: 'uuid',
      label: 'UUID',
      description: 'Unique identifier',
      group: 'Structured',
      properties: [],
      searchTerms: ['uuid'],
    },
    {
      id: 'enum',
      label: 'Enum',
      description: 'One value from a fixed list',
      group: 'Structured',
      properties: [],
      searchTerms: ['enum', 'enumeration', 'list'],
    },
    {
      id: 'json',
      label: 'JSON',
      description: 'JSON stored as text',
      group: 'Structured',
      properties: [],
    },
    {
      id: 'jsonb',
      label: 'JSONB',
      description: 'Binary JSON',
      group: 'Structured',
      properties: [],
    },
    {
      id: 'bytea',
      label: 'Bytea',
      description: 'Binary data',
      group: 'Structured',
      properties: [],
    },
    {
      id: 'bit',
      label: 'Bit',
      description: 'Fixed-length bit string',
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
      description: 'Variable-length bit string',
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
    {
      id: 'inet',
      label: 'Inet',
      description: 'IPv4 or IPv6 address',
      group: 'Network',
      properties: [],
    },
    {
      id: 'cidr',
      label: 'CIDR',
      description: 'IPv4 or IPv6 network',
      group: 'Network',
      properties: [],
    },
    {
      id: 'macaddr',
      label: 'MAC address',
      description: 'MAC address',
      group: 'Network',
      properties: [],
    },
  ]

const MYSQL_COLUMN_TYPE_BY_ID = new Map(
  MYSQL_COLUMN_TYPE_DEFINITIONS.map((definition) => [
    definition.id,
    definition,
  ]),
)

export const MYSQL_COLUMN_TYPE_GROUPS: MysqlColumnTypeGroup[] = [
  'Text',
  'Integer',
  'Decimal',
  'Boolean',
  'Date & time',
  'Structured',
  'Network',
]

const DATETIME_TYPE_IDS = new Set<MysqlColumnTypeId>([
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

export function getMysqlColumnTypeDefinition(
  typeId: MysqlColumnTypeId,
): MysqlColumnTypeDefinition {
  return MYSQL_COLUMN_TYPE_BY_ID.get(typeId)!
}

export function getMysqlColumnTypePropertyValue(
  state: MysqlColumnTypeState,
  key: MysqlColumnTypePropertyKey,
): number | undefined {
  return state[key]
}

function formatLimitNumber(value: number): string {
  return value.toLocaleString()
}

export function formatMysqlColumnTypePropertyLimits(
  property: MysqlColumnTypeProperty,
): string {
  const min = formatLimitNumber(property.min)
  const max = formatLimitNumber(property.max)
  const unit = property.unit ? ` ${property.unit}` : ''

  if (property.min === property.max) {
    return `${min}${unit}`
  }

  return `${min} to ${max}${unit}`
}

export function getMysqlColumnTypePropertyPlaceholder(
  property: MysqlColumnTypeProperty,
): string | undefined {
  if (!property.optional) {
    return property.defaultValue != null
      ? String(property.defaultValue)
      : undefined
  }

  return property.optionalEmptyLabel ?? 'Default'
}

export function getMysqlColumnTypePropertyRangeError(
  property: MysqlColumnTypeProperty,
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

export function createDefaultMysqlColumnTypeState(
  typeId: MysqlColumnTypeId = 'text',
): MysqlColumnTypeState {
  const definition = getMysqlColumnTypeDefinition(typeId)
  const state: MysqlColumnTypeState = { typeId }

  for (const property of definition.properties) {
    if (property.defaultValue != null) {
      state[property.key] = property.defaultValue
    }
  }

  if (typeId === 'enum') {
    state.enumValues = ['']
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

function buildMysqlColumnBaseTypeSql(state: MysqlColumnTypeState): string {
  switch (state.typeId) {
    case 'varchar':
      return `VARCHAR(${state.length ?? 255})`
    case 'char':
      return `CHAR(${state.length ?? 1})`
    case 'text':
      return 'TEXT'
    case 'smallint':
      return 'SMALLINT'
    case 'integer':
      return 'INT'
    case 'bigint':
      return 'BIGINT'
    case 'smallserial':
      return 'SMALLINT'
    case 'serial':
      return 'INT'
    case 'bigserial':
      return 'BIGINT'
    case 'real':
      return 'FLOAT'
    case 'double precision':
      return 'DOUBLE'
    case 'numeric': {
      const precision = state.numericPrecision
      const scale = state.numericScale
      if (precision != null && scale != null) {
        return `DECIMAL(${precision},${scale})`
      }
      if (precision != null) {
        return `DECIMAL(${precision})`
      }
      return 'DECIMAL'
    }
    case 'boolean':
      return 'BOOLEAN'
    case 'date':
      return 'DATE'
    case 'time':
      return appendPrecisionSuffix('TIME', state.datetimePrecision)
    case 'time with time zone':
      return appendPrecisionSuffix('TIME', state.datetimePrecision)
    case 'timestamp':
      return appendPrecisionSuffix('DATETIME', state.datetimePrecision)
    case 'timestamp with time zone':
      return appendPrecisionSuffix('TIMESTAMP', state.datetimePrecision)
    case 'interval':
      return 'VARCHAR(64)'
    case 'uuid':
      return 'CHAR(36)'
    case 'enum':
      return buildMysqlInlineEnumTypeSql(normalizeMysqlEnumValues(state.enumValues))
    case 'json':
    case 'jsonb':
      return 'JSON'
    case 'bytea':
      return 'BLOB'
    case 'bit':
      return state.length != null ? `BIT(${state.length})` : 'BIT'
    case 'bit varying':
      return state.length != null ? `VARBINARY(${Math.ceil(state.length / 8)})` : 'VARBINARY(255)'
    case 'inet':
    case 'cidr':
      return 'VARCHAR(45)'
    case 'macaddr':
      return 'VARCHAR(17)'
    default:
      return state.typeId
  }
}

export function buildMysqlColumnTypeSql(state: MysqlColumnTypeState): string {
  // MySQL has no array column types; ignore isArray for SQL emission.
  const base = buildMysqlColumnBaseTypeSql(state)
  if (isMysqlSerialColumnType(state.typeId)) {
    return `${base} AUTO_INCREMENT`
  }
  return base
}

export function isMysqlSerialColumnType(typeId: MysqlColumnTypeId): boolean {
  return (
    typeId === 'serial' || typeId === 'bigserial' || typeId === 'smallserial'
  )
}

export function formatMysqlColumnTypeLabel(
  state: MysqlColumnTypeState,
): string {
  const definition = getMysqlColumnTypeDefinition(state.typeId)
  const baseSql = buildMysqlColumnBaseTypeSql(state)
  let label = definition.label
  if (baseSql !== state.typeId) {
    const paramsMatch = baseSql.match(/\((.+)\)$/)
    if (paramsMatch) {
      label = `${definition.label} (${paramsMatch[1]})`
    }
  }
  return state.isArray ? `${label}[]` : label
}

function normalizeDataTypeLabel(value: string): string {
  return value.trim().toLowerCase().replace(/\s+/g, ' ')
}

function parsePrecisionFromDataType(dataType: string): number | undefined {
  const match = dataType.match(/\((\d+)\)$/)
  if (!match) return undefined
  return parseOptionalInt(match[1])
}

function parseMysqlColumnBaseTypeState(
  udt: string,
  dataType: string,
  length: number | undefined,
  numericPrecision: number | undefined,
  numericScale: number | undefined,
  datetimePrecision: number | undefined,
): MysqlColumnTypeState {
  const normalizedUdt = udt.toLowerCase().replace(/\s+/g, ' ')
  const normalizedData = dataType.toLowerCase().replace(/\s+/g, ' ')

  if (
    normalizedUdt.startsWith('varchar') ||
    normalizedData.startsWith('varchar') ||
    normalizedData.startsWith('character varying')
  ) {
    return {
      typeId: 'varchar',
      length: length ?? parsePrecisionFromDataType(dataType) ?? 255,
    }
  }
  if (
    normalizedUdt.startsWith('char') ||
    normalizedData === 'char' ||
    normalizedData === 'character'
  ) {
    return {
      typeId: 'char',
      length: length ?? parsePrecisionFromDataType(dataType) ?? 1,
    }
  }
  if (normalizedData === 'tinyint' || normalizedUdt.startsWith('tinyint')) {
    // MySQL often stores BOOLEAN as tinyint(1)
    if (length === 1 || normalizedUdt.includes('(1)')) {
      return { typeId: 'boolean' }
    }
    return { typeId: 'smallint' }
  }
  if (normalizedData === 'smallint' || normalizedUdt.startsWith('smallint')) {
    return { typeId: 'smallint' }
  }
  if (
    normalizedData === 'int' ||
    normalizedData === 'integer' ||
    normalizedData === 'mediumint' ||
    normalizedUdt.startsWith('int')
  ) {
    return { typeId: 'integer' }
  }
  if (normalizedData === 'bigint' || normalizedUdt.startsWith('bigint')) {
    return { typeId: 'bigint' }
  }
  if (
    normalizedData === 'float' ||
    normalizedData === 'real' ||
    normalizedUdt.startsWith('float')
  ) {
    return { typeId: 'real' }
  }
  if (
    normalizedData === 'double' ||
    normalizedData === 'double precision' ||
    normalizedUdt.startsWith('double')
  ) {
    return { typeId: 'double precision' }
  }
  if (
    normalizedData === 'decimal' ||
    normalizedData === 'numeric' ||
    normalizedUdt.startsWith('decimal') ||
    normalizedUdt.startsWith('numeric')
  ) {
    return { typeId: 'numeric', numericPrecision, numericScale }
  }
  if (normalizedData === 'boolean' || normalizedData === 'bool') {
    return { typeId: 'boolean' }
  }
  if (normalizedData === 'datetime' || normalizedUdt.startsWith('datetime')) {
    return { typeId: 'timestamp', datetimePrecision }
  }
  if (normalizedData === 'timestamp' || normalizedUdt.startsWith('timestamp')) {
    return { typeId: 'timestamp with time zone', datetimePrecision }
  }
  if (normalizedData === 'time' || normalizedUdt.startsWith('time')) {
    return { typeId: 'time', datetimePrecision }
  }
  if (normalizedData === 'date') {
    return { typeId: 'date' }
  }
  if (normalizedData === 'enum' || normalizedUdt.startsWith('enum')) {
    return {
      typeId: 'enum',
      enumValues: parseMysqlEnumValues(udt || dataType),
    }
  }
  if (normalizedData === 'json' || normalizedUdt.startsWith('json')) {
    return { typeId: 'json' }
  }
  if (
    normalizedData.includes('blob') ||
    normalizedData.includes('binary') ||
    normalizedUdt.includes('blob')
  ) {
    return { typeId: 'bytea' }
  }
  if (normalizedData === 'bit' || normalizedUdt.startsWith('bit')) {
    return {
      typeId: 'bit',
      length: length ?? parsePrecisionFromDataType(dataType),
    }
  }
  if (normalizedData === 'text' || normalizedData.includes('text')) {
    return { typeId: 'text' }
  }

  const directMatch = MYSQL_COLUMN_TYPE_BY_ID.get(
    (udt || dataType) as MysqlColumnTypeId,
  )
  if (directMatch) {
    return createDefaultMysqlColumnTypeState(directMatch.id)
  }

  return createDefaultMysqlColumnTypeState('text')
}

export function parseMysqlColumnTypeFromRow(
  row: Pick<
    MysqlTableColumnRow,
    | 'data_type'
    | 'udt_name'
    | 'character_maximum_length'
    | 'numeric_precision'
    | 'numeric_scale'
    | 'datetime_precision'
  >,
): MysqlColumnTypeState {
  const rawUdt = row.udt_name.toLowerCase()
  const rawDataType = normalizeDataTypeLabel(row.data_type)
  const length = parseOptionalInt(row.character_maximum_length)
  const numericPrecision = parseOptionalInt(row.numeric_precision)
  const numericScale = parseOptionalInt(row.numeric_scale)
  const datetimePrecision =
    parseOptionalInt(row.datetime_precision) ??
    parsePrecisionFromDataType(rawDataType)

  return parseMysqlColumnBaseTypeState(
    rawUdt,
    rawDataType,
    length,
    numericPrecision,
    numericScale,
    datetimePrecision,
  )
}

export function mysqlColumnTypeStatesEqual(
  a: MysqlColumnTypeState,
  b: MysqlColumnTypeState,
): boolean {
  return (
    buildMysqlColumnTypeSql(a).toLowerCase() ===
    buildMysqlColumnTypeSql(b).toLowerCase()
  )
}

export function validateMysqlColumnTypeState(
  state: MysqlColumnTypeState,
): string | null {
  if (state.typeId === 'enum') {
    return validateMysqlEnumValues(state.enumValues)
  }

  const definition = getMysqlColumnTypeDefinition(state.typeId)

  for (const property of definition.properties) {
    const value = getMysqlColumnTypePropertyValue(state, property.key)
    const rangeError = getMysqlColumnTypePropertyRangeError(property, value)
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

export function getMysqlColumnDefaultPlaceholder(
  typeId: MysqlColumnTypeId,
): string {
  switch (typeId) {
    case 'boolean':
      return 'NULL'
    case 'uuid':
      return 'NULL'
    case 'enum':
      return 'NULL'
    case 'timestamp with time zone':
    case 'timestamp':
      return 'CURRENT_TIMESTAMP'
    case 'json':
    case 'jsonb':
      return `CAST('{}' AS JSON)`
    default:
      return 'NULL'
  }
}

export function getMysqlColumnTypeSearchValue(
  definition: MysqlColumnTypeDefinition,
): string {
  return [
    definition.label,
    definition.description,
    definition.id,
    definition.group,
    ...(definition.searchTerms ?? []),
  ].join(' ')
}

export function isMysqlDatetimeType(typeId: MysqlColumnTypeId): boolean {
  return DATETIME_TYPE_IDS.has(typeId)
}
