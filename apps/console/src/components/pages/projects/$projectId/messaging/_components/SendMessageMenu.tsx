import { useNavigate } from '@tanstack/react-router'
import { Bell, Mail, Phone, Send, type LucideIcon } from 'lucide-react'
import { MessagingProviderType, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  ContextMenuItem,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
} from '@/components/ui/context-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import {
  useCreateDraftMessage,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canWriteMessages } from '@/lib/console-access-checks'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

const CHANNELS: {
  type: MessagingProviderType
  label: string
  icon: LucideIcon
}[] = [
  { type: MessagingProviderType.Email, label: 'Email', icon: Mail },
  { type: MessagingProviderType.Sms, label: 'SMS', icon: Phone },
  { type: MessagingProviderType.Push, label: 'Push', icon: Bell },
]

/** Plural noun for several targets of one channel on a single user. */
const TARGET_NOUNS: Record<MessagingProviderType, string> = {
  [MessagingProviderType.Email]: 'email addresses',
  [MessagingProviderType.Sms]: 'phone numbers',
  [MessagingProviderType.Push]: 'devices',
}

type Target = Pick<Models.Target, 'providerType' | 'identifier' | 'name'>

/** Who a channel would reach, shown next to it so the choice is concrete. */
export type Reach = Record<
  MessagingProviderType,
  { detail: string; available: boolean }
>

interface Recipients {
  topics?: string[]
  users?: string[]
  targets?: string[]
}

interface SendMessageProps extends Recipients {
  projectId: string
  reach: Reach
}

function reachEach(
  detail: (type: MessagingProviderType) => Reach[MessagingProviderType],
): Reach {
  return Object.fromEntries(
    CHANNELS.map(({ type }) => [type, detail(type)]),
  ) as Reach
}

/** One user: their address, or how many targets they have per channel. */
export function useUserReach(targets: Target[]): Reach {
  const t = useT()
  return reachEach((type) => {
    const matching = targets.filter((target) => target.providerType === type)
    if (matching.length === 0) {
      return { detail: t('No targets for this channel'), available: false }
    }
    if (matching.length > 1) {
      return {
        detail: `${matching.length} ${t(TARGET_NOUNS[type])}`,
        available: true,
      }
    }
    // A push identifier is a device token, so show the device name instead.
    const [target] = matching
    const detail =
      type === MessagingProviderType.Push
        ? target.name || `1 ${t('device')}`
        : target.identifier
    return { detail, available: true }
  })
}

/** Several users: how many of them each channel reaches. */
export function useUsersReach(users: { targets: Target[] }[]): Reach {
  const t = useT()
  return reachEach((type) => {
    const count = users.filter((user) =>
      user.targets.some((target) => target.providerType === type),
    ).length
    if (count === 0) {
      return { detail: t('No targets for this channel'), available: false }
    }
    return {
      detail: `${count.toLocaleString()} ${t(count === 1 ? 'user' : 'users')}`,
      available: true,
    }
  })
}

/** A topic: its subscriber count per channel. */
export function useTopicReach(
  topic: Pick<Models.Topic, 'emailTotal' | 'smsTotal' | 'pushTotal'>,
): Reach {
  const t = useT()
  const totals: Record<MessagingProviderType, number> = {
    [MessagingProviderType.Email]: topic.emailTotal,
    [MessagingProviderType.Sms]: topic.smsTotal,
    [MessagingProviderType.Push]: topic.pushTotal,
  }
  return reachEach((type) => {
    const count = totals[type] ?? 0
    if (count === 0) {
      return { detail: t('No subscribers for this channel'), available: false }
    }
    return {
      detail: `${count.toLocaleString()} ${t(count === 1 ? 'subscriber' : 'subscribers')}`,
      available: true,
    }
  })
}

/** Creates a draft addressed to the recipients, then opens it in the editor. */
function useSendMessage(projectId: string) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  // Selecting a channel closes the menu and unmounts this component,
  // so the handlers live on the mutation rather than on `mutate()`.
  const createDraft = useCreateDraftMessage(projectId, {
    onSuccess: (message) => {
      toast.success(t('Draft message created'))
      navigate({
        to: '/projects/$projectId/messaging/$messageId',
        params: { projectId, messageId: message.$id },
      })
    },
    onError: (e) =>
      toast.error(getErrorMessage(e) || t('Could not create message')),
  })

  const send = (type: MessagingProviderType, recipients: Recipients) =>
    createDraft.mutate({ type, ...recipients })

  return {
    send,
    pending: createDraft.isPending,
    allowed: canWriteMessages(access, features),
  }
}

function ChannelLabel({
  label,
  icon,
  detail,
}: {
  label: string
  icon: LucideIcon
  detail: string
}) {
  const t = useT()
  return (
    <MenuItemContent icon={icon} className="flex items-center gap-3">
      <span className="shrink-0">{t(label)}</span>
      <span className="ms-auto truncate text-[12px] text-muted-foreground">
        {detail}
      </span>
    </MenuItemContent>
  )
}

/** "Send message" submenu for a resource's ⋯ actions menu. */
export function SendMessageMenuSub({
  projectId,
  reach,
  ...recipients
}: SendMessageProps) {
  const t = useT()
  const { send, pending, allowed } = useSendMessage(projectId)

  return (
    <DropdownMenuSub>
      <DropdownMenuSubTrigger disabled={!allowed || pending}>
        <MenuItemContent icon={Send}>{t('Send message')}</MenuItemContent>
      </DropdownMenuSubTrigger>
      <DropdownMenuSubContent className="w-80">
        {CHANNELS.map((channel) => (
          <DropdownMenuItem
            key={channel.type}
            disabled={pending || !reach[channel.type].available}
            onSelect={() => send(channel.type, recipients)}
            {...analyticsAttrs('send-message')}
          >
            <ChannelLabel
              label={channel.label}
              icon={channel.icon}
              detail={reach[channel.type].detail}
            />
          </DropdownMenuItem>
        ))}
      </DropdownMenuSubContent>
    </DropdownMenuSub>
  )
}

/** "Send message" submenu for a list row's right-click menu. */
export function SendMessageContextSub({
  projectId,
  reach,
  ...recipients
}: SendMessageProps) {
  const t = useT()
  const { send, pending, allowed } = useSendMessage(projectId)

  return (
    <ContextMenuSub>
      <ContextMenuSubTrigger disabled={!allowed || pending}>
        <MenuItemContent icon={Send}>{t('Send message')}</MenuItemContent>
      </ContextMenuSubTrigger>
      <ContextMenuSubContent className="w-80">
        {CHANNELS.map((channel) => (
          <ContextMenuItem
            key={channel.type}
            disabled={pending || !reach[channel.type].available}
            onSelect={() => send(channel.type, recipients)}
            {...analyticsAttrs('send-message')}
          >
            <ChannelLabel
              label={channel.label}
              icon={channel.icon}
              detail={reach[channel.type].detail}
            />
          </ContextMenuItem>
        ))}
      </ContextMenuSubContent>
    </ContextMenuSub>
  )
}

/** Row action that messages one target; its channel is already known. */
export function SendMessageMenuItem({
  projectId,
  target,
}: {
  projectId: string
  target: Pick<Models.Target, '$id' | 'providerType'>
}) {
  const t = useT()
  const { send, pending, allowed } = useSendMessage(projectId)

  return (
    <DropdownMenuItem
      disabled={!allowed || pending}
      onSelect={() =>
        send(target.providerType as MessagingProviderType, {
          targets: [target.$id],
        })
      }
      {...analyticsAttrs('send-message')}
    >
      <MenuItemContent icon={Send}>{t('Send message')}</MenuItemContent>
    </DropdownMenuItem>
  )
}

/** Bulk action bar button; quieter than the bar's Delete. */
export function SendMessageMenu({
  projectId,
  reach,
  ...recipients
}: SendMessageProps) {
  const t = useT()
  const { send, pending, allowed } = useSendMessage(projectId)

  const button = (
    <Button
      variant="outline"
      size="sm"
      className="h-8 text-xs"
      disabled={!allowed || pending}
    >
      {t('Send message')}
    </Button>
  )

  if (!allowed) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex">{button}</span>
          </TooltipTrigger>
          <TooltipContent side="top">
            <p>{t("You don't have permission to create messages.")}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>{button}</DropdownMenuTrigger>
      <DropdownMenuContent align="end" side="top" className="w-80">
        {CHANNELS.map((channel) => (
          <DropdownMenuItem
            key={channel.type}
            disabled={pending || !reach[channel.type].available}
            onSelect={() => send(channel.type, recipients)}
            {...analyticsAttrs('send-message')}
          >
            <ChannelLabel
              label={channel.label}
              icon={channel.icon}
              detail={reach[channel.type].detail}
            />
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
