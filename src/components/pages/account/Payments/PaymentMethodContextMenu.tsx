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
import { Copy, FileJson, Pencil, Trash2 } from 'lucide-react'
import { fetchPaymentMethod } from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'

interface PaymentMethodContextMenuProps {
  paymentMethod: Models.PaymentMethod
  onUpdate: (method: Models.PaymentMethod) => void
  onDelete: (method: Models.PaymentMethod) => void
  children: React.ReactNode
}

export function PaymentMethodContextMenu({
  paymentMethod,
  onUpdate,
  onDelete,
  children,
}: PaymentMethodContextMenuProps) {
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={() => onUpdate(paymentMethod)}>
          <ContextMenuIcon icon={Pencil} />
          Update
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', paymentMethod.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            {paymentMethod.name ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', paymentMethod.name)}
              >
                <ContextMenuIcon icon={Copy} />
                Copy name
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchPaymentMethod(paymentMethod.$id),
                )
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={() => onDelete(paymentMethod)}>
          <ContextMenuIcon icon={Trash2} />
          Delete
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
