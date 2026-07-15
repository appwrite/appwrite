/**
 * Status Badge Utilities
 *
 * Provides standardized colors and variants for status badges across the application.
 * All status badges should use these utilities for consistency.
 */

import type { CanonicalPlanId } from '@/lib/utils/plan-filter'

/**
 * Status types for general use cases
 */
export type StatusType =
  | 'success'
  | 'warning'
  | 'error'
  | 'info'
  | 'pending'
  | 'processing'
  | 'active'
  | 'inactive'
  | 'completed'
  | 'failed'
  | 'verified'
  | 'unverified'

/**
 * Get status badge variant for Badge component
 * Maps status types to Badge component variants
 */
export function getStatusVariant(
  status: StatusType,
): 'default' | 'secondary' | 'destructive' | 'outline' {
  switch (status) {
    case 'success':
    case 'active':
    case 'completed':
    case 'verified':
      return 'default'
    case 'error':
    case 'failed':
    case 'unverified':
      return 'destructive'
    case 'warning':
    case 'pending':
      return 'secondary'
    case 'processing':
    case 'info':
    case 'inactive':
    default:
      return 'secondary'
  }
}

/**
 * Get status badge color classes for custom styling
 * Returns Tailwind CSS classes for consistent status colors
 */
export function getStatusColor(status: StatusType): string {
  switch (status) {
    case 'success':
    case 'active':
    case 'completed':
    case 'verified':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    case 'error':
    case 'failed':
    case 'unverified':
      return 'bg-red-500/10 text-red-600 dark:text-red-400'
    case 'warning':
    case 'pending':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    case 'processing':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400'
    case 'info':
      return 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
    case 'inactive':
    default:
      return 'bg-muted text-muted-foreground'
  }
}

/**
 * Invoice status types
 */
export type InvoiceStatus =
  | 'paid'
  | 'pending'
  | 'due'
  | 'overdue'
  | 'failed'
  | 'cancelled'
  | 'requires_authentication'

/**
 * Get invoice status badge color classes
 */
export function getInvoiceStatusColor(status: InvoiceStatus): string {
  switch (status) {
    case 'paid':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    case 'pending':
    case 'due':
    case 'requires_authentication':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    case 'overdue':
    case 'failed':
      return 'bg-red-500/10 text-red-600 dark:text-red-400'
    case 'cancelled':
      return 'bg-muted text-muted-foreground'
    default:
      return 'bg-muted text-muted-foreground'
  }
}

/** Status-style `Badge` variant for invoice rows (matches `badge.tsx` tinted status variants). */
export type InvoiceStatusBadgeVariant = 'success' | 'warning' | 'error' | 'info'

/**
 * Map invoice status to a status-style Badge variant (prefer over raw Tailwind on spans).
 */
export function getInvoiceStatusBadgeVariant(
  status: InvoiceStatus,
): InvoiceStatusBadgeVariant {
  switch (status) {
    case 'paid':
      return 'success'
    case 'pending':
    case 'due':
    case 'requires_authentication':
      return 'warning'
    case 'overdue':
    case 'failed':
      return 'error'
    case 'cancelled':
      return 'info'
    default:
      return 'info'
  }
}

/**
 * Plan types for organizations (alias of canonical billing plan ids)
 */
export type PlanType = CanonicalPlanId

/**
 * Get plan badge color classes
 */
export function getPlanBadgeColor(plan: PlanType): string {
  switch (plan) {
    case 'custom':
      return 'bg-purple-500/10 text-purple-600 dark:text-purple-300'
    case 'core':
      return 'bg-blue-500/10 text-blue-700 dark:text-blue-300'
    case 'pro':
    case 'education':
      return 'bg-emerald-500/10 text-emerald-900 dark:text-emerald-300'
    case 'free':
    default:
      return 'bg-muted text-muted-foreground'
  }
}

/**
 * Usage status types
 */
export type UsageStatus = 'normal' | 'warning' | 'critical'

/**
 * Get usage status badge color classes
 */
export function getUsageStatusColor(status: UsageStatus): string {
  switch (status) {
    case 'normal':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
    case 'warning':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    case 'critical':
      return 'bg-red-500/10 text-red-600 dark:text-red-400'
    default:
      return 'bg-muted text-muted-foreground'
  }
}

/**
 * Backup status types
 */
export type BackupStatus =
  | 'pending'
  | 'completed'
  | 'processing'
  | 'failed'
  | 'uploading'
  | 'downloading'

/**
 * Get backup status badge variant
 */
export function getBackupStatusVariant(
  status: BackupStatus,
): 'default' | 'secondary' | 'destructive' {
  switch (status) {
    case 'completed':
      return 'default'
    case 'failed':
      return 'destructive'
    case 'pending':
    case 'processing':
    case 'uploading':
    case 'downloading':
    default:
      return 'secondary'
  }
}

/**
 * Migration status types
 */
export type MigrationStatus = 'completed' | 'processing' | 'failed' | 'pending'

/**
 * Get migration status badge variant
 */
export function getMigrationStatusVariant(
  status: MigrationStatus,
): 'default' | 'secondary' | 'destructive' {
  switch (status) {
    case 'completed':
      return 'default'
    case 'failed':
      return 'destructive'
    case 'processing':
    case 'pending':
    default:
      return 'secondary'
  }
}

/**
 * Domain status types
 */
export type DomainStatus = 'verified' | 'verifying' | 'unverified' | 'created'

/** Status-style badge variant per AGENTS.md */
export type DomainStatusBadgeVariant = 'success' | 'processing' | 'error'

/**
 * Get domain status badge variant (status-style: success, processing, error)
 */
export function getDomainStatusVariant(
  status: DomainStatus,
): DomainStatusBadgeVariant | null {
  switch (status) {
    case 'verified':
      return null
    case 'verifying':
      return 'processing'
    case 'unverified':
    case 'created':
      return 'error'
    default:
      return 'processing'
  }
}

/**
 * Get domain status badge config for rendering
 */
export function getDomainStatusBadgeConfig(status: string): {
  variant: DomainStatusBadgeVariant | 'success'
  label: string
} {
  switch (status) {
    case 'verified':
      return { variant: 'success', label: 'Verified' }
    case 'verifying':
      return { variant: 'processing', label: 'Generating certificate' }
    case 'created':
      return { variant: 'error', label: 'Verification failed' }
    case 'unverified':
      return { variant: 'error', label: 'Certificate generation failed' }
    default:
      return { variant: 'processing', label: status }
  }
}
