import { resolveInitHref, type ResolvedInitHref } from '@/lib/init/links'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'

export function useInitHref(href: string | undefined): ResolvedInitHref | null {
  return resolveInitHref(href, isPreLaunchModeEnabled())
}
