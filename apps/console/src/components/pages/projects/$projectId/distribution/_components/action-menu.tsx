import type { ComponentType, ReactNode } from 'react'
import {
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu'

type ActionMenuItemProps = {
  onSelect?: (event: Event) => void
  disabled?: boolean
  title?: string
  'data-analytics'?: string
  children?: ReactNode
}

export type ActionMenuParts = {
  Item: ComponentType<ActionMenuItemProps>
  Separator: ComponentType
  Sub: ComponentType<{ children?: ReactNode }>
  SubTrigger: ComponentType<{ children?: ReactNode }>
  SubContent: ComponentType<{ children?: ReactNode }>
}

export const CONTEXT_MENU_PARTS: ActionMenuParts = {
  Item: ContextMenuItem,
  Separator: ContextMenuSeparator,
  Sub: ContextMenuSub,
  SubTrigger: ContextMenuSubTrigger,
  SubContent: ContextMenuSubContent,
}

export const DROPDOWN_MENU_PARTS: ActionMenuParts = {
  Item: DropdownMenuItem,
  Separator: DropdownMenuSeparator,
  Sub: DropdownMenuSub,
  SubTrigger: DropdownMenuSubTrigger,
  SubContent: DropdownMenuSubContent,
}
