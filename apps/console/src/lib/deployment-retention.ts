import type { Models } from '@appwrite.io/console'

export const MAX_DEPLOYMENT_RETENTION = 36500
export const DEPLOYMENT_RETENTION_CHANGE_DATE = Date.UTC(2026, 10, 5)

export function getCloudRetentionLimit(
  plan: Pick<Models.BillingPlan, '$id' | 'name' | 'group'> | null | undefined,
): number | undefined {
  if (!plan) return undefined
  if (
    plan.group === 'free' ||
    plan.$id.toLowerCase() === 'education' ||
    plan.name.toLowerCase() === 'education'
  )
    return 90
  if (plan.group === 'start' || plan.group === 'pro') return 1095
  // Scale has a custom policy; do not impose the public plans' maximum.
  if (plan.group === 'scale') return MAX_DEPLOYMENT_RETENTION
  return undefined
}

export const DEPLOYMENT_RETENTION_OPTIONS = [
  { value: 7, label: '1 Week' },
  { value: 30, label: '1 Month' },
  { value: 90, label: '3 Months' },
  { value: 180, label: '6 Months' },
  { value: 365, label: '1 Year' },
  { value: 730, label: '2 Years' },
  { value: 1095, label: '3 Years' },
  { value: 1825, label: '5 Years' },
  { value: 3650, label: '10 Years' },
] as const

export function getRetentionOptions(
  retention: number,
  maxRetention = MAX_DEPLOYMENT_RETENTION,
) {
  const options = DEPLOYMENT_RETENTION_OPTIONS.filter(
    (option) => option.value <= maxRetention,
  )
  const hasCurrentOption = options.some((option) => option.value === retention)

  if (retention < 1 || retention > maxRetention || hasCurrentOption) {
    return options
  }

  return [{ value: retention, label: `${retention} days` }, ...options]
}

export function getDeploymentRetention(resource: {
  deploymentRetention?: number | null
}): number {
  return resource.deploymentRetention ?? 0
}
