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

/** Non-empty resource ID from a route search param, if present. */
export function parseFirewallResourceIdSearch(
  value: unknown,
): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

/**
 * Normalize firewall list scope from URL search.
 * Functions/sites require a resourceId; otherwise fall back to API.
 */
export function resolveFirewallListSearch(search: {
  resourceType?: unknown
  resourceId?: unknown
}): {
  resourceType: FirewallResourceType
  resourceId?: string
} {
  const resourceType =
    parseFirewallResourceTypeSearch(search.resourceType) ?? 'api'
  const resourceId = parseFirewallResourceIdSearch(search.resourceId)

  if (resourceType === 'api') {
    return { resourceType: 'api' }
  }

  if (!resourceId) {
    return { resourceType: 'api' }
  }

  return { resourceType, resourceId }
}

/** Condition attributes shown in the rule builder, grouped for the picker. */
export const FIREWALL_CONDITION_ATTRIBUTE_GROUPS = [
  {
    label: 'Request',
    attributes: [
      { value: 'host', label: 'Hostname' },
      { value: 'path', label: 'Path' },
      { value: 'method', label: 'Method' },
      { value: 'headers', label: 'Header' },
      { value: 'query', label: 'Query parameter' },
    ],
  },
  {
    label: 'Client',
    attributes: [
      { value: 'ip', label: 'IP address' },
      { value: 'os', label: 'Operating system' },
      { value: 'browser', label: 'Browser' },
      { value: 'userAgent', label: 'User agent' },
    ],
  },
  {
    label: 'Location',
    attributes: [
      { value: 'country', label: 'Country' },
      { value: 'continent', label: 'Continent' },
      { value: 'city', label: 'City' },
      { value: 'state', label: 'State' },
    ],
  },
] as const

/** Flat list of condition attributes (lookups, validation). */
export const FIREWALL_CONDITION_ATTRIBUTES =
  FIREWALL_CONDITION_ATTRIBUTE_GROUPS.flatMap((group) => group.attributes)

export type FirewallConditionAttribute =
  (typeof FIREWALL_CONDITION_ATTRIBUTES)[number]['value']

/** Attributes gated behind the premium Geo DB addon (enforced server-side). */
export const FIREWALL_PREMIUM_ATTRIBUTES = new Set<FirewallConditionAttribute>([
  'city',
  'state',
])

export function isPremiumAttribute(
  attribute: FirewallConditionAttribute,
): boolean {
  return FIREWALL_PREMIUM_ATTRIBUTES.has(attribute)
}

/**
 * Attributes that target a user-named key. The API attribute is the prefix +
 * the key, e.g. `headers.x-custom` / `query.token` (keys are matched
 * case-insensitively server-side, so they are lowercased here).
 */
const DYNAMIC_KEY_PREFIXES = {
  headers: 'headers.',
  query: 'query.',
} as const

export type FirewallDynamicKeyAttribute = keyof typeof DYNAMIC_KEY_PREFIXES

export function isDynamicKeyAttribute(
  attribute: FirewallConditionAttribute,
): attribute is FirewallDynamicKeyAttribute {
  return attribute in DYNAMIC_KEY_PREFIXES
}

/** Normalize a user-typed key: trim, lowercase, drop a pasted-in prefix. */
export function normalizeConditionKey(
  attribute: FirewallDynamicKeyAttribute,
  key: string,
): string {
  let normalized = key.trim().toLowerCase()
  const prefix = DYNAMIC_KEY_PREFIXES[attribute]
  if (normalized.startsWith(prefix)) {
    normalized = normalized.slice(prefix.length)
  }
  return normalized
}

/**
 * API attribute string for a draft: prefixed key for headers/query
 * (null while the key is still empty), the attribute itself otherwise.
 */
export function resolveConditionAttribute(draft: {
  attribute: FirewallConditionAttribute
  key?: string
}): string | null {
  if (!isDynamicKeyAttribute(draft.attribute)) return draft.attribute
  const key = normalizeConditionKey(draft.attribute, draft.key ?? '')
  return key ? `${DYNAMIC_KEY_PREFIXES[draft.attribute]}${key}` : null
}

/**
 * Operators mirror the usage `listEvents` filter operators so rule conditions
 * and affected-traffic estimations speak the same language.
 * `isNull` / `isNotNull` take no value.
 */
export const FIREWALL_CONDITION_OPERATORS = [
  { value: 'equal', label: 'Equals' },
  { value: 'notEqual', label: 'Not equal' },
  { value: 'contains', label: 'Contains' },
  { value: 'notContains', label: 'Does not contain' },
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

/** Operators that filter on the value being absent - no value input is shown. */
const NO_VALUE_OPERATORS = new Set<FirewallConditionOperator>([
  'isNull',
  'isNotNull',
])

/** Text-matching operators only apply to free-text attributes. */
const TEXT_MATCH_OPERATORS = new Set<FirewallConditionOperator>([
  'contains',
  'notContains',
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
 * - Free text (ip / host / path / headers / query / city / state / os /
 *   browser / userAgent): full set. For `ip`, CIDR blocks only match on
 *   equal / not equal (other operators fall back to string comparison).
 * - `method`: enum-backed, equality + presence only (no text matching).
 * - `country` / `continent`: picker-backed, basic set (equal / not equal /
 *   contains / does not contain). `notContains` has no usage equivalent, so it
 *   is skipped in affected-traffic estimation
 *   (see buildFirewallConditionUsageQueries).
 */
const OPERATORS_BY_ATTRIBUTE: Record<
  FirewallConditionAttribute,
  ReadonlyArray<FirewallConditionOperator>
> = {
  ip: FREE_TEXT_OPERATORS,
  host: FREE_TEXT_OPERATORS,
  path: FREE_TEXT_OPERATORS,
  headers: FREE_TEXT_OPERATORS,
  query: FREE_TEXT_OPERATORS,
  city: FREE_TEXT_OPERATORS,
  state: FREE_TEXT_OPERATORS,
  os: FREE_TEXT_OPERATORS,
  browser: FREE_TEXT_OPERATORS,
  userAgent: FREE_TEXT_OPERATORS,
  method: ['equal', 'notEqual', 'isNull', 'isNotNull'],
  country: ['equal', 'notEqual', 'contains', 'notContains'],
  continent: ['equal', 'notEqual', 'contains', 'notContains'],
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
  /** Header name / query parameter name for dynamic-key attributes. */
  key?: string
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
 * dynamic-key attributes (headers / query) always need a key, no-value
 * operators (is empty / is not empty) need nothing else, every other
 * operator needs a non-empty value. Incomplete drafts are silently dropped
 * by `serializeFirewallConditions`, so callers should block submit on them.
 */
export function isConditionDraftComplete(
  draft: FirewallConditionDraft,
): boolean {
  if (resolveConditionAttribute(draft) == null) return false
  return isNoValueOperator(draft.operator) || draft.value.trim().length > 0
}

/** True when every condition would serialize (nothing gets silently dropped). */
export function areFirewallConditionsComplete(
  drafts: FirewallConditionDraft[],
): boolean {
  return drafts.every(isConditionDraftComplete)
}

/** Attributes whose values are uppercase geo codes (US, EU, ...). */
const UPPERCASE_VALUE_ATTRIBUTES = new Set<FirewallConditionAttribute>([
  'country',
  'continent',
])

function buildQueryString(draft: FirewallConditionDraft): string | null {
  const attribute = resolveConditionAttribute(draft)
  if (!attribute) return null

  if (isNoValueOperator(draft.operator)) {
    return draft.operator === 'isNotNull'
      ? Query.isNotNull(attribute)
      : Query.isNull(attribute)
  }

  const raw = draft.value.trim()
  if (!raw) return null
  const value = UPPERCASE_VALUE_ATTRIBUTES.has(draft.attribute)
    ? raw.toUpperCase()
    : raw

  switch (draft.operator) {
    case 'equal':
      return Query.equal(attribute, value)
    case 'notEqual':
      return Query.notEqual(attribute, value)
    case 'contains':
      return Query.contains(attribute, value)
    case 'notContains':
      return Query.notContains(attribute, value)
    case 'startsWith':
      return Query.startsWith(attribute, value)
    case 'endsWith':
      return Query.endsWith(attribute, value)
    default:
      return Query.equal(attribute, value)
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
    // Prefixed attributes (headers.x-custom / query.token) split into a
    // dynamic-key attribute + key.
    const dynamic = splitDynamicAttribute(item.attribute)

    const attribute =
      dynamic?.attribute ??
      ((FIREWALL_CONDITION_ATTRIBUTES.some((a) => a.value === item.attribute)
        ? item.attribute
        : 'ip') as FirewallConditionAttribute)

    const operator = (
      FIREWALL_CONDITION_OPERATORS.some((o) => o.value === item.operator) &&
      isOperatorAllowedForAttribute(attribute, item.operator as FirewallConditionOperator)
        ? item.operator
        : 'equal'
    ) as FirewallConditionOperator

    const rawValue = item.values[0] ?? ''
    const value = UPPERCASE_VALUE_ATTRIBUTES.has(attribute)
      ? rawValue.toUpperCase()
      : rawValue

    return {
      id: `cond_${Math.random().toString(36).slice(2, 10)}`,
      attribute,
      operator,
      value,
      key: dynamic?.key,
    }
  })
}

/** Split an API attribute like `headers.x-custom` into attribute + key. */
function splitDynamicAttribute(
  attribute: string,
): { attribute: FirewallDynamicKeyAttribute; key: string } | null {
  for (const [dynamicAttribute, prefix] of Object.entries(
    DYNAMIC_KEY_PREFIXES,
  ) as Array<[FirewallDynamicKeyAttribute, string]>) {
    if (attribute.startsWith(prefix) && attribute.length > prefix.length) {
      return { attribute: dynamicAttribute, key: attribute.slice(prefix.length) }
    }
  }
  return null
}

export function formatConditionSummary(
  condition: ParsedFirewallCondition,
): string {
  const dynamic = splitDynamicAttribute(condition.attribute)
  const attr = dynamic
    ? `${
        FIREWALL_CONDITION_ATTRIBUTES.find(
          (a) => a.value === dynamic.attribute,
        )?.label ?? dynamic.attribute
      } "${dynamic.key}"`
    : (FIREWALL_CONDITION_ATTRIBUTES.find(
        (a) => a.value === condition.attribute,
      )?.label ?? condition.attribute)
  const op =
    FIREWALL_CONDITION_OPERATORS.find((o) => o.value === condition.operator)
      ?.label ?? condition.operator
  const value = condition.values.join(', ')
  return value ? `${attr} ${op} ${value}` : `${attr} ${op}`
}
