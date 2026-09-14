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
import { useConsoleImpersonationActive } from '@/hooks/use-console-impersonation-active'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

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
  const t = useT()
  const isImpersonating = useConsoleImpersonationActive()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          disabled={isImpersonating}
          onSelect={() =>
            openDialogAfterOverlayCloses(() => onUpdate(paymentMethod))
          }
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
              onSelect={() => copyToClipboard('ID', paymentMethod.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            {paymentMethod.name ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', paymentMethod.name)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
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
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem
          disabled={isImpersonating}
          onSelect={() =>
            openDialogAfterOverlayCloses(() => onDelete(paymentMethod))
          }
        >
          <ContextMenuIcon icon={Trash2} />
          {t('Delete')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
