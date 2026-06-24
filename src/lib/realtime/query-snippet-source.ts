import {
  subscriptionQueryNeedsValue,
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

function jsLiteral(value: string): string {
  if (value === 'true' || value === 'false') return value
  if (/^-?\d+(\.\d+)?$/.test(value)) return value
  return `'${escapeSingleQuoted(value)}'`
}

function swiftLiteral(value: string): string {
  if (value === 'true' || value === 'false') return value
  if (/^-?\d+(\.\d+)?$/.test(value)) return value
  return `"${escapeDoubleQuoted(value)}"`
}

function javaLiteral(value: string): string {
  return `"${escapeDoubleQuoted(value)}"`
}

function indentLines(lines: string[], indent: string): string {
  return lines.map((line) => `${indent}${line}`).join('\n')
}

type QueryDialect = {
  jsAttr: (attribute: string) => string
  swiftAttr: (attribute: string) => string
  javaAttr: (attribute: string) => string
  jsValue: (value: string) => string
  swiftValue: (value: string) => string
  javaValue: (value: string) => string
}

const DIALECT: QueryDialect = {
  jsAttr: (attribute) => `'${escapeSingleQuoted(attribute)}'`,
  swiftAttr: (attribute) => `"${escapeDoubleQuoted(attribute)}"`,
  javaAttr: (attribute) => `"${escapeDoubleQuoted(attribute)}"`,
  jsValue: jsLiteral,
  swiftValue: swiftLiteral,
  javaValue: javaLiteral,
}

function buildJsQuery(entry: SubscriptionQueryEntry): string {
  const attribute = entry.attribute.trim()
  const { operatorKey } = entry
  const attr = DIALECT.jsAttr(attribute)

  if (!subscriptionQueryNeedsValue(operatorKey)) {
    return `Query.${operatorKey}(${attr})`
  }

  if (operatorKey === 'between' || operatorKey === 'notBetween') {
    const pair = parseBetweenValue(entry.value)
    if (pair) {
      const method = operatorKey === 'between' ? 'between' : 'notBetween'
      return `Query.${method}(${attr}, ${DIALECT.jsValue(pair[0])}, ${DIALECT.jsValue(pair[1])})`
    }
  }

  if (operatorKey === 'exists') {
    return `Query.exists([${attr}])`
  }

  if (operatorKey === 'notExists') {
    return `Query.notExists([${attr}])`
  }

  return `Query.${operatorKey}(${attr}, ${DIALECT.jsValue(entry.value.trim())})`
}

function buildSwiftQuery(entry: SubscriptionQueryEntry): string {
  const attribute = entry.attribute.trim()
  const { operatorKey } = entry
  const attr = DIALECT.swiftAttr(attribute)

  if (!subscriptionQueryNeedsValue(operatorKey)) {
    return `Query.${operatorKey}(${attr})`
  }

  if (operatorKey === 'between' || operatorKey === 'notBetween') {
    const pair = parseBetweenValue(entry.value)
    if (pair) {
      const method = operatorKey === 'between' ? 'between' : 'notBetween'
      return `Query.${method}(${attr}, value: ${DIALECT.swiftValue(pair[0])}, value: ${DIALECT.swiftValue(pair[1])})`
    }
  }

  if (operatorKey === 'exists') {
    return `Query.exists([${attr}])`
  }

  if (operatorKey === 'notExists') {
    return `Query.notExists([${attr}])`
  }

  return `Query.${operatorKey}(${attr}, value: ${DIALECT.swiftValue(entry.value.trim())})`
}

function buildJavaQuery(entry: SubscriptionQueryEntry): string {
  const attribute = entry.attribute.trim()
  const { operatorKey } = entry
  const attr = DIALECT.javaAttr(attribute)

  if (!subscriptionQueryNeedsValue(operatorKey)) {
    return `Query.${operatorKey}(${attr})`
  }

  if (operatorKey === 'between' || operatorKey === 'notBetween') {
    const pair = parseBetweenValue(entry.value)
    if (pair) {
      const method = operatorKey === 'between' ? 'between' : 'notBetween'
      return `Query.${method}(${attr}, ${DIALECT.javaValue(pair[0])}, ${DIALECT.javaValue(pair[1])})`
    }
  }

  if (operatorKey === 'exists') {
    return `Query.exists(Arrays.asList(${attr}))`
  }

  if (operatorKey === 'notExists') {
    return `Query.notExists(Arrays.asList(${attr}))`
  }

  return `Query.${operatorKey}(${attr}, ${DIALECT.javaValue(entry.value.trim())})`
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
