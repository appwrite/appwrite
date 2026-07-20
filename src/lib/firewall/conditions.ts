import { Query } from '@appwrite.io/console'

/** Resource scopes supported by Firewall rules. */
export const FIREWALL_RESOURCE_TYPES = [
  { value: 'api', label: 'API' },
  { value: 'functions', label: 'Functions' },
  { value: 'sites', label: 'Sites' },
] as const

export type FirewallResourceType =
  (typeof FIREWALL_RESOURCE_TYPES)[number]['value']

/** Condition attributes shown in the rule builder. */
export const FIREWALL_CONDITION_ATTRIBUTES = [
  { value: 'ip', label: 'IP address' },
  { value: 'path', label: 'Request path' },
  { value: 'method', label: 'HTTP method' },
  { value: 'country', label: 'Country' },
  { value: 'userAgent', label: 'User agent' },
] as const

export type FirewallConditionAttribute =
  (typeof FIREWALL_CONDITION_ATTRIBUTES)[number]['value']

/**
 * Operators mirror the usage `listEvents` filter operators so rule conditions
 * and affected-traffic estimations speak the same language.
 * `isNull` / `isNotNull` take no value.
 */
export const FIREWALL_CONDITION_OPERATORS = [
  { value: 'equal', label: 'Equals' },
  { value: 'notEqual', label: 'Does not equal' },
  { value: 'contains', label: 'Contains' },
  { value: 'startsWith', label: 'Starts with' },
  { value: 'endsWith', label: 'Ends with' },
  { value: 'isNull', label: 'Is empty', noValue: true },
  { value: 'isNotNull', label: 'Is not empty', noValue: true },
] as const

export type FirewallConditionOperator =
  (typeof FIREWALL_CONDITION_OPERATORS)[number]['value']

export type FirewallConditionOperatorDef = {
  value: FirewallConditionOperator
  label: string
  noValue?: boolean
}

/** Operators that filter on the value being absent — no value input is shown. */
const NO_VALUE_OPERATORS = new Set<FirewallConditionOperator>([
  'isNull',
  'isNotNull',
])

/** Text-matching operators only apply to free-text attributes. */
const TEXT_MATCH_OPERATORS = new Set<FirewallConditionOperator>([
  'contains',
  'startsWith',
  'endsWith',
])

export function isNoValueOperator(operator: FirewallConditionOperator): boolean {
  return NO_VALUE_OPERATORS.has(operator)
}

export function isTextMatchOperator(
  operator: FirewallConditionOperator,
): boolean {
  return TEXT_MATCH_OPERATORS.has(operator)
}

/** HTTP methods selectable for the method condition attribute. */
export const FIREWALL_HTTP_METHODS = [
  'GET',
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
  'OPTIONS',
  'HEAD',
] as const

export type FirewallHttpMethod = (typeof FIREWALL_HTTP_METHODS)[number]

/**
 * Attributes backed by a fixed enum. Only `method` — mirrors usage, where
 * `method` is an enum column (equal / not equal / is empty / is not empty) while
 * `country` is a free-text string column with the full operator set.
 */
const DISCRETE_VALUE_ATTRIBUTES = new Set<FirewallConditionAttribute>([
  'method',
])

/**
 * Operators available for a given attribute.
 * Enum-backed attributes (method) drop the text-matching operators
 * (contains / starts with / ends with) since they filter on a fixed value.
 */
export function getOperatorsForAttribute(
  attribute: FirewallConditionAttribute,
): ReadonlyArray<FirewallConditionOperatorDef> {
  if (DISCRETE_VALUE_ATTRIBUTES.has(attribute)) {
    return FIREWALL_CONDITION_OPERATORS.filter(
      (op) => !TEXT_MATCH_OPERATORS.has(op.value),
    )
  }
  return FIREWALL_CONDITION_OPERATORS
}

export function isOperatorAllowedForAttribute(
  attribute: FirewallConditionAttribute,
  operator: FirewallConditionOperator,
): boolean {
  return getOperatorsForAttribute(attribute).some((op) => op.value === operator)
}

export type FirewallConditionDraft = {
  id: string
  attribute: FirewallConditionAttribute
  operator: FirewallConditionOperator
  value: string
}

export type ParsedFirewallCondition = {
  attribute: string
  operator: string
  values: string[]
}

export function createEmptyConditionDraft(): FirewallConditionDraft {
  return {
    id: `cond_${Math.random().toString(36).slice(2, 10)}`,
    attribute: 'path',
    operator: 'equal',
    value: '',
  }
}

function buildQueryString(draft: FirewallConditionDraft): string | null {
  if (isNoValueOperator(draft.operator)) {
    return draft.operator === 'isNotNull'
      ? Query.isNotNull(draft.attribute)
      : Query.isNull(draft.attribute)
  }

  const raw = draft.value.trim()
  if (!raw) return null
  const value = draft.attribute === 'country' ? raw.toLowerCase() : raw

  switch (draft.operator) {
    case 'equal':
      return Query.equal(draft.attribute, value)
    case 'notEqual':
      return Query.notEqual(draft.attribute, value)
    case 'contains':
      return Query.contains(draft.attribute, value)
    case 'startsWith':
      return Query.startsWith(draft.attribute, value)
    case 'endsWith':
      return Query.endsWith(draft.attribute, value)
    default:
      return Query.equal(draft.attribute, value)
  }
}

/**
 * Build the conditions payload for create/update rule calls.
 * The SDK types this as a string, but the API expects an array of Query strings.
 */
export function serializeFirewallConditions(
  drafts: FirewallConditionDraft[],
): string[] {
  return drafts
    .map(buildQueryString)
    .filter((value): value is string => Boolean(value))
}

export function parseFirewallConditions(
  conditions: unknown,
): ParsedFirewallCondition[] {
  const rawList: unknown[] = Array.isArray(conditions)
    ? conditions
    : typeof conditions === 'string'
      ? (() => {
          try {
            const parsed = JSON.parse(conditions)
            return Array.isArray(parsed) ? parsed : [conditions]
          } catch {
            return [conditions]
          }
        })()
      : conditions && typeof conditions === 'object'
        ? Object.values(conditions as Record<string, unknown>)
        : []

  return rawList
    .map((item): ParsedFirewallCondition | null => {
      if (typeof item === 'string') {
        try {
          const parsed = JSON.parse(item) as {
            method?: string
            attribute?: string
            values?: unknown[]
          }
          if (!parsed.method || !parsed.attribute) return null
          return {
            attribute: parsed.attribute,
            operator: parsed.method,
            values: (parsed.values ?? []).map(String),
          }
        } catch {
          return {
            attribute: 'condition',
            operator: 'equal',
            values: [item],
          }
        }
      }

      if (item && typeof item === 'object') {
        const record = item as Record<string, unknown>
        const attribute = String(
          record.attribute ?? record.field ?? record.key ?? 'condition',
        )
        const operator = String(record.method ?? record.operator ?? 'equal')
        const valuesRaw = record.values ?? record.value
        const values = Array.isArray(valuesRaw)
          ? valuesRaw.map(String)
          : valuesRaw != null
            ? [String(valuesRaw)]
            : []
        return { attribute, operator, values }
      }

      return null
    })
    .filter((item): item is ParsedFirewallCondition => item != null)
}

export function draftsFromParsedConditions(
  parsed: ParsedFirewallCondition[],
): FirewallConditionDraft[] {
  if (parsed.length === 0) return [createEmptyConditionDraft()]

  return parsed.map((item) => {
    const attribute = (
      FIREWALL_CONDITION_ATTRIBUTES.some((a) => a.value === item.attribute)
        ? item.attribute
        : 'ip'
    ) as FirewallConditionAttribute

    const operator = (
      FIREWALL_CONDITION_OPERATORS.some((o) => o.value === item.operator) &&
      isOperatorAllowedForAttribute(attribute, item.operator as FirewallConditionOperator)
        ? item.operator
        : 'equal'
    ) as FirewallConditionOperator

    const rawValue = item.values[0] ?? ''
    const value =
      attribute === 'country' ? rawValue.toLowerCase() : rawValue

    return {
      id: `cond_${Math.random().toString(36).slice(2, 10)}`,
      attribute,
      operator,
      value,
    }
  })
}

export function formatConditionSummary(
  condition: ParsedFirewallCondition,
): string {
  const attr =
    FIREWALL_CONDITION_ATTRIBUTES.find((a) => a.value === condition.attribute)
      ?.label ?? condition.attribute
  const op =
    FIREWALL_CONDITION_OPERATORS.find((o) => o.value === condition.operator)
      ?.label ?? condition.operator
  const value = condition.values.join(', ')
  return value ? `${attr} ${op} ${value}` : `${attr} ${op}`
}
