import { useNavigate } from '@tanstack/react-router'
import { Bell, Mail, Phone, Send, type LucideIcon } from 'lucide-react'
import { MessagingProviderType } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
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

interface SendMessageMenuProps {
  projectId: string
  topics?: string[]
  users?: string[]
  /** Reason a channel can't reach these recipients, keyed by channel. */
  unavailable?: Partial<Record<MessagingProviderType, string>>
  /** `inline` always shows the label instead of following the header width. */
  layout?: 'toolbar' | 'inline'
}

/** Creates a draft addressed to the recipients, then opens it in the editor. */
function useSendMessage({ projectId, topics, users }: SendMessageMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const createDraft = useCreateDraftMessage(projectId)

  const send = (type: MessagingProviderType) =>
    createDraft.mutate(
      { type, topics, users },
      {
        onSuccess: (message) => {
          toast.success(t('Draft message created'))
          navigate({
            to: '/projects/$projectId/messaging/$messageId',
            params: { projectId, messageId: message.$id },
          })
        },
        onError: (e: Error) =>
          toast.error(getErrorMessage(e) || t('Could not create message')),
      },
    )

  return {
    send,
    pending: createDraft.isPending,
    allowed: canWriteMessages(access, features),
  }
}

function ChannelLabel({
  label,
  icon,
  reason,
}: {
  label: string
  icon: LucideIcon
  reason?: string
}) {
  const t = useT()
  return (
    <MenuItemContent icon={icon}>
      {t(label)}
      {reason && (
        <span className="block text-[11px] text-muted-foreground">
          {t(reason)}
        </span>
      )}
    </MenuItemContent>
  )
}

/** Header button that starts a message to the given topics or users. */
export function SendMessageMenu(props: SendMessageMenuProps) {
  const t = useT()
  const { send, pending, allowed } = useSendMessage(props)

  const button = (
    <Button
      variant="outline"
      size="sm"
      className={
        props.layout === 'inline'
          ? 'h-8 gap-2 text-xs'
          : 'h-9 w-9 p-0 text-[13px] @[640px]:w-auto @[640px]:px-3'
      }
      disabled={!allowed || pending}
      aria-label={t('Send message')}
      {...analyticsAttrs('send-message')}
    >
      <Send
        className={
          props.layout === 'inline'
            ? 'h-4 w-4 shrink-0'
            : 'h-4 w-4 shrink-0 @[640px]:me-1.5'
        }
      />
      <span
        className={
          props.layout === 'inline' ? undefined : 'hidden @[640px]:inline'
        }
      >
        {t('Send message')}
      </span>
    </Button>
  )

  if (!allowed) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex">{button}</span>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            <p>{t("You don't have permission to create messages.")}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>{button}</DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        {CHANNELS.map((channel) => (
          <DropdownMenuItem
            key={channel.type}
            disabled={pending || !!props.unavailable?.[channel.type]}
            onSelect={() => send(channel.type)}
          >
            <ChannelLabel
              label={channel.label}
              icon={channel.icon}
              reason={props.unavailable?.[channel.type]}
            />
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** Context menu submenu that starts a message to the given topics or users. */
export function SendMessageContextSub(props: SendMessageMenuProps) {
  const t = useT()
  const { send, pending, allowed } = useSendMessage(props)

  return (
    <ContextMenuSub>
      <ContextMenuSubTrigger disabled={!allowed || pending}>
        <MenuItemContent icon={Send}>{t('Send message')}</MenuItemContent>
      </ContextMenuSubTrigger>
      <ContextMenuSubContent className="w-56">
        {CHANNELS.map((channel) => (
          <ContextMenuItem
            key={channel.type}
            disabled={pending || !!props.unavailable?.[channel.type]}
            onSelect={() => send(channel.type)}
          >
            <ChannelLabel
              label={channel.label}
              icon={channel.icon}
              reason={props.unavailable?.[channel.type]}
            />
          </ContextMenuItem>
        ))}
      </ContextMenuSubContent>
    </ContextMenuSub>
  )
}
