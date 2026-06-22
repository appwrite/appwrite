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
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <ContextMenuItem onSelect={onUpdate}>
          <ContextMenuIcon icon={Pencil} />
          Update
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={ArrowLeftRight} />
            Replace
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
            <ContextMenuItem onSelect={onAddNew}>
              <ContextMenuIcon icon={Plus} />
              Add new address
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuSub>
          <ContextMenuSubTrigger>
            <ContextMenuIcon icon={Copy} />
            Copy
          </ContextMenuSubTrigger>
          <ContextMenuSubContent>
            <ContextMenuItem
              onSelect={() => copyToClipboard('ID', address.$id)}
            >
              <ContextMenuIcon icon={Copy} />
              Copy ID
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                copyToClipboard('Address', formatAddressLabel(address))
              }
            >
              <ContextMenuIcon icon={Copy} />
              Copy name
            </ContextMenuItem>
            <ContextMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => fetchBillingAddress(address.$id))
              }
            >
              <ContextMenuIcon icon={FileJson} />
              Copy as JSON
            </ContextMenuItem>
          </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSeparator />
        <ContextMenuItem onSelect={onRemove}>
          <ContextMenuIcon icon={Trash2} />
          Remove
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  )
}
