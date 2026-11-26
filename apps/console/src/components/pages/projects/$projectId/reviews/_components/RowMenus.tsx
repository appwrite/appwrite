import { Fragment, type ReactNode } from 'react'
import { Copy, type LucideIcon } from 'lucide-react'
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  ContextMenuIcon,
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'

export type RowAction = {
  icon: LucideIcon
  label: string
  onSelect: () => void
}

/**
 * What a row's menus offer, in the Console's order: the Copy submenu, links,
 * then the destructive action last. Empty sections are left out.
 */
export type RowActions = {
  copy: RowAction[]
  links: RowAction[]
  remove?: RowAction[]
}

/** The sections that have items, each to render after a separator but the first. */
function sections({ copy, links, remove = [] }: RowActions) {
  return [
    copy.length > 0 ? ('copy' as const) : null,
    links.length > 0 ? ('links' as const) : null,
    remove.length > 0 ? ('remove' as const) : null,
  ].filter((section) => section !== null)
}

/** Right-click menu of a row in the review screens. */
export function RowContextMenu({
  actions,
  children,
}: {
  actions: RowActions
  children: ReactNode
}) {
  const t = useT()
  const shown = sections(actions)
  if (shown.length === 0) return <>{children}</>

  const item = (action: RowAction) => (
    <ContextMenuItem key={action.label} onSelect={action.onSelect}>
      <ContextMenuIcon icon={action.icon} />
      {action.label}
    </ContextMenuItem>
  )

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {shown.map((section, index) => (
          <Fragment key={section}>
            {index > 0 && <ContextMenuSeparator />}
            {section === 'copy' ? (
              <ContextMenuSub>
                <ContextMenuSubTrigger>
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy')}
                </ContextMenuSubTrigger>
                <ContextMenuSubContent>
                  {actions.copy.map(item)}
                </ContextMenuSubContent>
              </ContextMenuSub>
            ) : section === 'links' ? (
              actions.links.map(item)
            ) : (
              (actions.remove ?? []).map(item)
            )}
          </Fragment>
        ))}
      </ContextMenuContent>
    </ContextMenu>
  )
}

/** The ⋯ menu of a row in the review screens: the same items as its right-click menu. */
export function RowActionsDropdown({
  actions,
  trigger,
}: {
  actions: RowActions
  trigger: ReactNode
}) {
  const t = useT()
  const shown = sections(actions)

  const item = (action: RowAction) => (
    <DropdownMenuItem key={action.label} onClick={action.onSelect}>
      <MenuItemContent icon={action.icon}>{action.label}</MenuItemContent>
    </DropdownMenuItem>
  )

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        {shown.map((section, index) => (
          <Fragment key={section}>
            {index > 0 && <DropdownMenuSeparator />}
            {section === 'copy' ? (
              <DropdownMenuSub>
                <DropdownMenuSubTrigger>
                  <MenuItemIcon icon={Copy} />
                  {t('Copy')}
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent>
                  {actions.copy.map(item)}
                </DropdownMenuSubContent>
              </DropdownMenuSub>
            ) : section === 'links' ? (
              actions.links.map(item)
            ) : (
              (actions.remove ?? []).map(item)
            )}
          </Fragment>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
