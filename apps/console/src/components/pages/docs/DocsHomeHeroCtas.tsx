'use client'

import { Link, useLocation } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { Button } from '@/components/ui/button'
import { isConsoleDocsPreviewPath } from '@/lib/docs/docs-preview-context'
import type { DocsPreviewView } from '@/lib/docs/docs-preview-menu'
import { useDocsPreviewNavigation } from '@/lib/docs/docs-preview-navigation'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useLocalMarketingEnabled } from '@/lib/marketing/local-marketing'
import { DocsRouteLink } from './DocsRouteLink'

const PRIMARY_BUTTON_CLASS = 'h-10 text-[14px]'
const SECONDARY_BUTTON_CLASS = 'h-10 text-[14px]'

type DocsHomeHeroCtasProps = {
  quickStartsPreviewView?: DocsPreviewView
}

/** Shared docs home hero CTAs (full page + in-console preview). */
export function DocsHomeHeroCtas({
  quickStartsPreviewView = 'menu',
}: DocsHomeHeroCtasProps) {
  const location = useLocation()
  const marketingEnabled = useLocalMarketingEnabled()
  const previewNav = useDocsPreviewNavigation()
  const canUsePreviewPane =
    marketingEnabled && isConsoleDocsPreviewPath(location.pathname)
  const useStableRouteLinks = !previewNav && !canUsePreviewPane

  const primaryContent = (
    <>
      <McpIcon className="size-4 opacity-90" />
      Install MCP
      <ArrowRight className="ms-1.5 size-4" />
    </>
  )

  return (
    <div className="mt-8 flex flex-wrap items-center justify-start gap-2 @[480px]:mt-10">
      <Button variant="brandCta" size="lg" className={PRIMARY_BUTTON_CLASS} asChild>
        {useStableRouteLinks ? (
          <Link
            to="/docs/$"
            params={{ _splat: 'tooling/ai/mcp-servers' }}
            {...analyticsAttrs('docs-mcp-cta')}
          >
            {primaryContent}
          </Link>
        ) : (
          <DocsRouteLink
            href="/docs/tooling/ai/mcp-servers"
            {...analyticsAttrs('docs-mcp-cta')}
          >
            {primaryContent}
          </DocsRouteLink>
        )}
      </Button>
      <Button variant="outline" size="lg" className={SECONDARY_BUTTON_CLASS} asChild>
        {useStableRouteLinks ? (
          <Link to="/docs/quick-starts" {...analyticsAttrs('docs-get-started')}>
            Quick start guides
          </Link>
        ) : (
          <DocsRouteLink
            href="/docs/quick-starts"
            previewView={quickStartsPreviewView}
            {...analyticsAttrs('docs-get-started')}
          >
            Quick start guides
          </DocsRouteLink>
        )}
      </Button>
    </div>
  )
}
