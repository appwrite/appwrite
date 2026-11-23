export type SqlQueryPlanMode = 'explain' | 'analyze'

export function isSqlQueryPlanMode(
  value: string | undefined,
): value is SqlQueryPlanMode {
  return value === 'explain' || value === 'analyze'
}
