/**
 * Bridge so the agent right-pane (sibling of route layouts) can open the
 * page-owned Command Center. React context cannot cross that sibling boundary.
 */

export type CommandCenterPage =
  | 'shortcuts'
  | 'feedback'
  | 'support'
  | 'docs'

type CommandCenterOpener = (page: CommandCenterPage | null) => void

let opener: CommandCenterOpener | null = null

/** Register the active page's Command Center opener. Returns unregister. */
export function registerCommandCenterOpener(
  next: CommandCenterOpener,
): () => void {
  opener = next
  return () => {
    if (opener === next) opener = null
  }
}

/** Open Command Center, optionally on a named sub-page. */
export function openCommandCenterViaBridge(
  page: CommandCenterPage | null = null,
): boolean {
  if (!opener) return false
  opener(page)
  return true
}
