import { queryOptions } from '@tanstack/react-query'
import { Query, ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  completeOrganizationPaymentAuthentication,
  getPaymentAuthentication,
} from '@/lib/billing/addons'
import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { LONG_STALE_TIME } from './constants'
import {
  isPartnerClientBudgetCapReached,
  isPartnerClientPaymentIssue,
  type PartnerAwareOrganization,
} from '@/lib/partners'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export type ClientBillingSummary = {
  $id: string
  name: string
  billingPlan: string
  status: string
  billingBudget: number | null
  billingNextInvoiceDate: string | null
  lastInvoiceId: string | null
  lastInvoiceStatus: string | null
  lastInvoiceAmount: number | null
  lastInvoiceError: string | null
  chargeFailed: boolean
  budgetLimitReached: boolean
}

type InvoiceSnapshot = {
  $id?: string
  status?: string | null
  grossAmount?: number | null
  amount?: number | null
  lastError?: string | null
  error?: string | null
}

function consoleCall(
  method: string,
  path: string,
  payload: Record<string, unknown> = {},
) {
  const client = sdk.forConsole.client
  const base = String(client.config.endpoint ?? '').replace(/\/$/, '')
  const uri = new URL(`${base}${path.startsWith('/') ? path : `/${path}`}`)
  const body: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(payload)) {
    if (value !== undefined) body[key] = value
  }
  return client.call(
    method,
    uri,
    {
      accept: 'application/json',
      'content-type': 'application/json',
      'X-Appwrite-Project': client.config.project,
    },
    body,
  )
}

function errorType(error: unknown): string {
  return error &&
    typeof error === 'object' &&
    'type' in error &&
    typeof error.type === 'string'
    ? error.type
    : ''
}

export async function fetchPartnerClients(partnerId: string) {
  const response = await sdk.forConsole.organizations.list({
    queries: [Query.equal('managerId', [partnerId]), Query.limit(100)],
  })

  return {
    teams: (response.teams ?? []) as PartnerAwareOrganization[],
    total: response.total ?? 0,
  }
}

export async function fetchPartnerClientsBilling(partnerId: string) {
  const { teams } = await fetchPartnerClients(partnerId)
  const clients = await Promise.all(
    teams.map(async (client) => {
      let invoice: InvoiceSnapshot | undefined
      try {
        const response = await sdk.forConsole.organizations.listInvoices({
          organizationId: client.$id,
          queries: [Query.orderDesc('$createdAt'), Query.limit(1)],
        })
        invoice = response.invoices?.[0] as InvoiceSnapshot | undefined
      } catch {
        invoice = undefined
      }

      const invoiceStatus = invoice?.status ?? null
      return {
        $id: client.$id,
        name: client.name ?? client.$id,
        billingPlan: client.billingPlan ?? client.billingPlanId ?? '',
        status: client.status ?? '',
        billingBudget: client.billingBudget ?? null,
        billingNextInvoiceDate: client.billingNextInvoiceDate ?? null,
        lastInvoiceId: invoice?.$id ?? null,
        lastInvoiceStatus: invoiceStatus,
        lastInvoiceAmount: invoice?.grossAmount ?? invoice?.amount ?? null,
        lastInvoiceError: invoice?.lastError ?? invoice?.error ?? null,
        chargeFailed:
          isPartnerClientPaymentIssue(client) ||
          invoiceStatus?.toLowerCase() === 'failed',
        budgetLimitReached: isPartnerClientBudgetCapReached(client),
      } satisfies ClientBillingSummary
    }),
  )

  return {
    clients,
    total: clients.length,
  }
}

export async function createPartnerClient(params: {
  partnerId: string
  name: string
  billingPlan: string
  budget?: number
  managerType?: 'agency' | 'reseller'
  paymentMethodId?: string
}) {
  const organizationId = ID.unique()
  const payload = {
    organizationId,
    name: params.name.trim(),
    billingPlan: params.billingPlan || BillingPlanTier.Tier1,
    managerType: params.managerType ?? 'agency',
    managerId: params.partnerId,
    paymentMethodId: params.paymentMethodId,
    budget: params.budget,
  }

  let result: unknown
  try {
    result = await consoleCall('post', '/organizations', payload)
  } catch (error) {
    const auth = getPaymentAuthentication(error, organizationId)
    if (!auth) throw error
    return (await completeOrganizationPaymentAuthentication({
      auth: { ...auth, organizationId },
      paymentMethodId: params.paymentMethodId,
      organizationIdForPaymentMethod: params.partnerId,
    })) as PartnerAwareOrganization
  }

  const auth = getPaymentAuthentication(result, organizationId)
  if (auth) {
    return (await completeOrganizationPaymentAuthentication({
      auth: { ...auth, organizationId },
      paymentMethodId: params.paymentMethodId,
      organizationIdForPaymentMethod: params.partnerId,
    })) as PartnerAwareOrganization
  }

  return result as PartnerAwareOrganization
}

export async function deletePartnerManager(organizationId: string) {
  return (await consoleCall(
    'delete',
    `/organizations/${encodeURIComponent(organizationId)}/manager`,
  )) as PartnerAwareOrganization
}

export function partnerClientCreateErrorMessage(error: unknown): string {
  const type = errorType(error)
  const message =
    error instanceof Error ? error.message.toLowerCase() : ''

  if (
    type.includes('payment_method') ||
    message.includes('payment method')
  ) {
    return 'Add a payment method to this partner organization before onboarding a client.'
  }
  if (
    message.includes('requires authentication') ||
    message.includes('authentication was cancelled')
  ) {
    return 'Complete card authentication to finish creating this client.'
  }
  if (
    type.includes('billing_address') ||
    message.includes('billing address')
  ) {
    return 'Add a billing address to this partner organization before onboarding a client.'
  }
  if (type === 'partner_nested_manager_not_allowed') {
    return 'Client organizations cannot manage other organizations.'
  }
  if (type === 'billing_plan_not_allowed') {
    return 'Client organizations must use a paid plan.'
  }
  if (type === 'partner_project_transfer_not_allowed') {
    return 'Playground projects can only be handed off to a client of this partner.'
  }
  if (type === 'team_not_found' || type === 'user_unauthorized') {
    return 'You need to be an owner of this partner organization to onboard a client.'
  }

  return getErrorMessage(error, 'Could not create client')
}

export function partnerManagerDeleteErrorMessage(error: unknown): string {
  const type = errorType(error)
  if (type === 'partner_manager_not_found') {
    return 'This organization is not managed by a partner.'
  }
  if (type === 'user_unauthorized' || type === 'team_not_found') {
    return "You don't have permission to disconnect this partner."
  }
  return getErrorMessage(error, 'Could not disconnect partner')
}

export function partnerClientsQueryOptions(partnerId: string | null | undefined) {
  return queryOptions({
    queryKey: ['partners', partnerId, 'clients'],
    queryFn: () => fetchPartnerClients(partnerId!),
    enabled: !!partnerId,
    staleTime: LONG_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: partnerId ? 5 * 60 * 1000 : 0,
  })
}

export function partnerClientsBillingQueryOptions(
  partnerId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['partners', partnerId, 'clients', 'billing'],
    queryFn: () => fetchPartnerClientsBilling(partnerId!),
    enabled: !!partnerId,
    staleTime: 30 * 1000,
  })
}
