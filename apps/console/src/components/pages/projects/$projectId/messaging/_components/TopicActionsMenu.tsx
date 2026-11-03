import { Copy, Link2, FileJson } from 'lucide-react'
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
import { fetchTopic } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
} from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { SendMessageMenuSub, useTopicReach } from './SendMessageMenu'

/** The topic detail page's ⋯ menu: same Actions and Copy groups as `TopicContextMenu`. */
export function TopicActionsMenu({
  projectId,
  topic,
}: {
  projectId: string
  topic: Pick<
    Models.Topic,
    '$id' | 'name' | 'emailTotal' | 'smsTotal' | 'pushTotal'
  >
}) {
  const t = useT()
  const reach = useTopicReach(topic)
  const topicHref = buildConsoleUrl(
    `/projects/${projectId}/messaging/topics/${topic.$id}`,
  )

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <RowActionsMenuTrigger />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <SendMessageMenuSub
          projectId={projectId}
          topics={[topic.$id]}
          reach={reach}
        />
        <DropdownMenuSeparator />
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>
            <MenuItemContent icon={Copy}>{t('Copy')}</MenuItemContent>
          </DropdownMenuSubTrigger>
          <DropdownMenuSubContent>
            <DropdownMenuItem onSelect={() => copyToClipboard('ID', topic.$id)}>
              <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
            </DropdownMenuItem>
            {topic.name && (
              <DropdownMenuItem
                onSelect={() => copyToClipboard('Name', topic.name)}
              >
                <MenuItemContent icon={Copy}>{t('Copy name')}</MenuItemContent>
              </DropdownMenuItem>
            )}
            <DropdownMenuItem
              onSelect={() => copyToClipboard('Link', topicHref)}
            >
              <MenuItemContent icon={Link2}>{t('Copy link')}</MenuItemContent>
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() =>
                void copyResourceAsJson(() => fetchTopic(projectId, topic.$id))
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
