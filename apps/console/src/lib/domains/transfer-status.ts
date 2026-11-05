import { DomainTransferStatusEnum } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import type { DomainStatusBadgeVariant } from '@/lib/utils/status-badge'

/** Transfer statuses that indicate an in-progress transfer-in. */
export const PENDING_DOMAIN_TRANSFER_STATUSES: readonly DomainTransferStatusEnum[] =
  [
    DomainTransferStatusEnum.PendingOwner,
    DomainTransferStatusEnum.PendingAdmin,
    DomainTransferStatusEnum.PendingRegistry,
  ]

export const DOMAIN_TRANSFER_IN_PROGRESS_DESCRIPTION =
  'Domain transfers usually take 5-7 days. ICANN allows the old registrar up to 5 days to release the domain, with .com and .net sometimes taking 1-2 extra days to finalize.'

function normalizeDomainTransferStatus(
  status: DomainTransferStatusEnum | string | undefined | null,
): string | null {
  if (status == null || status === '') return null
  return String(status).trim().toLowerCase()
}

export function isPendingDomainTransferStatus(
  status: DomainTransferStatusEnum | string | undefined | null,
): boolean {
  const normalized = normalizeDomainTransferStatus(status)
  if (!normalized) return false
  return (
    normalized.startsWith('pending_') ||
    PENDING_DOMAIN_TRANSFER_STATUSES.some((value) => value === normalized)
  )
}

/** True when the domain resource indicates an active transfer-in. */
export function isDomainTransferInProgress(
  domain: Pick<Models.Domain, 'transferStatus'> | null | undefined,
): boolean {
  return isPendingDomainTransferStatus(domain?.transferStatus)
}

/** Whether to fetch / poll the transfer status endpoint for this domain. */
export function shouldFetchDomainTransferStatus(
  domain: Pick<Models.Domain, 'transferStatus'> | null | undefined,
): boolean {
  return isDomainTransferInProgress(domain)
}

/** Whether transfer status should appear in the console UI. */
export function shouldShowDomainTransferStatus(
  status: DomainTransferStatusEnum | string | undefined | null,
): boolean {
  if (!status) return false
  if (
    status === DomainTransferStatusEnum.Transferrable ||
    status === DomainTransferStatusEnum.NotTransferrable ||
    status === DomainTransferStatusEnum.Completed
  ) {
    return false
  }
  return true
}

export function getDomainTransferStatusLabel(
  status: DomainTransferStatusEnum | string,
): string {
  switch (status) {
    case DomainTransferStatusEnum.PendingOwner:
      return 'Pending owner approval'
    case DomainTransferStatusEnum.PendingAdmin:
      return 'Pending admin approval'
    case DomainTransferStatusEnum.PendingRegistry:
      return 'Pending registry'
    case DomainTransferStatusEnum.Completed:
      return 'Transfer completed'
    case DomainTransferStatusEnum.Cancelled:
      return 'Transfer cancelled'
    case DomainTransferStatusEnum.Transferrable:
      return 'Transferrable'
    case DomainTransferStatusEnum.NotTransferrable:
      return 'Not transferrable'
    case DomainTransferStatusEnum.ServiceUnavailable:
      return 'Transfer unavailable'
    default:
      return String(status).replaceAll('_', ' ')
  }
}

export function getDomainTransferStatusBadgeConfig(
  status: DomainTransferStatusEnum | string,
): {
  variant: DomainStatusBadgeVariant | 'success' | 'info' | 'pending'
  label: string
} {
  const label = getDomainTransferStatusLabel(status)

  switch (status) {
    case DomainTransferStatusEnum.Completed:
      return { variant: 'success', label }
    case DomainTransferStatusEnum.Cancelled:
    case DomainTransferStatusEnum.NotTransferrable:
    case DomainTransferStatusEnum.ServiceUnavailable:
      return { variant: 'error', label }
    case DomainTransferStatusEnum.Transferrable:
      return { variant: 'processing', label }
    case DomainTransferStatusEnum.PendingOwner:
    case DomainTransferStatusEnum.PendingAdmin:
    case DomainTransferStatusEnum.PendingRegistry:
      return { variant: 'processing', label }
    default:
      return { variant: 'processing', label }
  }
}
