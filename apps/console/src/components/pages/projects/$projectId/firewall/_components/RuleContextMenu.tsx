import type { Models } from '@appwrite.io/console'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pencil,
  Square,
  ToggleLeft,
  ToggleRight,
  Trash2,
} from 'lucide-react'
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
import { fetchFirewallRule } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface RuleContextMenuProps {
  projectId: string
  rule: Models.WafRule
  canWrite: boolean
  togglePending?: boolean
  onUpdate: (rule: Models.WafRule) => void
  onToggleEnabled: (rule: Models.WafRule) => void
  onDelete: (rule: Models.WafRule) => void
  children: React.ReactNode
}

export function RuleContextMenu({
  projectId,
  rule,
  canWrite,
  togglePending = false,
  onUpdate,
  onToggleEnabled,
  onDelete,
  children,
}: RuleContextMenuProps) {
  const t = useT()
  const ruleHref = buildConsoleUrl(`/projects/${projectId}/firewall`)

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          disabled={!canWrite}
          onSelect={() => openDialogAfterOverlayCloses(() => onUpdate(rule))}
        >
          <ContextMenuIcon icon={Pencil} />
          {t('Update')}
        </ContextMenuItem>
        <ContextMenuItem
          disabled={!canWrite || togglePending}
          onSelect={() => onToggleEnabled(rule)}
        >
          <ContextMenuIcon icon={rule.enabled ? ToggleLeft : ToggleRight} />
          {rule.enabled ? t('Disable') : t('Enable')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', rule.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Name', rule.name)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Link', ruleHref)}
            >
              <ContextMenuIcon icon={Link2} />
              {t('Copy link')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(
                  () => fetchFirewallRule(projectId, rule.$id),
                  { fallback: rule },
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => openInNewTab(ruleHref)}>
          <ContextMenuIcon icon={ExternalLink} />
          {t('Open in new tab')}
        </ContextMenuItem>
        <ContextMenuItem onSelect={() => openInNewWindow(ruleHref)}>
          <ContextMenuIcon icon={Square} />
          {t('Open in new window')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem
          disabled={!canWrite}
          onSelect={() => openDialogAfterOverlayCloses(() => onDelete(rule))}
        >
          <ContextMenuIcon icon={Trash2} />
          {t('Delete')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
