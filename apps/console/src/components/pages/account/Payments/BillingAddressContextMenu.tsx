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
import { fetchBillingAddress } from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface BillingAddressContextMenuProps {
  address: Models.BillingAddress
  onUpdate: (address: Models.BillingAddress) => void
  onDelete: (address: Models.BillingAddress) => void
  children: React.ReactNode
}

function formatAddressLabel(address: Models.BillingAddress) {
  const parts = [
    address.streetAddress,
    address.city,
    address.country,
  ].filter(Boolean)
  return parts.join(', ') || address.$id
}

export function BillingAddressContextMenu({
  address,
  onUpdate,
  onDelete,
  children,
}: BillingAddressContextMenuProps) {
  const t = useT()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() =>
            openDialogAfterOverlayCloses(() => onUpdate(address))
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
              onSelect={() => copyToClipboard('ID', address.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy ID')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('Address', formatAddressLabel(address))
              }
            >
              <ContextMenuIcon icon={Copy} />
              {t('Copy name')}
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchBillingAddress(address.$id),
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
          onSelect={() =>
            openDialogAfterOverlayCloses(() => onDelete(address))
          }
        >
          <ContextMenuIcon icon={Trash2} />
          {t('Delete')}
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
