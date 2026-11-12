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
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pencil,
  Square,
  Trash2,
} from 'lucide-react'
import { fetchProjectOAuth2App } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'

interface ProjectOAuth2AppContextMenuProps {
  projectId: string
  region?: string
  app: Models.App
  onUpdate: (app: Models.App) => void
  onDelete: (app: Models.App) => void
  children: React.ReactNode
}

export function ProjectOAuth2AppContextMenu({
  projectId,
  region,
  app,
  onUpdate,
  onDelete,
  children,
}: ProjectOAuth2AppContextMenuProps) {
  const t = useT()
  const appHref = buildConsoleUrl(
    `/projects/${projectId}/auth/oauth2-server/apps?appId=${app.$id}`,
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(() => onUpdate(app))}
        >
          <ContextMenuIcon icon={Pencil} />
          {t('Update')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', app.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', app.name)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', appHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchProjectOAuth2App(projectId, app.$id, region),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(appHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(appHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(() => onDelete(app))}
        >
          <ContextMenuIcon icon={Trash2} />
          {t('Delete')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
