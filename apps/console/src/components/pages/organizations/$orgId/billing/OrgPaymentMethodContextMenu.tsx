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
  ArrowLeftRight,
  Copy,
  CreditCard,
  FileJson,
  Plus,
  Star,
  Trash2,
} from 'lucide-react'
import { fetchPaymentMethod } from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface OrgPaymentMethodContextMenuProps {
  method: Models.PaymentMethod
  isPrimary: boolean
  availableMethods: Models.PaymentMethod[]
  onSetPrimary?: (paymentMethodId: string) => void
  onReplacePrimary?: (paymentMethodId: string) => void
  onReplaceBackup?: (paymentMethodId: string) => void
  onRemove: () => void
  onAddPaymentMethod?: (isBackup?: boolean) => void
  children: React.ReactNode
}

export function OrgPaymentMethodContextMenu({
  method,
  isPrimary,
  availableMethods,
  onSetPrimary,
  onReplacePrimary,
  onReplaceBackup,
  onRemove,
  onAddPaymentMethod,
  children,
}: OrgPaymentMethodContextMenuProps) {
  const t = useT()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        {!isPrimary && onSetPrimary ? (
          <ContextMenuItem onSelect={() => onSetPrimary(method.$id)}>
            <ContextMenuIcon icon={Star} />
            {t('Primary')}
          </ContextMenuItem>
        ) : null}
        {((isPrimary && onReplacePrimary) || (!isPrimary && onReplaceBackup)) && (
          <>
            {!isPrimary && onSetPrimary ? <ContextMenuSeparator /> : null}
            <ContextMenuSub>
              <ContextMenuSubTrigger>
                <ContextMenuIcon icon={ArrowLeftRight} />
                {t('Replace')}
              </ContextMenuSubTrigger>
              <ContextMenuSubContent className="w-56">
                {availableMethods.map((availableMethod) => (
                  <ContextMenuItem
                    key={availableMethod.$id}
                    onSelect={() => {
                      if (isPrimary && onReplacePrimary) {
                        onReplacePrimary(availableMethod.$id)
                      } else if (!isPrimary && onReplaceBackup) {
                        onReplaceBackup(availableMethod.$id)
                      }
                    }}
                  >
                    <ContextMenuIcon icon={CreditCard} />
                    {availableMethod.brand} ••••{availableMethod.last4}
                  </ContextMenuItem>
                ))}
                {availableMethods.length > 0 ? <ContextMenuSeparator /> : null}
                <ContextMenuItem
                  onSelect={() =>
                    openDialogAfterOverlayCloses(() =>
                      onAddPaymentMethod?.(!isPrimary),
                    )
                  }
                >
                  <ContextMenuIcon icon={Plus} />
                  {t('Add')}
                </ContextMenuItem>
              </ContextMenuSubContent>
            </ContextMenuSub>
          </>
        )}
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', method.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            {method.name ? (
              <ContextMenuItem
                onSelect={() => copyToClipboard('Name', method.name)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy name')}
              </ContextMenuItem>
            ) : null}
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => fetchPaymentMethod(method.$id))
              }
            >
              <ContextMenuIcon icon={FileJson} />
              {t('Copy as JSON')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onRemove)}
        >
          <ContextMenuIcon icon={Trash2} />
          {t('Remove')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
