import { WafRuleAction } from '@appwrite.io/console'
import type { CreateFirewallRuleInput } from '@/lib/react-query/hooks/waf'
import {
  FIREWALL_RATE_LIMIT_KEY_DEFAULT,
  FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT,
} from '@/lib/firewall/actions'
import {
  serializeFirewallConditions,
  type FirewallConditionAttribute,
  type FirewallConditionDraft,
  type FirewallConditionOperator,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'

/** OTP-related Account API paths (Appwrite REST 2.2). */
export type OtpFirewallFlow =
  | 'phoneSend'
  | 'emailSend'
  | 'phoneVerify'
  | 'emailVerify'

const OTP_FLOW_TARGETS: Record<
  OtpFirewallFlow,
  { path: string; method: string }
> = {
  phoneSend: { path: '/v1/account/tokens/phone', method: 'POST' },
  emailSend: { path: '/v1/account/tokens/email', method: 'POST' },
  phoneVerify: { path: '/v1/account/sessions/phone', method: 'PUT' },
  emailVerify: { path: '/v1/account/sessions/token', method: 'POST' },
}

export const OTP_FIREWALL_FLOWS: OtpFirewallFlow[] = [
  'phoneSend',
  'emailSend',
  'phoneVerify',
  'emailVerify',
]

export function otpFlowUsesPhone(flow: OtpFirewallFlow): boolean {
  return flow === 'phoneSend' || flow === 'phoneVerify'
}

export function otpFlowIsSend(flow: OtpFirewallFlow): boolean {
  return flow === 'phoneSend' || flow === 'emailSend'
}

export type OtpFlowSelection = {
  phone: boolean
  email: boolean
  send: boolean
  verify: boolean
}

/** Resolve API OTP flows from channel (phone/email) and step (send/verify) toggles. */
export function resolveOtpFlows(selection: OtpFlowSelection): OtpFirewallFlow[] {
  return OTP_FIREWALL_FLOWS.filter((flow) => {
    if (otpFlowUsesPhone(flow) ? !selection.phone : !selection.email) {
      return false
    }
    if (otpFlowIsSend(flow) ? !selection.send : !selection.verify) {
      return false
    }
    return true
  })
}

function conditionDraftId(): string {
  return `cond_${Math.random().toString(36).slice(2, 10)}`
}

export function otpFlowConditions(flow: OtpFirewallFlow): FirewallConditionDraft[] {
  const target = OTP_FLOW_TARGETS[flow]
  return [
    {
      id: conditionDraftId(),
      attribute: 'path',
      operator: 'startsWith',
      value: target.path,
    },
    {
      id: conditionDraftId(),
      attribute: 'method',
      operator: 'equal',
      value: target.method,
    },
  ]
}

function pathPrefixConditions(
  pathPrefix: string,
  method?: string,
): FirewallConditionDraft[] {
  const conditions: FirewallConditionDraft[] = [
    {
      id: conditionDraftId(),
      attribute: 'path',
      operator: 'startsWith',
      value: pathPrefix,
    },
  ]
  if (method) {
    conditions.push({
      id: conditionDraftId(),
      attribute: 'method',
      operator: 'equal',
      value: method,
    })
  }
  return conditions
}

function attributeCondition(input: {
  attribute: FirewallConditionAttribute
  operator: FirewallConditionOperator
  value: string
}): FirewallConditionDraft {
  return {
    id: conditionDraftId(),
    attribute: input.attribute,
    operator: input.operator,
    value: input.value,
  }
}

export type FirewallRulePresetId =
  | 'rate-limit-otp'
  | 'country-block-otp'
  | 'country-allow-otp'
  | 'country-block-api'
  | 'country-allow-api'
  | 'rate-limit-api'
  | 'rate-limit-database-api'
  | 'deny-hosting-networks'
  | 'deny-datacenter-networks'

export type FirewallRulePresetUseCaseId =
  | 'otpAbuseProtection'
  | 'regionalCompliance'
  | 'scrapingPrevention'

export const FIREWALL_RULE_PRESET_USE_CASES: {
  id: FirewallRulePresetUseCaseId
  label: string
}[] = [
  {
    id: 'otpAbuseProtection',
    label: 'OTP abuse protection',
  },
  {
    id: 'regionalCompliance',
    label: 'Regional compliance',
  },
  {
    id: 'scrapingPrevention',
    label: 'Scraping prevention',
  },
]

type RateLimitOtpPresetDef = {
  id: 'rate-limit-otp'
  target: 'otp'
  flows: OtpFirewallFlow[]
}

type RateLimitPathPresetDef = {
  id: 'rate-limit-api' | 'rate-limit-database-api'
  target: 'path'
  pathPrefix: string
  method?: string
}

type RateLimitPresetDef = {
  kind: 'rateLimit'
  useCase: FirewallRulePresetUseCaseId
  label: string
  description: string
  defaultLimit: number
  defaultInterval: number
} & (RateLimitOtpPresetDef | RateLimitPathPresetDef)

type CountryPresetDef = {
  kind: 'country'
  useCase: FirewallRulePresetUseCaseId
  label: string
  description: string
  mode: 'block' | 'allow'
  scope: 'otp' | 'api'
}

export type PremiumNetworkDenyVariant = 'hosting' | 'datacenter'

type PremiumDenyPresetDef = {
  kind: 'premiumDeny'
  useCase: 'scrapingPrevention'
  id: 'deny-hosting-networks' | 'deny-datacenter-networks'
  label: string
  description: string
  requiresPremiumGeo: true
  variant: PremiumNetworkDenyVariant
}

export type FirewallRulePresetDefinition =
  | RateLimitPresetDef
  | CountryPresetDef
  | PremiumDenyPresetDef

export type FirewallRulePresetGroup = {
  id: FirewallRulePresetUseCaseId
  label: string
  presets: FirewallRulePresetDefinition[]
}

const API_FIREWALL_PRESETS: FirewallRulePresetDefinition[] = [
  {
    kind: 'rateLimit',
    useCase: 'otpAbuseProtection',
    id: 'rate-limit-otp',
    target: 'otp',
    label: 'Rate limit OTP',
    description:
      'Cap phone and email OTP send and verification per IP address.',
    flows: [...OTP_FIREWALL_FLOWS],
    defaultLimit: 10,
    defaultInterval: 3600,
  },
  {
    kind: 'country',
    useCase: 'otpAbuseProtection',
    id: 'country-block-otp',
    label: 'Block OTP from countries',
    description: 'Deny OTP traffic from selected countries.',
    mode: 'block',
    scope: 'otp',
  },
  {
    kind: 'country',
    useCase: 'otpAbuseProtection',
    id: 'country-allow-otp',
    label: 'Allow OTP only from countries',
    description: 'Deny OTP traffic outside selected countries.',
    mode: 'allow',
    scope: 'otp',
  },
  {
    kind: 'country',
    useCase: 'regionalCompliance',
    id: 'country-block-api',
    label: 'Block API from countries',
    description: 'Deny all project API traffic from selected countries.',
    mode: 'block',
    scope: 'api',
  },
  {
    kind: 'country',
    useCase: 'regionalCompliance',
    id: 'country-allow-api',
    label: 'Allow API only from countries',
    description: 'Deny project API traffic outside selected countries.',
    mode: 'allow',
    scope: 'api',
  },
  {
    kind: 'rateLimit',
    useCase: 'scrapingPrevention',
    id: 'rate-limit-api',
    target: 'path',
    pathPrefix: '/v1/',
    label: 'Rate limit project API',
    description: 'Cap total REST API requests per IP to slow bulk scraping.',
    defaultLimit: 600,
    defaultInterval: 60,
  },
  {
    kind: 'rateLimit',
    useCase: 'scrapingPrevention',
    id: 'rate-limit-database-api',
    target: 'path',
    pathPrefix: '/v1/tablesdb',
    label: 'Rate limit database API',
    description:
      'Cap TablesDB list and read traffic per IP to protect against data scraping.',
    defaultLimit: 120,
    defaultInterval: 60,
  },
  {
    kind: 'premiumDeny',
    useCase: 'scrapingPrevention',
    id: 'deny-hosting-networks',
    label: 'Block hosting provider traffic',
    description: 'Deny API requests from hosting provider networks.',
    requiresPremiumGeo: true,
    variant: 'hosting',
  },
  {
    kind: 'premiumDeny',
    useCase: 'scrapingPrevention',
    id: 'deny-datacenter-networks',
    label: 'Block datacenter connection traffic',
    description: 'Deny API requests from datacenter connection types.',
    requiresPremiumGeo: true,
    variant: 'datacenter',
  },
]

export function presetRequiresPremiumGeo(
  preset: FirewallRulePresetDefinition,
): preset is PremiumDenyPresetDef {
  return preset.kind === 'premiumDeny'
}

export function getFirewallRulePresets(
  resourceType: FirewallResourceType,
): FirewallRulePresetDefinition[] {
  if (resourceType === 'api') return API_FIREWALL_PRESETS
  return []
}

export function getGroupedFirewallRulePresets(
  resourceType: FirewallResourceType,
): FirewallRulePresetGroup[] {
  const presets = getFirewallRulePresets(resourceType)
  return FIREWALL_RULE_PRESET_USE_CASES.map((useCase) => ({
    id: useCase.id,
    label: useCase.label,
    presets: presets.filter((preset) => preset.useCase === useCase.id),
  })).filter((group) => group.presets.length > 0)
}

export function getFirewallRulePresetById(
  resourceType: FirewallResourceType,
  presetId: FirewallRulePresetId,
): FirewallRulePresetDefinition | undefined {
  return getFirewallRulePresets(resourceType).find((preset) => {
    if (preset.kind === 'country' || preset.kind === 'premiumDeny') {
      return preset.id === presetId
    }
    return preset.id === presetId
  })
}

export function buildOtpRateLimitRule(input: {
  flow: OtpFirewallFlow
  limit: number
  interval: number
  name: string
  description?: string
}): CreateFirewallRuleInput {
  return {
    action: WafRuleAction.RateLimit,
    resourceType: 'api',
    name: input.name,
    description: input.description,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions(otpFlowConditions(input.flow)),
    limit: input.limit,
    interval: input.interval,
    key: FIREWALL_RATE_LIMIT_KEY_DEFAULT,
    strategy: FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT,
  }
}

export function buildApiPathRateLimitRule(input: {
  pathPrefix: string
  method?: string
  limit: number
  interval: number
  name: string
  description?: string
}): CreateFirewallRuleInput {
  return {
    action: WafRuleAction.RateLimit,
    resourceType: 'api',
    name: input.name,
    description: input.description,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions(
      pathPrefixConditions(input.pathPrefix, input.method),
    ),
    limit: input.limit,
    interval: input.interval,
    key: FIREWALL_RATE_LIMIT_KEY_DEFAULT,
    strategy: FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT,
  }
}

function countryCondition(
  operator: 'equal' | 'notEqual',
  countryCode: string,
): FirewallConditionDraft {
  return {
    id: conditionDraftId(),
    attribute: 'country',
    operator,
    value: countryCode.toUpperCase(),
  }
}

function buildOtpCountryDenyRule(input: {
  flow: OtpFirewallFlow
  countryCode: string
  name: string
}): CreateFirewallRuleInput {
  return {
    action: WafRuleAction.Deny,
    resourceType: 'api',
    name: input.name,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions([
      ...otpFlowConditions(input.flow),
      countryCondition('equal', input.countryCode),
    ]),
  }
}

function buildOtpCountryAllowRule(input: {
  flow: OtpFirewallFlow
  allowedCountryCodes: string[]
  name: string
}): CreateFirewallRuleInput {
  const allowed = input.allowedCountryCodes.map((code) => code.toUpperCase())
  const countryConditions: FirewallConditionDraft[] = [
    ...allowed.map((code) => countryCondition('notEqual', code)),
    countryCondition('notEqual', 'unresolved'),
  ]
  return {
    action: WafRuleAction.Deny,
    resourceType: 'api',
    name: input.name,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions([
      ...otpFlowConditions(input.flow),
      ...countryConditions,
    ]),
  }
}

export function buildCountryOtpFirewallRules(input: {
  mode: 'block' | 'allow'
  flows: OtpFirewallFlow[]
  countryCodes: string[]
  ruleNameForFlow: (flow: OtpFirewallFlow, countryCode?: string) => string
}): CreateFirewallRuleInput[] {
  const flows = input.flows.length > 0 ? input.flows : []
  const countries = input.countryCodes.map((c) => c.toUpperCase())
  if (flows.length === 0 || countries.length === 0) return []

  if (input.mode === 'block') {
    const rules: CreateFirewallRuleInput[] = []
    for (const flow of flows) {
      for (const countryCode of countries) {
        rules.push(
          buildOtpCountryDenyRule({
            flow,
            countryCode,
            name: input.ruleNameForFlow(flow, countryCode),
          }),
        )
      }
    }
    return rules
  }

  return flows.map((flow) =>
    buildOtpCountryAllowRule({
      flow,
      allowedCountryCodes: countries,
      name: input.ruleNameForFlow(flow),
    }),
  )
}

function buildApiCountryDenyRule(input: {
  countryCode: string
  name: string
}): CreateFirewallRuleInput {
  return {
    action: WafRuleAction.Deny,
    resourceType: 'api',
    name: input.name,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions([
      countryCondition('equal', input.countryCode),
    ]),
  }
}

function buildApiCountryAllowRule(input: {
  allowedCountryCodes: string[]
  name: string
}): CreateFirewallRuleInput {
  const allowed = input.allowedCountryCodes.map((code) => code.toUpperCase())
  const countryConditions: FirewallConditionDraft[] = [
    ...allowed.map((code) => countryCondition('notEqual', code)),
    countryCondition('notEqual', 'unresolved'),
  ]
  return {
    action: WafRuleAction.Deny,
    resourceType: 'api',
    name: input.name,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions(countryConditions),
  }
}

export function buildApiCountryFirewallRules(input: {
  mode: 'block' | 'allow'
  countryCodes: string[]
  ruleNameForCountry: (countryCode?: string) => string
}): CreateFirewallRuleInput[] {
  const countries = input.countryCodes.map((c) => c.toUpperCase())
  if (countries.length === 0) return []

  if (input.mode === 'block') {
    return countries.map((countryCode) =>
      buildApiCountryDenyRule({
        countryCode,
        name: input.ruleNameForCountry(countryCode),
      }),
    )
  }

  return [
    buildApiCountryAllowRule({
      allowedCountryCodes: countries,
      name: input.ruleNameForCountry(),
    }),
  ]
}

const PREMIUM_NETWORK_DENY_CONDITIONS: Record<
  PremiumNetworkDenyVariant,
  FirewallConditionDraft[]
> = {
  hosting: [
    attributeCondition({
      attribute: 'connectionUsageType',
      operator: 'equal',
      value: 'hosting',
    }),
  ],
  datacenter: [
    attributeCondition({
      attribute: 'connectionType',
      operator: 'equal',
      value: 'hosting',
    }),
  ],
}

export function buildPremiumNetworkDenyRule(input: {
  variant: PremiumNetworkDenyVariant
  name: string
  description?: string
}): CreateFirewallRuleInput {
  return {
    action: WafRuleAction.Deny,
    resourceType: 'api',
    name: input.name,
    description: input.description,
    priority: 100,
    enabled: true,
    conditions: serializeFirewallConditions(
      PREMIUM_NETWORK_DENY_CONDITIONS[input.variant],
    ),
  }
}

/** Conditions for usage impact preview when configuring a rate-limit preset. */
export function buildPresetRateLimitPreviewConditions(
  preset: Extract<FirewallRulePresetDefinition, { kind: 'rateLimit' }>,
  flows: OtpFirewallFlow[],
): FirewallConditionDraft[] {
  if (preset.target === 'path') {
    return pathPrefixConditions(preset.pathPrefix, preset.method)
  }
  if (flows.length === 1) {
    return otpFlowConditions(flows[0]!)
  }
  if (flows.length > 1) {
    return pathPrefixConditions('/v1/account')
  }
  return []
}

/** Conditions for usage impact preview when configuring a country preset. */
export function buildPresetCountryPreviewConditions(input: {
  scope: 'otp' | 'api'
  mode: 'block' | 'allow'
  flows: OtpFirewallFlow[]
  countryCodes: string[]
}): FirewallConditionDraft[] {
  const countries = input.countryCodes
    .map((code) => code.toUpperCase())
    .filter(Boolean)
  if (countries.length === 0) return []

  const flowBase =
    input.scope === 'otp' && input.flows.length > 0
      ? input.flows.length === 1
        ? otpFlowConditions(input.flows[0]!)
        : pathPrefixConditions('/v1/account')
      : []

  if (input.mode === 'block') {
    return [...flowBase, countryCondition('equal', countries[0]!)]
  }

  return [
    ...flowBase,
    ...countries.map((code) => countryCondition('notEqual', code)),
    countryCondition('notEqual', 'unresolved'),
  ]
}

/** One condition set per deny rule when blocking multiple countries (and OTP flows). */
export function buildPresetCountryBlockPreviewConditionSets(input: {
  scope: 'otp' | 'api'
  flows: OtpFirewallFlow[]
  countryCodes: string[]
}): FirewallConditionDraft[][] {
  const countries = input.countryCodes
    .map((code) => code.toUpperCase())
    .filter(Boolean)
  if (countries.length === 0) return []

  if (input.scope === 'api') {
    return countries.map((countryCode) => [
      countryCondition('equal', countryCode),
    ])
  }

  const flows = input.flows.length > 0 ? input.flows : []
  if (flows.length === 0) return []

  const sets: FirewallConditionDraft[][] = []
  for (const flow of flows) {
    for (const countryCode of countries) {
      sets.push([
        ...otpFlowConditions(flow),
        countryCondition('equal', countryCode),
      ])
    }
  }
  return sets
}

/** One condition set per rate-limit rule when multiple OTP flows are selected. */
export function buildPresetRateLimitOtpPreviewConditionSets(
  flows: OtpFirewallFlow[],
): FirewallConditionDraft[][] {
  return flows.map((flow) => otpFlowConditions(flow))
}

export function buildPresetPremiumDenyPreviewConditions(
  variant: PremiumNetworkDenyVariant,
): FirewallConditionDraft[] {
  return PREMIUM_NETWORK_DENY_CONDITIONS[variant].map((condition) => ({
    ...condition,
    id: conditionDraftId(),
  }))
}
