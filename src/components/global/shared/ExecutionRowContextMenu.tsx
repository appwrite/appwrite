import {
  Copy,
  ExternalLink,
  FileJson,
  LayoutList,
  Link2,
  Square,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  fetchFunctionExecution,
  fetchSiteLog,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

export type ExecutionRowContextMenuVariant = 'function' | 'site'

interface ExecutionRowContextMenuProps {
  variant: ExecutionRowContextMenuVariant
  projectId: string
  resourceId: string
  execution: Pick<Models.Execution, '$id'>
  onOpenDetails: () => void
  children: React.ReactNode
}

function executionPermalink(
  variant: ExecutionRowContextMenuVariant,
  projectId: string,
  resourceId: string,
  executionId: string,
) {
  const path =
    variant === 'function'
      ? `/projects/${projectId}/functions/${resourceId}/executions?executionId=${encodeURIComponent(executionId)}`
      : `/projects/${projectId}/sites/${resourceId}/logs?executionId=${encodeURIComponent(executionId)}`
  return buildConsoleUrl(path)
}

export function ExecutionRowContextMenu({
  variant,
  projectId,
  resourceId,
  execution,
  onOpenDetails,
  children,
}: ExecutionRowContextMenuProps) {
  const t = useT()
  if (!execution?.$id || !projectId || !resourceId) {
    return <>{children}</>
  }

  const executionHref = executionPermalink(
    variant,
    projectId,
    resourceId,
    execution.$id,
  )

  const fetchExecution = () =>
    variant === 'function'
      ? fetchFunctionExecution(projectId, resourceId, execution.$id)
      : fetchSiteLog(projectId, resourceId, execution.$id)

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={() => onOpenDetails()}>
          <ContextMenuIcon icon={LayoutList} />
          {t('Overview')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', execution.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', executionHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => void copyResourceAsJson(fetchExecution)}
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(executionHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(executionHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
