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
import {
  copyResourceAsJson,
  copyToClipboard,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

interface GitInstallationContextMenuProps {
  installation: Models.Installation
  configureHref: string
  onDisconnect: (installation: Models.Installation) => void
  children: React.ReactNode
}

function getProviderUrl(provider: string, organization: string) {
  if (provider === 'github') {
    return `https://github.com/${organization}`
  }
  return null
}

export function GitInstallationContextMenu({
  installation,
  configureHref,
  onDisconnect,
  children,
}: GitInstallationContextMenuProps) {
  const providerUrl = getProviderUrl(
    installation.provider,
    installation.organization,
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() => window.open(configureHref, '_blank', 'noreferrer')}
        >
          <ContextMenuIcon icon={Settings} />
          Configure
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', installation.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('Organization', installation.organization)
              }
            >
              <ContextMenuIcon icon={Copy} />
              Copy name
            </ContextMenuItem>
            {providerUrl ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', providerUrl)}
              >
                <ContextMenuIcon icon={Link2} />
                Copy link
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
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => onDisconnect(installation)}>
          <ContextMenuIcon icon={Trash2} />
          Disconnect
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
