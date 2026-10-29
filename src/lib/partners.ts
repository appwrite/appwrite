import { BillingPlanTier } from '@/lib/constants/billing-plan'

export const BILLING_PLAN_PARTNER = 'partner-1'

export type ManagerType = 'self' | 'agency' | 'reseller'

export type PartnerAwareOrganization = {
  $id: string
  name?: string
  $createdAt?: string
  billingPlan?: string | null
  billingPlanId?: string | null
  billingBudget?: number | null
  billingNextInvoiceDate?: string | null
  billingLimits?: {
    budgetLimit?: number | string | null
  } | null
  managerType?: string | null
  managerId?: string | null
  programSlackChannelUrl?: string | null
  programSlackChannelName?: string | null
  paymentMethodId?: string | null
  status?: string | null
  remarks?: string | null
}

export function isPartnerClientPaymentIssue(
  org: PartnerAwareOrganization | null | undefined,
): boolean {
  return (org?.status ?? '').toLowerCase() === 'readonly'
}

export function isPartnerClientBudgetCapReached(
  org: PartnerAwareOrganization | null | undefined,
): boolean {
  const raw = org?.billingLimits?.budgetLimit
  if (raw == null || raw === '') return false
  const budgetLimit = typeof raw === 'number' ? raw : Number(raw)
  return Number.isFinite(budgetLimit) && budgetLimit >= 100
}

export function isPartnerPlanId(
  planId: string | null | undefined,
): boolean {
  return planId === BILLING_PLAN_PARTNER
}

export function isPartnerOrganization(
  org: PartnerAwareOrganization | null | undefined,
): boolean {
  if (!org) return false
  return isPartnerPlanId(org.billingPlanId || org.billingPlan)
}

export function isSelfManagedOrganization(
  org: PartnerAwareOrganization | null | undefined,
): boolean {
  if (!org) return true
  const type = org.managerType || 'self'
  return type === 'self'
}

/** Regular console org: not a partner-plan org, and not a managed client org. */
export function isDeveloperConsoleOrganization(
  org: PartnerAwareOrganization | null | undefined,
): boolean {
  if (!org) return false
  return !isPartnerOrganization(org) && isSelfManagedOrganization(org)
}

export function partitionConsoleOrganizations(
  orgs: PartnerAwareOrganization[],
) {
  const partnerPlatform: PartnerAwareOrganization[] = []
  const developersPlatform: PartnerAwareOrganization[] = []
  for (const org of orgs) {
    if (isPartnerOrganization(org)) {
      partnerPlatform.push(org)
    } else if (isSelfManagedOrganization(org)) {
      developersPlatform.push(org)
    }
  }
  return { partnerPlatform, developersPlatform }
}

/** Console partner org (`/partners/$id`), not the marketing `/partners` page. */
export function isPartnerConsolePath(pathname: string): boolean {
  const segments = pathname.split('/').filter(Boolean)
  return segments[0] === 'partners' && Boolean(segments[1])
}

export function organizationHomeLink(orgId: string, isPartner: boolean) {
  if (isPartner) {
    return {
      to: '/partners/$partnerId/clients' as const,
      params: { partnerId: orgId },
    }
  }
  return {
    to: '/organizations/$orgId' as const,
    params: { orgId },
  }
}

export const PARTNER_SLACK_TEAM = [
  { name: 'Dylan Graham', src: '/images/avatars/dylan.avif' },
  { name: 'Eldad Fux', src: '/images/avatars/eldad.avif' },
  { name: 'Matej Bačo', src: '/images/avatars/matej.avif' },
  { name: 'Torsten Dittmann', src: '/images/avatars/torsten.avif' },
  { name: 'Aditya Oberai', src: '/images/avatars/aditya.avif' },
  { name: 'Luke B. Silver', src: '/images/avatars/luke.avif' },
  { name: 'Emma Carpagnano', src: '/images/avatars/emma.avif' },
] as const

export const PARTNER_CLIENT_PLANS = [
  { id: BillingPlanTier.Tier1, label: 'Pro' },
  { id: BillingPlanTier.Tier2, label: 'Scale' },
] as const

export const PARTNER_CLIENT_SECTIONS = [
  'projects',
  'members',
  'compliance',
  'addons',
  'domains',
  'apps',
  'settings',
  'billing',
] as const

export type PartnerClientSection = (typeof PARTNER_CLIENT_SECTIONS)[number]

/** Resource panes in partner Workspace (`view=development`). Reuses `section`. */
export const PARTNER_WORKSPACE_RESOURCE_SECTIONS = [
  'projects',
  'domains',
  'apps',
] as const satisfies readonly PartnerClientSection[]

export type PartnerWorkspaceResourceSection =
  (typeof PARTNER_WORKSPACE_RESOURCE_SECTIONS)[number]

export const PARTNER_CLIENT_SECTION_GROUPS = [
  {
    id: 'workspace',
    label: 'Resources',
    sections: PARTNER_WORKSPACE_RESOURCE_SECTIONS,
  },
  {
    id: 'plan',
    label: 'Plan',
    sections: ['billing', 'addons', 'compliance'],
  },
  {
    id: 'organization',
    label: 'Organization',
    sections: ['members', 'settings'],
  },
] as const satisfies ReadonlyArray<{
  id: string
  label: string
  sections: readonly PartnerClientSection[]
}>

export const PARTNER_WORKSPACE_VIEWS = ['clients', 'development'] as const

export type PartnerWorkspaceView = (typeof PARTNER_WORKSPACE_VIEWS)[number]

export type PartnerWorkspaceSearch = {
  client?: string
  section?: PartnerClientSection
  view?: PartnerWorkspaceView
}

export function isPartnerWorkspaceView(
  value: unknown,
): value is PartnerWorkspaceView {
  return (
    typeof value === 'string' &&
    (PARTNER_WORKSPACE_VIEWS as readonly string[]).includes(value)
  )
}

export function isPartnerClientSection(
  value: unknown,
): value is PartnerClientSection {
  return (
    typeof value === 'string' &&
    (PARTNER_CLIENT_SECTIONS as readonly string[]).includes(value)
  )
}

export function isPartnerWorkspaceResourceSection(
  value: unknown,
): value is PartnerWorkspaceResourceSection {
  return (
    typeof value === 'string' &&
    (PARTNER_WORKSPACE_RESOURCE_SECTIONS as readonly string[]).includes(value)
  )
}

/** Pinned clients first (pin order), then the rest in the given list order. */
export function sortPartnerClientsByPins<T extends { $id: string }>(
  clients: T[],
  pinnedIds: string[],
): T[] {
  if (clients.length === 0 || pinnedIds.length === 0) return clients
  const byId = new Map(clients.map((client) => [client.$id, client]))
  const pinned: T[] = []
  const pinnedSet = new Set<string>()
  for (const id of pinnedIds) {
    const client = byId.get(id)
    if (!client || pinnedSet.has(id)) continue
    pinned.push(client)
    pinnedSet.add(id)
  }
  if (pinned.length === 0) return clients
  return [...pinned, ...clients.filter((client) => !pinnedSet.has(client.$id))]
}

export function getDefaultPartnerClientId<T extends { $id: string }>(
  clients: T[],
  pinnedIds: string[],
): string | undefined {
  return sortPartnerClientsByPins(clients, pinnedIds)[0]?.$id
}

export type PartnerWorkspacePageSearch = {
  section?: PartnerClientSection
  /** Legacy single-page workspace URLs. Redirected in the index beforeLoad. */
  client?: string
  view?: PartnerWorkspaceView
}

export type PartnerClientPageSearch = {
  section?: PartnerClientSection
}

export function partnerWorkspaceLink(
  partnerId: string,
  section?: PartnerWorkspaceResourceSection,
) {
  return {
    to: '/partners/$partnerId' as const,
    params: { partnerId },
    search: section && section !== 'projects' ? { section } : {},
  }
}

export function partnerClientsLink(partnerId: string) {
  return {
    to: '/partners/$partnerId/clients' as const,
    params: { partnerId },
  }
}

export function partnerClientLink(
  partnerId: string,
  clientId: string,
  section?: PartnerClientSection,
) {
  return {
    to: '/partners/$partnerId/clients/$clientId' as const,
    params: { partnerId, clientId },
    search: section && section !== 'projects' ? { section } : {},
  }
}

export function parsePartnerWorkspacePageSearch(
  search: Record<string, unknown>,
): PartnerWorkspacePageSearch {
  const next: PartnerWorkspacePageSearch = {}
  if (typeof search.client === 'string' && search.client.trim()) {
    next.client = search.client.trim()
  }
  if (isPartnerWorkspaceView(search.view) && search.view !== 'clients') {
    next.view = search.view
  }
  if (isPartnerClientSection(search.section)) {
    next.section = search.section
  }
  return next
}

export function parsePartnerClientPageSearch(
  search: Record<string, unknown>,
): PartnerClientPageSearch {
  if (isPartnerClientSection(search.section)) {
    return { section: search.section }
  }
  return {}
}

/** Legacy `?client=&view=` URLs from the single-page workspace. */
export function parsePartnerWorkspaceSearch(
  search: Record<string, unknown>,
): PartnerWorkspaceSearch {
  const next: PartnerWorkspaceSearch = {}
  if (typeof search.client === 'string' && search.client.trim()) {
    next.client = search.client.trim()
  }
  if (isPartnerWorkspaceView(search.view) && search.view !== 'clients') {
    next.view = search.view
  }
  if (next.view === 'development') {
    if (isPartnerWorkspaceResourceSection(search.section)) {
      next.section = search.section
    }
  } else if (isPartnerClientSection(search.section)) {
    next.section = search.section
  }
  return next
}

export const PARTNER_CLIENT_SECTION_LABELS: Record<PartnerClientSection, string> =
  {
    projects: 'Projects',
    members: 'Members',
    compliance: 'Compliance',
    addons: 'Addons',
    domains: 'Domains',
    apps: 'Apps',
    settings: 'Settings',
    billing: 'Billing',
  }
