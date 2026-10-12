import {
  parseQueryEntryValue,
  subscriptionQueryNeedsValue,
  type RealtimeQueryValueType,
  type SubscriptionQueryEntry,
} from '@/lib/realtime/subscription-queries'

export type SnippetSdkId =
  | 'client-web'
  | 'client-flutter'
  | 'client-apple'
  | 'client-android-kotlin'
  | 'client-android-java'
  | 'client-react-native'

function parseBetweenValue(value: string): [string, string] | null {
  const parts = value
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
  if (parts.length >= 2) return [parts[0], parts[1]]
  return null
}

function escapeSingleQuoted(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")
}

function escapeDoubleQuoted(value: string): string {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
}

function jsLiteralFromCoerced(value: string | number | boolean): string {
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  if (typeof value === 'number') return String(value)
  return `'${escapeSingleQuoted(value)}'`
}

function swiftLiteralFromCoerced(value: string | number | boolean): string {
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  if (typeof value === 'number') return String(value)
  return `"${escapeDoubleQuoted(value)}"`
}

function javaLiteralFromCoerced(value: string | number | boolean): string {
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  if (typeof value === 'number') return String(value)
  return `"${escapeDoubleQuoted(value)}"`
}

function coercePart(
  value: string,
  valueType: RealtimeQueryValueType,
): string | number | boolean {
  const parsed = parseQueryEntryValue({
    id: '',
    attribute: 'x',
    operatorKey: 'equal',
    value,
    valueType,
  })
  return parsed === null || parsed === '' ? value : parsed
}

function entryScalarValue(entry: SubscriptionQueryEntry): string | number | boolean {
  const parsed = parseQueryEntryValue(entry)
  return parsed === null || parsed === '' ? entry.value.trim() : parsed
}

function indentLines(lines: string[], indent: string): string {
  return lines.map((line) => `${indent}${line}`).join('\n')
}

type QueryDialect = {
  jsAttr: (attribute: string) => string
  swiftAttr: (attribute: string) => string
  javaAttr: (attribute: string) => string
  jsValue: (value: string | number | boolean) => string
  swiftValue: (value: string | number | boolean) => string
  javaValue: (value: string | number | boolean) => string
}

const DIALECT: QueryDialect = {
  jsAttr: (attribute) => `'${escapeSingleQuoted(attribute)}'`,
  swiftAttr: (attribute) => `"${escapeDoubleQuoted(attribute)}"`,
  javaAttr: (attribute) => `"${escapeDoubleQuoted(attribute)}"`,
  jsValue: jsLiteralFromCoerced,
  swiftValue: swiftLiteralFromCoerced,
  javaValue: javaLiteralFromCoerced,
}

function buildJsQuery(entry: SubscriptionQueryEntry): string {
  const attribute = entry.attribute.trim()
  const { operatorKey, valueType } = entry
  const attr = DIALECT.jsAttr(attribute)

  if (!subscriptionQueryNeedsValue(operatorKey)) {
    return `Query.${operatorKey}(${attr})`
  }

  if (operatorKey === 'between' || operatorKey === 'notBetween') {
    const pair = parseBetweenValue(entry.value)
    if (pair) {
      const method = operatorKey === 'between' ? 'between' : 'notBetween'
      return `Query.${method}(${attr}, ${DIALECT.jsValue(coercePart(pair[0], valueType))}, ${DIALECT.jsValue(coercePart(pair[1], valueType))})`
    }
  }

  if (operatorKey === 'exists') {
    return `Query.exists([${attr}])`
  }

  if (operatorKey === 'notExists') {
    return `Query.notExists([${attr}])`
  }

  return `Query.${operatorKey}(${attr}, ${DIALECT.jsValue(entryScalarValue(entry))})`
}

function buildSwiftQuery(entry: SubscriptionQueryEntry): string {
  const attribute = entry.attribute.trim()
  const { operatorKey, valueType } = entry
  const attr = DIALECT.swiftAttr(attribute)

  if (!subscriptionQueryNeedsValue(operatorKey)) {
    return `Query.${operatorKey}(${attr})`
  }

  if (operatorKey === 'between' || operatorKey === 'notBetween') {
    const pair = parseBetweenValue(entry.value)
    if (pair) {
      const method = operatorKey === 'between' ? 'between' : 'notBetween'
      return `Query.${method}(${attr}, value: ${DIALECT.swiftValue(coercePart(pair[0], valueType))}, value: ${DIALECT.swiftValue(coercePart(pair[1], valueType))})`
    }
  }

  if (operatorKey === 'exists') {
    return `Query.exists([${attr}])`
  }

  if (operatorKey === 'notExists') {
    return `Query.notExists([${attr}])`
  }

  return `Query.${operatorKey}(${attr}, value: ${DIALECT.swiftValue(entryScalarValue(entry))})`
}

function buildJavaQuery(entry: SubscriptionQueryEntry): string {
  const attribute = entry.attribute.trim()
  const { operatorKey, valueType } = entry
  const attr = DIALECT.javaAttr(attribute)

  if (!subscriptionQueryNeedsValue(operatorKey)) {
    return `Query.${operatorKey}(${attr})`
  }

  if (operatorKey === 'between' || operatorKey === 'notBetween') {
    const pair = parseBetweenValue(entry.value)
    if (pair) {
      const method = operatorKey === 'between' ? 'between' : 'notBetween'
      return `Query.${method}(${attr}, ${DIALECT.javaValue(coercePart(pair[0], valueType))}, ${DIALECT.javaValue(coercePart(pair[1], valueType))})`
    }
  }

  if (operatorKey === 'exists') {
    return `Query.exists(Arrays.asList(${attr}))`
  }

  if (operatorKey === 'notExists') {
    return `Query.notExists(Arrays.asList(${attr}))`
  }

  return `Query.${operatorKey}(${attr}, ${DIALECT.javaValue(entryScalarValue(entry))})`
}

const QUERY_BUILDERS: Record<SnippetSdkId, (entry: SubscriptionQueryEntry) => string> =
  {
    'client-web': buildJsQuery,
    'client-react-native': buildJsQuery,
    'client-flutter': buildJsQuery,
    'client-apple': buildSwiftQuery,
    'client-android-kotlin': buildJavaQuery,
    'client-android-java': buildJavaQuery,
  }

export function buildSdkQueryCalls(
  entries: SubscriptionQueryEntry[],
  sdkId: SnippetSdkId,
): string[] {
  const builder = QUERY_BUILDERS[sdkId]
  return entries
    .filter((entry) => entry.attribute.trim())
    .map((entry) => builder(entry))
}

export function formatSdkQueryArray(
  queryCalls: string[],
  sdkId: SnippetSdkId,
): string {
  if (queryCalls.length === 0) return ''

  switch (sdkId) {
    case 'client-web':
    case 'client-react-native':
      return `[\n${indentLines(
        queryCalls.map((query) => `${query},`),
        '        ',
      )}\n    ]`
    case 'client-flutter':
      return `[\n${indentLines(
        queryCalls.map((query) => `${query},`),
        '        ',
      )}\n    ]`
    case 'client-apple':
      return `[\n${indentLines(
        queryCalls.map((query) => `${query},`),
        '        ',
      )}\n    ]`
    case 'client-android-kotlin':
      return `setOf(\n${indentLines(
        queryCalls.map((query) => `${query},`),
        '        ',
      )}\n    )`
    case 'client-android-java':
      return `new HashSet<>(Arrays.asList(\n${indentLines(
        queryCalls.map((query) => `${query},`),
        '            ',
      )}\n        ))`
    default:
      return `[${queryCalls.join(', ')}]`
  }
}
