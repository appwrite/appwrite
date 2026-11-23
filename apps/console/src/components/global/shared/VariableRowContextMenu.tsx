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
import { Copy, Eye, FileJson, Lock, Pencil, Trash2 } from 'lucide-react'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import type { EnvVariable, VariableRecord } from './VariablesSettingsCard'

type VariableRowContextMenuProps =
  | {
      variant: 'wizard'
      variable: EnvVariable
      onToggleSecret: () => void
      onDelete: () => void
      children: React.ReactNode
    }
  | {
      variant: 'settings'
      variable: VariableRecord
      onUpdate: () => void
      onMarkSecret?: () => void
      onDelete: () => void
      children: React.ReactNode
    }

export function VariableRowContextMenu(props: VariableRowContextMenuProps) {
  const t = useT()
  const { children } = props

  if (props.variant === 'wizard') {
    const { variable, onToggleSecret, onDelete } = props
    return (
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('Key', variable.key)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy key')}
              </ContextMenuItem>
              {!variable.secret ? (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Value', variable.value)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy value')}
                </ContextMenuItem>
              ) : null}
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={onToggleSecret}>
            <ContextMenuIcon icon={variable.secret ? Eye : Lock} />
            {variable.secret ? t('Unmark secret') : t('Secret')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={onDelete}>
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>
    )
  }

  const { variable, onUpdate, onMarkSecret, onDelete } = props

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={onUpdate}>
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
              onSelect={() => copyToClipboard('ID', variable.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() => copyToClipboard('Key', variable.key)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy key')}
            </ContextMenuItem>
            {!variable.secret ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Value', variable.value)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy value')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => variable, { fallback: variable })
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        {!variable.secret && onMarkSecret ? (
          <>
            <ContextMenuSeparator />
            <ContextMenuItem onSelect={onMarkSecret}>
              <ContextMenuIcon icon={Lock} />
              {t('Secret')}
            </ContextMenuItem>
          </>
        ) : null}
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={onDelete}>
          <ContextMenuIcon icon={Trash2} />
          {t('Delete')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
