import { useRouter } from '@tanstack/react-router'
import { useEffect } from 'react'
import { recordCalls } from '@/lib/webmcp/calls'
import { installSiteTools } from '@/lib/webmcp/site/tools'

/**
 * Keeps appwrite.io's WebMCP tools registered while a public-site layout is
 * mounted, and answers their calls with ./actions, which loads only in
 * browsers that have WebMCP.
 */
export function useSiteTools(): void {
  const router = useRouter()
  useEffect(() => {
    // SITE_TOOLS_SCRIPT only runs on a full page load, not a client-side navigation.
    installSiteTools()
    const bridge = window.__appwriteSiteTools
    if (!bridge) return
    bridge.register()

    let disconnect: (() => void) | undefined
    let unmounted = false
    void import('@/lib/webmcp/site/actions').then(({ siteHandlers }) => {
      if (unmounted) return
      disconnect = bridge.connect(
        recordCalls(siteHandlers((path) => router.navigate({ href: path }))),
      )
    })
    return () => {
      unmounted = true
      disconnect?.()
      bridge.unregister()
    }
  }, [router])
}
