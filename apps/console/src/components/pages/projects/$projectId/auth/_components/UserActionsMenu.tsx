import { Copy, User2, Link2, FileJson } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
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
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { fetchUser } from '@/lib/react-query/hooks/users'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
} from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import {
  SendMessageMenuSub,
  useUserReach,
} from '../../messaging/_components/SendMessageMenu'

/** The user detail page's ⋯ menu: same Actions and Copy groups as `UserContextMenu`. */
export function UserActionsMenu({
  projectId,
  user,
}: {
  projectId: string
  user: Pick<Models.User, '$id' | 'name' | 'targets'>
}) {
  const t = useT()
  const reach = useUserReach(user.targets)
  const userHref = buildConsoleUrl(
    `/projects/${projectId}/auth/users/${user.$id}`,
  )

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <RowActionsMenuTrigger />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <SendMessageMenuSub
          projectId={projectId}
          users={[user.$id]}
          reach={reach}
        />
        <DropdownMenuSeparator />
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>
            <MenuItemContent icon={Copy}>{t('Copy')}</MenuItemContent>
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            <DropdownMenuItem onSelect={() => copyToClipboard('ID', user.$id)}>
              <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
            </DropdownMenuItem>
            {user.name && (
              <DropdownMenuItem
                onSelect={() => copyToClipboard('Name', user.name)}
              >
                <MenuItemContent icon={User2}>{t('Copy name')}</MenuItemContent>
              </DropdownMenuItem>
            )}
            <DropdownMenuItem
              onSelect={() => copyToClipboard('Link', userHref)}
            >
              <MenuItemContent icon={Link2}>{t('Copy link')}</MenuItemContent>
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => fetchUser(projectId, user.$id))
              }
            >
              <MenuItemContent icon={FileJson}>
                {t('Copy as JSON')}
              </MenuItemContent>
            </DropdownMenuItem>
          </DropdownMenuSubContent>
        </DropdownMenuSub>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
