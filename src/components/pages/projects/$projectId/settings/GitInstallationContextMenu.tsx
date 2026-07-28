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
import { Copy, FileJson, Link2, Settings, Trash2 } from 'lucide-react'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import { getProviderOwnerUrl } from '@/lib/vcs/providers'

interface GitInstallationContextMenuProps {
  installation: Models.Installation
  configureHref: string | null
  onDisconnect: (installation: Models.Installation) => void
  children: React.ReactNode
}

export function GitInstallationContextMenu({
  installation,
  configureHref,
  onDisconnect,
  children,
}: GitInstallationContextMenuProps) {
  const t = useT()
  const providerUrl = getProviderOwnerUrl(
    installation.provider,
    installation.organization,
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {configureHref ? (
          <ContextMenuItem
            onSelect={() =>
              window.open(configureHref, '_blank', 'noreferrer')
            }
          >
            <ContextMenuIcon icon={Settings} />
            {t('Configure')}
          </ContextMenuItem>
        ) : null}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', installation.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('Organization', installation.organization)
              }
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            {providerUrl ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', providerUrl)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => installation, {
                  fallback: installation,
                })
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => onDisconnect(installation)}>
          <ContextMenuIcon icon={Trash2} />
          {t('Disconnect')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
