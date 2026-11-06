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
  FileJson,
  MapPin,
  Pencil,
  Plus,
  Trash2,
} from 'lucide-react'
import { fetchBillingAddress } from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface OrgBillingAddressContextMenuProps {
  address: Models.BillingAddress
  availableAddresses: Models.BillingAddress[]
  onUpdate: () => void
  onReplace: (addressId: string) => void
  onAddNew: () => void
  onRemove: () => void
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

export function OrgBillingAddressContextMenu({
  address,
  availableAddresses,
  onUpdate,
  onReplace,
  onAddNew,
  onRemove,
  children,
}: OrgBillingAddressContextMenuProps) {
  const t = useT()
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem
          onSelect={() => openDialogAfterOverlayCloses(onUpdate)}
        >
          <ContextMenuIcon icon={Pencil} />
          {t('Update')}
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={ArrowLeftRight} />
            {t('Replace')}
          </ContextMenuSubTrigger>
          <ContextMenuSubContent className="w-56">
            {availableAddresses.map((addr) => (
              <ContextMenuItem
                key={addr.$id}
                onSelect={() => onReplace(addr.$id)}
              >
                <ContextMenuIcon icon={MapPin} />
                {formatAddressLabel(addr)}
              </ContextMenuItem>
            ))}
            {availableAddresses.length > 0 ? <ContextMenuSeparator /> : null}
            <ContextMenuItem
              onSelect={() => openDialogAfterOverlayCloses(onAddNew)}
            >
              <ContextMenuIcon icon={Plus} />
              {t('Add new address')}
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
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
                void copyResourceAsJson(() => fetchBillingAddress(address.$id))
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
