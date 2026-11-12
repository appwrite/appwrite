import { useRouter } from '@tanstack/react-router'
import { useEffect } from 'react'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { isSelfHostedConsoleProfile } from '@/lib/runtime-config-shared'
import { registerSiteTools } from '@/lib/webmcp/site/tools'

/**
 * Registers appwrite.io's WebMCP tools while a public-site layout is mounted.
 * Called from those layouts, not the root, so the tools ship in a route
 * chunk the page HTML lists, where scanners look for them.
 */
export function useSiteTools(): void {
  const router = useRouter()
  useEffect(() => {
    if (isSelfHostedConsoleProfile(getRuntimeConfig().consoleProfile)) return
    return registerSiteTools((path) => router.navigate({ href: path }))
  }, [router])
}
