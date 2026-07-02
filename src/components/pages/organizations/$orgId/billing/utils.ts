/**
 * Billing Utility Functions
 *
 * Shared utilities for billing components including formatting,
 * status helpers, and calculations.
 */

import { formatLocalizedDateTime } from '@/lib/i18n/date-format'

/**
 * Format a number as currency
 * @param amount - The amount to format
 * @param currency - Currency code (default: USD)
 * @returns Formatted currency string
 */
export function formatCurrency(
  amount: number,
  currency: string = 'USD',
): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount)
}

/**
 * Format a date for display
 * @param date - Date string or Date object
 * @param options - Intl.DateTimeFormatOptions
 * @returns Formatted date string
 */
export function formatDate(
  date: string | Date,
  options: Intl.DateTimeFormatOptions = {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  },
): string {
  return formatLocalizedDateTime(new Date(date), options)
}

/**
 * Calculate total from an array of charges
 * @param charges - Array of charge objects with amount property
 * @returns Total amount
 */
export function calculateTotal(charges: { amount: number }[]): number {
  return charges.reduce((sum, charge) => sum + charge.amount, 0)
}

/**
 * Format invoice number for display
 * @param invoiceNumber - Raw invoice number
 * @returns Formatted invoice number
 */
export function formatInvoiceNumber(invoiceNumber: string): string {
  return invoiceNumber.toUpperCase()
}

/**
 * Get payment method icon name based on type
 * @param type - Payment method type
 * @returns Icon name for lucide-react
 */
export function getPaymentMethodIcon(type: 'card' | 'paypal' | 'bank'): string {
  switch (type) {
    case 'card':
      return 'CreditCard'
    case 'paypal':
      return 'Wallet'
    case 'bank':
      return 'Building2'
    default:
      return 'CreditCard'
  }
}

/**
 * Check if an invoice is overdue
 * @param dueDate - Invoice due date
 * @param status - Current invoice status
 * @returns Whether the invoice is overdue
 */
export function isInvoiceOverdue(dueDate: string, status: string): boolean {
  if (status === 'paid') return false
  return new Date(dueDate) < new Date()
}

/**
 * Get relative time string (e.g., "in 15 days", "3 days ago")
 * @param date - Target date
 * @returns Relative time string
 */
export function getRelativeTime(date: string | Date): string {
  const now = new Date()
  const target = new Date(date)
  const diffMs = target.getTime() - now.getTime()
  const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return 'today'
  if (diffDays === 1) return 'tomorrow'
  if (diffDays === -1) return 'yesterday'
  if (diffDays > 0) return `in ${diffDays} days`
  return `${Math.abs(diffDays)} days ago`
}

/**
 * Mask a credit card number showing only last 4 digits
 * @param last4 - Last 4 digits of card
 * @returns Masked card number
 */
export function maskCardNumber(last4: string): string {
  return `•••• •••• •••• ${last4}`
}

/**
 * Format card expiry date
 * @param month - Expiry month
 * @param year - Expiry year
 * @returns Formatted expiry string (MM/YY)
 */
export function formatCardExpiry(month: number, year: number): string {
  const monthStr = month.toString().padStart(2, '0')
  const yearStr = year.toString().slice(-2)
  return `${monthStr}/${yearStr}`
}

const CARD_BRAND_LABELS: Record<string, string> = {
  visa: 'Visa',
  mastercard: 'Mastercard',
  amex: 'American Express',
  american_express: 'American Express',
  discover: 'Discover',
  diners: 'Diners Club',
  diners_club: 'Diners Club',
  jcb: 'JCB',
  unionpay: 'UnionPay',
  maestro: 'Maestro',
  elo: 'Elo',
  hipercard: 'Hipercard',
  mir: 'MIR',
  rupay: 'RuPay',
  argencard: 'Argencard',
  cabal: 'Cabal',
  cencosud: 'Cencosud',
  naranja: 'Naranja',
  'targeta-shopping': 'Tarjeta Shopping',
}

function normalizePaymentCardBrand(brand: string | undefined): string {
  return brand?.trim().toLowerCase().replace(/[\s-]+/g, '_') || ''
}

/**
 * Human-readable card network from API `brand` (often Stripe-style slugs).
 */
export function formatPaymentCardBrand(brand: string | undefined): string {
  const b = normalizePaymentCardBrand(brand)
  if (CARD_BRAND_LABELS[b]) return CARD_BRAND_LABELS[b]
  if (!b) return 'Card'
  return b
    .split(/[\s_]+/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ')
}

export type PaymentMethodCardSummaryInput = {
  brand?: string
  last4?: string
  name?: string
  expiryMonth?: number
  expiryYear?: number
}

/**
 * Label for payment method selects: card type, last four, optional expiry.
 */
export function formatPaymentMethodSummary(
  method: PaymentMethodCardSummaryInput,
  options?: { includeExpiry?: boolean },
): string {
  if (!method.last4?.trim()) {
    return method.name?.trim() || 'Card'
  }
  const typeLabel = formatPaymentCardBrand(method.brand)
  let out = `${typeLabel} ending in ${method.last4}`
  if (options?.includeExpiry && method.expiryMonth && method.expiryYear) {
    out += ` · Expires ${formatCardExpiry(method.expiryMonth, method.expiryYear)}`
  }
  return out
}

/** Billing-related fields on org API payloads (SDK Team type may omit these). */
export type OrganizationPaymentRefs = {
  paymentMethodId?: string | null
  backupPaymentMethodId?: string | null
  failedInvoice?: { $id: string; lastError?: string; type?: string } | null
  billingPlanDowngrade?: unknown
}

export function asOrganizationPaymentRefs(
  organization: unknown,
): OrganizationPaymentRefs {
  return organization as OrganizationPaymentRefs
}

/** Subscription failed invoices only - other types (domains, addons, etc.) must not trigger payment alerts. */
export function isSubscriptionFailedInvoiceWithError(
  failedInvoice: OrganizationPaymentRefs['failedInvoice'],
): failedInvoice is NonNullable<OrganizationPaymentRefs['failedInvoice']> {
  return (
    !!failedInvoice &&
    failedInvoice.type === 'subscription' &&
    !!failedInvoice.lastError
  )
}

