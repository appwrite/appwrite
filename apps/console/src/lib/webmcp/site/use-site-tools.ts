import { useRouter } from '@tanstack/react-router'
import { useEffect } from 'react'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { isSelfHostedConsoleProfile } from '@/lib/runtime-config-shared'
import { hasWebMcp } from '@/lib/webmcp/site/detect'

/**
 * Registers appwrite.io's WebMCP tools while a public-site layout is mounted.
 * The tools chunk loads only when WEBMCP_DETECT_SCRIPT found WebMCP.
 */
export function useSiteTools(): void {
  const router = useRouter()
  useEffect(() => {
    if (isSelfHostedConsoleProfile(getRuntimeConfig().consoleProfile)) return
    if (!hasWebMcp()) return
    let unregister: (() => void) | undefined
    let unmounted = false
    void import('@/lib/webmcp/site/tools').then(({ registerSiteTools }) => {
      if (unmounted) return
      unregister = registerSiteTools((path) => router.navigate({ href: path }))
    })
    return () => {
      unmounted = true
      unregister?.()
    }
  }, [router])
}
