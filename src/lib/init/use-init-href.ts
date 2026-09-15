import { useDebugOverrides } from '@/lib/debug-overrides'
import { resolveInitHref, type ResolvedInitHref } from '@/lib/init/links'

export function useInitHref(href: string | undefined): ResolvedInitHref | null {
  const { preLaunch } = useDebugOverrides()
  return resolveInitHref(href, preLaunch)
}
