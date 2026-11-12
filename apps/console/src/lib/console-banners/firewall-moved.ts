/** Projects this many days old had Firewall in the sidebar before it moved. */
export const FIREWALL_MOVED_MIN_PROJECT_AGE_DAYS = 3

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * Whether a project is old enough for its people to remember Firewall in the
 * sidebar. Newer projects only ever had the tabs, so they need no explainer.
 */
export function isFirewallMovedProject(
  projectCreatedAt: string | undefined,
  now: Date = new Date(),
): boolean {
  if (!projectCreatedAt) return false
  const createdMs = new Date(projectCreatedAt).getTime()
  if (Number.isNaN(createdMs)) return false
  return (
    now.getTime() - createdMs >= FIREWALL_MOVED_MIN_PROJECT_AGE_DAYS * DAY_MS
  )
}
