import { Query } from '@appwrite.io/console'

/** Resource scopes supported by Firewall rules. */
export const FIREWALL_RESOURCE_TYPES = [
  { value: 'api', label: 'API' },
  { value: 'functions', label: 'Functions' },
  { value: 'sites', label: 'Sites' },
] as const

export type FirewallResourceType =
  (typeof FIREWALL_RESOURCE_TYPES)[number]['value']

export function isFirewallResourceType(
  value: unknown,
): value is FirewallResourceType {
  return (
    value === 'api' || value === 'functions' || value === 'sites'
  )
}

export function parseFirewallResourceTypeSearch(
  value: unknown,
): FirewallResourceType | undefined {
  return isFirewallResourceType(value) ? value : undefined
}

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
  { value: 'notEqual', label: 'Not equal' },
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

/** Free-text attributes get the full usage `listEvents` operator set. */
const FREE_TEXT_OPERATORS: ReadonlyArray<FirewallConditionOperator> = [
  'equal',
  'notEqual',
  'contains',
  'startsWith',
  'endsWith',
  'isNull',
  'isNotNull',
]

/**
 * Operators offered per attribute. All values also exist in usage `listEvents`
 * so affected-traffic estimates stay accurate.
 * - Free text (ip / path / userAgent): full set.
 * - `method`: enum-backed, equality + presence only (no text matching).
 * - `country`: picker-backed, kept to the basic set (equal / not equal / contains).
 */
const OPERATORS_BY_ATTRIBUTE: Record<
  FirewallConditionAttribute,
  ReadonlyArray<FirewallConditionOperator>
> = {
  ip: FREE_TEXT_OPERATORS,
  path: FREE_TEXT_OPERATORS,
  userAgent: FREE_TEXT_OPERATORS,
  method: ['equal', 'notEqual', 'isNull', 'isNotNull'],
  country: ['equal', 'notEqual', 'contains'],
}

/** Operators available for a given attribute (display order is preserved). */
export function getOperatorsForAttribute(
  attribute: FirewallConditionAttribute,
): ReadonlyArray<FirewallConditionOperatorDef> {
  const allowed = new Set(OPERATORS_BY_ATTRIBUTE[attribute])
  return FIREWALL_CONDITION_OPERATORS.filter((op) => allowed.has(op.value))
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

/**
 * A draft is complete when it will actually serialize into a query:
 * no-value operators (is empty / is not empty) always do; every other
 * operator needs a non-empty value. Incomplete drafts are silently dropped
 * by `serializeFirewallConditions`, so callers should block submit on them.
 */
export function isConditionDraftComplete(
  draft: FirewallConditionDraft,
): boolean {
  return isNoValueOperator(draft.operator) || draft.value.trim().length > 0
}

/** True when every condition would serialize (nothing gets silently dropped). */
export function areFirewallConditionsComplete(
  drafts: FirewallConditionDraft[],
): boolean {
  return drafts.every(isConditionDraftComplete)
}

function buildQueryString(draft: FirewallConditionDraft): string | null {
  if (isNoValueOperator(draft.operator)) {
    return draft.operator === 'isNotNull'
      ? Query.isNotNull(draft.attribute)
      : Query.isNull(draft.attribute)
  }

  const raw = draft.value.trim()
  if (!raw) return null
  const value = draft.attribute === 'country' ? raw.toUpperCase() : raw

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
      attribute === 'country' ? rawValue.toUpperCase() : rawValue

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
