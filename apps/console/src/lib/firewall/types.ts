export type FirewallImpactPoint = {
  date: string
  day: Date
  fullDate: string
  total: number
  matched: number
}

/** Per-interval WAF activity for a rule's action (project-wide counters). */
export type FirewallActionActivityPoint = {
  date: string
  day: Date
  fullDate: string
  /** Count of the action metric in this interval (e.g. denied / solved). */
  value: number
  /** Average solve time in ms this interval — challenge action only. */
  avgSolveTimeMs?: number
}
