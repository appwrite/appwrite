export const MAX_DEPLOYMENT_RETENTION = 36500

export const DEPLOYMENT_RETENTION_OPTIONS = [
  { value: 7, label: '1 Week' },
  { value: 30, label: '1 Month' },
  { value: 90, label: '3 Months' },
  { value: 180, label: '6 Months' },
  { value: 365, label: '1 Year' },
  { value: 730, label: '2 Years' },
  { value: 1825, label: '5 Years' },
  { value: 3650, label: '10 Years' },
] as const

export function getRetentionOptions(retention: number) {
  const hasCurrentOption = DEPLOYMENT_RETENTION_OPTIONS.some(
    (option) => option.value === retention,
  )

  if (
    retention < 1 ||
    retention > MAX_DEPLOYMENT_RETENTION ||
    hasCurrentOption
  ) {
    return DEPLOYMENT_RETENTION_OPTIONS
  }

  return [
    { value: retention, label: `${retention} days` },
    ...DEPLOYMENT_RETENTION_OPTIONS,
  ]
}

export function getDeploymentRetention(
  resource: { deploymentRetention?: number | null },
): number {
  return resource.deploymentRetention ?? 0
}
