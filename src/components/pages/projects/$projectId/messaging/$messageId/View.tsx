import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  Mail,
  Phone,
  Bell,
  Trash2,
  X,
  Plus,
  Loader2,
  Calendar,
  AlertCircle,
  Hash,
  Users,
  Target,
  Paperclip,
} from 'lucide-react'
import {
  useMessage,
  useMessageTargets,
  MESSAGE_DETAIL_TARGETS_LIMIT,
  useProjectTopics,
  useProjectBuckets,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { messageLogsQueryOptions } from '@/lib/react-query/hooks/messaging'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Checkbox } from '@/components/ui/checkbox'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { ID, MessagePriority } from '@appwrite.io/console'
import { formatDateTime } from '@/lib/date-utils'
import { trimForPageTitle } from '@/lib/utils/page-title'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { StorageFileExplorerDialog } from '@/components/global/shared/StorageFileExplorerDialog'

function parseIdArray(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.filter(
    (x): x is string => typeof x === 'string' && x.trim().length > 0,
  )
}

/** Email attachments: API may return `bucketId:fileId` strings or `{ bucketId, fileId }` objects. */
function parseMessagingEmailAttachments(value: unknown): string[] {
  if (value == null) return []
  if (!Array.isArray(value)) return []
  const out: string[] = []
  for (const item of value) {
    if (typeof item === 'string') {
      const t = item.trim()
      if (t.length > 0) out.push(t)
      continue
    }
    if (item && typeof item === 'object') {
      const o = item as Record<string, unknown>
      const bucketId =
        typeof o.bucketId === 'string'
          ? o.bucketId
          : typeof o.bucket_id === 'string'
            ? o.bucket_id
            : undefined
      const fileId =
        typeof o.fileId === 'string'
          ? o.fileId
          : typeof o.file_id === 'string'
            ? o.file_id
            : typeof o.$id === 'string'
              ? o.$id
              : undefined
      if (bucketId && fileId) out.push(`${bucketId}:${fileId}`)
    }
  }
  return out
}
import {
  MessageSendDialog,
  MessageScheduleDialog,
  MessageCancelScheduleDialog,
} from '../_components/MessageDeliveryDialogs'
import { MessagingTargetsModal } from '../_components/MessagingTargetsModal'
import { MessagingRecipientUsersModal } from '../_components/MessagingRecipientUsersModal'
import { MessagingLogsTable } from '../_components/MessagingLogsTable'
import { EmailAttachmentRow } from '../_components/EmailAttachmentRow'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

type MessageComposeCardFooterProps = {
  messageStatus: Models.Message['status']
  hasContentChanges: boolean
  updatePending: boolean
  onSchedule: () => void
  onUpdateDraft: () => void
  onSend: () => void
  onCancelSchedule: () => void
  onReschedule: () => void
  onOpenLogs: () => void
}

function MessageComposeCardFooter({
  messageStatus,
  hasContentChanges,
  updatePending,
  onSchedule,
  onUpdateDraft,
  onSend,
  onCancelSchedule,
  onReschedule,
  onOpenLogs,
}: MessageComposeCardFooterProps) {
  const isDraft = messageStatus === 'draft'
  const isScheduled = messageStatus === 'scheduled'
  const logsEnabled = messageStatus !== 'draft'

  return (
    <div className="px-6 py-4 border-t border-border bg-muted/30">
      <div className="flex w-full flex-wrap items-center justify-between gap-2">
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 text-[13px]"
          disabled={!logsEnabled}
          onClick={onOpenLogs}
        >
          Logs
        </Button>
        <div className="flex min-w-0 flex-wrap items-center justify-end gap-2">
          {isDraft ? (
            <>
              <Button
                variant="ghost"
                size="sm"
                className="h-9 text-[13px]"
                onClick={onSchedule}
              >
                Schedule
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                disabled={!hasContentChanges || updatePending}
                onClick={onUpdateDraft}
              >
                Update draft
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 shrink-0 text-[13px]"
                onClick={onSend}
              >
                Send message
              </Button>
            </>
          ) : isScheduled ? (
            <>
              <Button
                variant="ghost"
                size="sm"
                className="h-9 text-[13px]"
                onClick={onCancelSchedule}
              >
                Cancel scheduling
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                onClick={onReschedule}
              >
                <Calendar className="me-1.5 h-4 w-4" />
                Reschedule
              </Button>
            </>
          ) : null}
        </div>
      </div>
    </div>
  )
}

export function View({
  initialMessage,
}: {
  initialMessage?: Models.Message
} = {}) {
  const { projectId, messageId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  const {
    data: message,
    isLoading: messageLoading,
    refetch: refetchMessage,
  } = useMessage(projectId, messageId, initialMessage)

  const { buckets } = useProjectBuckets(projectId ?? null, 0, 100, '')

  useEffect(() => {
    if (!message || message.status !== 'processing') {
      return
    }
    const interval = setInterval(() => {
      void refetchMessage()
    }, 2000)
    return () => clearInterval(interval)
  }, [message, refetchMessage])

  const { data: targetsData } = useMessageTargets(
    projectId,
    messageId,
    0,
    MESSAGE_DETAIL_TARGETS_LIMIT,
  )

  const targets = useMemo(
    () => targetsData?.targets || [],
    [targetsData?.targets],
  )

  const topicsById = useMemo(() => {
    if (!projectId || !message?.topics?.length) return {}
    const map: Record<string, Models.Topic> = {}
    for (const tid of message.topics) {
      const t = queryClient.getQueryData<Models.Topic>([
        'topic',
        'project',
        projectId,
        tid,
      ])
      if (t) map[tid] = t
    }
    return map
  }, [projectId, message, queryClient])

  const usersById = useMemo(() => {
    if (!projectId || !message) return {}
    const ids = new Set<string>()
    for (const t of targets) {
      if (t.userId) ids.add(t.userId)
    }
    const messageWithUsers = message as Models.Message & { users?: string[] }
    if (messageWithUsers.users) {
      for (const uid of messageWithUsers.users) {
        ids.add(uid)
      }
    }
    const map: Record<string, Models.User | null> = {}
    ids.forEach((uid) => {
      const data = queryClient.getQueryData<Models.User | null>([
        'user',
        'project',
        projectId,
        uid,
      ])
      map[uid] = data !== undefined ? data : null
    })
    return map
  }, [projectId, message, targets, queryClient])

  // Map targets by ID
  const targetsById = useMemo(() => {
    const map: Record<string, Models.Target> = {}
    targets.forEach((target) => {
      map[target.$id] = target
    })
    return map
  }, [targets])

  // Form state for message content (only for draft messages)
  const isDraft = message?.status === 'draft'
  const [emailSubject, setEmailSubject] = useState('')
  const [emailContent, setEmailContent] = useState('')
  const [emailHtml, setEmailHtml] = useState(false)
  const [smsContent, setSmsContent] = useState('')
  const [pushTitle, setPushTitle] = useState('')
  const [pushBody, setPushBody] = useState('')
  const [pushImage, setPushImage] = useState<File | null>(null)
  const [pushCustomData, setPushCustomData] = useState<
    Array<{ key: string; value: string }>
  >([{ key: '', value: '' }])

  const [selectedTopicIds, setSelectedTopicIds] = useState<Set<string>>(
    new Set(),
  )
  const [selectedTargetIds, setSelectedTargetIds] = useState<Set<string>>(
    new Set(),
  )

  const [selectedUserIds, setSelectedUserIds] = useState<Set<string>>(new Set())
  const [ccTargetIds, setCcTargetIds] = useState<Set<string>>(new Set())
  const [bccTargetIds, setBccTargetIds] = useState<Set<string>>(new Set())
  const [attachmentCompoundIds, setAttachmentCompoundIds] = useState<string[]>(
    [],
  )
  const [draftTargetDetailsById, setDraftTargetDetailsById] = useState<
    Record<string, Models.Target>
  >({})

  const displayTargetById = useMemo(
    () => ({ ...targetsById, ...draftTargetDetailsById }),
    [targetsById, draftTargetDetailsById],
  )

  const [pushBucketId, setPushBucketId] = useState('')
  const [pushAction, setPushAction] = useState('')
  const [pushIcon, setPushIcon] = useState('')
  const [pushSound, setPushSound] = useState('')
  const [pushColor, setPushColor] = useState('')
  const [pushTag, setPushTag] = useState('')
  const [pushBadge, setPushBadge] = useState('')
  const [pushPriority, setPushPriority] = useState<MessagePriority>(
    MessagePriority.Normal,
  )
  const [pushContentAvailable, setPushContentAvailable] = useState(false)
  const [pushCritical, setPushCritical] = useState(false)

  const [sendDialogOpen, setSendDialogOpen] = useState(false)
  const [scheduleDialogOpen, setScheduleDialogOpen] = useState(false)
  const [cancelScheduleOpen, setCancelScheduleOpen] = useState(false)
  const [recipientUsersModalOpen, setRecipientUsersModalOpen] = useState(false)
  const [attachmentExplorerOpen, setAttachmentExplorerOpen] = useState(false)
  const [targetPickerFor, setTargetPickerFor] = useState<
    'primary' | 'cc' | 'bcc' | null
  >(null)

  /** Sync topics / users / targets from the server only when those lists actually change (avoids wiping local draft edits on unrelated message refetches). */
  const messageServerListsKey = useMemo(() => {
    if (!message) return ''
    return [
      message.$id,
      [...(message.targets ?? [])].sort().join('|'),
      [...(message.topics ?? [])].sort().join('|'),
      [...(message.users ?? [])].sort().join('|'),
    ].join('\0')
  }, [message])

  useEffect(() => {
    if (!message) return
    setSelectedUserIds(new Set(message.users || []))
    setSelectedTopicIds(new Set(message.topics || []))
    setSelectedTargetIds(new Set(message.targets || []))
    // `message` omitted on purpose: including it would reset lists on every refetch even when server lists are unchanged.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [messageServerListsKey])

  /** Message body / options from API - only re-hydrate when id, channel, or payload changes. */
  const messagePayloadSyncKey = useMemo(
    () =>
      message?.data != null ? JSON.stringify(message.data) : '',
    [message?.data],
  )

  // Initialize form state when opening a different message or when server payload changes
  useEffect(() => {
    if (!message) return

    if (message.providerType === 'email') {
      setEmailSubject(message.data?.subject || '')
      setEmailContent(message.data?.content || '')
      setEmailHtml(message.data?.html || false)
      const raw = message.data as Record<string, unknown>
      setCcTargetIds(new Set(parseIdArray(raw?.cc)))
      setBccTargetIds(new Set(parseIdArray(raw?.bcc)))
      setAttachmentCompoundIds(parseMessagingEmailAttachments(raw?.attachments))
    } else if (message.providerType === 'sms') {
      setSmsContent(message.data?.content || '')
    } else if (message.providerType === 'push') {
      setPushTitle(message.data?.title || '')
      setPushBody(message.data?.body || '')
      const d = message.data as Record<string, unknown>
      setPushAction(typeof d?.action === 'string' ? d.action : '')
      setPushIcon(typeof d?.icon === 'string' ? d.icon : '')
      setPushSound(typeof d?.sound === 'string' ? d.sound : '')
      setPushColor(typeof d?.color === 'string' ? d.color : '')
      setPushTag(typeof d?.tag === 'string' ? d.tag : '')
      setPushBadge(
        typeof d?.badge === 'number'
          ? String(d.badge)
          : typeof d?.badge === 'string'
            ? d.badge
            : '',
      )
      setPushPriority(
        d?.priority === MessagePriority.High
          ? MessagePriority.High
          : MessagePriority.Normal,
      )
      setPushContentAvailable(Boolean(d?.contentAvailable))
      setPushCritical(Boolean(d?.critical))
      if (message.data?.data && typeof message.data.data === 'object') {
        const dataPairs = Object.entries(message.data.data).map(
          ([key, value]) => ({
            key,
            value: String(value),
          }),
        )
        setPushCustomData(
          dataPairs.length > 0 ? dataPairs : [{ key: '', value: '' }],
        )
      } else {
        setPushCustomData([{ key: '', value: '' }])
      }
    }
    setPushImage(null)
    // Intentionally not depending on `message` - same keys as before avoid re-hydrating from every refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [message?.$id, message?.providerType, messagePayloadSyncKey])

  useEffect(() => {
    setDraftTargetDetailsById({})
  }, [messageId])

  useEffect(() => {
    setDraftTargetDetailsById((prev) => {
      const next = { ...prev }
      for (const t of targets) {
        next[t.$id] = t
      }
      return next
    })
  }, [targets])

  const [topicsModalOpen, setTopicsModalOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [messageLogsDialogOpen, setMessageLogsDialogOpen] = useState(false)

  // Update email message mutation
  const updateEmailMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !messageId) {
        throw new Error('Project ID and Message ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.messaging.updateEmail({
        messageId,
        subject: emailSubject,
        content: emailContent,
        html: emailHtml,
        topics: Array.from(selectedTopicIds),
        targets: Array.from(selectedTargetIds),
        users: Array.from(selectedUserIds),
        cc: Array.from(ccTargetIds),
        bcc: Array.from(bccTargetIds),
        attachments: attachmentCompoundIds.filter((s) => s.includes(':')),
      })
    },
    onSuccess: async (updatedMessage) => {
      // Keep the API response as cache truth. Refetching getMessage can return a different
      // `data.attachments` shape and the payload sync effect would clear local attachment state.
      queryClient.setQueryData(
        ['message', 'project', projectId, messageId],
        updatedMessage,
      )
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      await queryClient.refetchQueries({
        queryKey: ['message-targets', 'project', projectId, messageId],
      })
      toast.success('Draft updated')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update draft')
    },
  })

  // Update SMS message mutation
  const updateSMSMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !messageId) {
        throw new Error('Project ID and Message ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.messaging.updateSMS({
        messageId,
        content: smsContent,
        topics: Array.from(selectedTopicIds),
        targets: Array.from(selectedTargetIds),
        users: Array.from(selectedUserIds),
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['message', 'project', projectId, messageId],
      })
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      await queryClient.refetchQueries({
        queryKey: ['message-targets', 'project', projectId, messageId],
      })
      toast.success('Message updated successfully')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update message')
    },
  })

  // Update push message mutation
  const updatePushMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !messageId) {
        throw new Error('Project ID and Message ID are required')
      }
      const projectSdk = sdk.forProject(projectId)

      const customData: Record<string, string> = {}
      pushCustomData.forEach(({ key, value }) => {
        if (key.trim()) {
          customData[key] = value
        }
      })

      let image: string | undefined =
        typeof message?.data?.image === 'string'
          ? (message.data.image as string)
          : undefined
      if (pushImage) {
        if (!pushBucketId) {
          throw new Error('Select a storage bucket before uploading an image')
        }
        const uploaded = await projectSdk.storage.createFile({
          bucketId: pushBucketId,
          fileId: ID.unique(),
          file: pushImage,
        })
        image = `${pushBucketId}:${uploaded.$id}`
      }

      const badgeNum =
        pushBadge.trim() === '' ? undefined : parseInt(pushBadge, 10)

      return await projectSdk.messaging.updatePush({
        messageId,
        title: pushTitle,
        body: pushBody,
        data: Object.keys(customData).length > 0 ? customData : undefined,
        image,
        topics: Array.from(selectedTopicIds),
        targets: Array.from(selectedTargetIds),
        users: Array.from(selectedUserIds),
        action: pushAction.trim() || undefined,
        icon: pushIcon.trim() || undefined,
        sound: pushSound.trim() || undefined,
        color: pushColor.trim() || undefined,
        tag: pushTag.trim() || undefined,
        badge: Number.isFinite(badgeNum) ? badgeNum : undefined,
        priority: pushPriority,
        contentAvailable: pushContentAvailable || undefined,
        critical: pushCritical || undefined,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['message', 'project', projectId, messageId],
      })
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      await queryClient.refetchQueries({
        queryKey: ['message-targets', 'project', projectId, messageId],
      })
      setPushImage(null)
      toast.success('Message updated successfully')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update message')
    },
  })

  // Delete message mutation
  const deleteMessageMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !messageId) {
        throw new Error('Project ID and Message ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.delete({ messageId })
    },
    onSuccess: async () => {
      // Refetch messages list so the list view shows updated data (uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['messages', 'project', projectId],
      })
      const statusMessage =
        message?.status === 'draft'
          ? 'The draft message has been deleted'
          : message?.status === 'scheduled'
            ? 'The scheduled message has been deleted, and its delivery was cancelled'
            : 'The message has been deleted'
      toast.success(statusMessage)
      navigate({
        to: '/projects/$projectId/messaging/',
        params: { projectId: projectId! },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete message')
    },
  })

  // Check if topics/targets have changed - must be before early returns
  const hasTopicsChanged = useMemo(() => {
    if (!message) return false
    const currentTopics = new Set(message.topics || [])
    return (
      selectedTopicIds.size !== currentTopics.size ||
      Array.from(selectedTopicIds).some((id) => !currentTopics.has(id)) ||
      Array.from(currentTopics).some((id) => !selectedTopicIds.has(id))
    )
  }, [message, selectedTopicIds])

  const hasTargetsChanged = useMemo(() => {
    if (!message) return false
    const currentTargets = new Set(message.targets || [])
    return (
      selectedTargetIds.size !== currentTargets.size ||
      Array.from(selectedTargetIds).some((id) => !currentTargets.has(id)) ||
      Array.from(currentTargets).some((id) => !selectedTargetIds.has(id))
    )
  }, [message, selectedTargetIds])

  const hasUsersChanged = useMemo(() => {
    if (!message) return false
    const cur = new Set(message.users || [])
    return (
      selectedUserIds.size !== cur.size ||
      Array.from(selectedUserIds).some((id) => !cur.has(id)) ||
      Array.from(cur).some((id) => !selectedUserIds.has(id))
    )
  }, [message, selectedUserIds])

  const parseDataStringIds = (key: string) => {
    if (!message || message.providerType !== 'email') return new Set<string>()
    const raw = message.data as Record<string, unknown>
    return new Set(parseIdArray(raw?.[key]))
  }

  const hasCcChanged = useMemo(() => {
    if (!message || message.providerType !== 'email') return false
    const cur = parseDataStringIds('cc')
    return (
      ccTargetIds.size !== cur.size ||
      Array.from(ccTargetIds).some((id) => !cur.has(id)) ||
      Array.from(cur).some((id) => !ccTargetIds.has(id))
    )
  }, [message, ccTargetIds])

  const hasBccChanged = useMemo(() => {
    if (!message || message.providerType !== 'email') return false
    const cur = parseDataStringIds('bcc')
    return (
      bccTargetIds.size !== cur.size ||
      Array.from(bccTargetIds).some((id) => !cur.has(id)) ||
      Array.from(cur).some((id) => !bccTargetIds.has(id))
    )
  }, [message, bccTargetIds])

  const hasAttachmentsChanged = useMemo(() => {
    if (!message || message.providerType !== 'email') return false
    const cur = parseMessagingEmailAttachments(
      (message.data as Record<string, unknown>)?.attachments,
    )
    if (cur.length !== attachmentCompoundIds.length) return true
    return cur.some((id, i) => id !== attachmentCompoundIds[i])
  }, [message, attachmentCompoundIds])

  const hasEmailChanges = useMemo(() => {
    if (!message || message.providerType !== 'email') return false
    return (
      emailSubject !== (message.data?.subject || '') ||
      emailContent !== (message.data?.content || '') ||
      emailHtml !== (message.data?.html || false) ||
      hasUsersChanged ||
      hasCcChanged ||
      hasBccChanged ||
      hasAttachmentsChanged
    )
  }, [
    message,
    emailSubject,
    emailContent,
    emailHtml,
    hasUsersChanged,
    hasCcChanged,
    hasBccChanged,
    hasAttachmentsChanged,
  ])

  const hasSMSChanges = useMemo(() => {
    if (!message || message.providerType !== 'sms') return false
    return (
      smsContent !== (message.data?.content || '') || hasUsersChanged
    )
  }, [message, smsContent, hasUsersChanged])

  const hasPushChanges = useMemo(() => {
    if (!message || message.providerType !== 'push') return false
    const titleChanged = pushTitle !== (message.data?.title || '')
    const bodyChanged = pushBody !== (message.data?.body || '')
    const imageChanged = pushImage !== null
    const currentData = message.data?.data || {}
    const newData: Record<string, string> = {}
    pushCustomData.forEach(({ key, value }) => {
      if (key.trim()) {
        newData[key] = value
      }
    })
    const dataChanged = JSON.stringify(currentData) !== JSON.stringify(newData)
    const d = message.data as Record<string, unknown>
    const advChanged =
      pushAction !== (typeof d?.action === 'string' ? d.action : '') ||
      pushIcon !== (typeof d?.icon === 'string' ? d.icon : '') ||
      pushSound !== (typeof d?.sound === 'string' ? d.sound : '') ||
      pushColor !== (typeof d?.color === 'string' ? d.color : '') ||
      pushTag !== (typeof d?.tag === 'string' ? d.tag : '') ||
      pushBadge !==
        (typeof d?.badge === 'number'
          ? String(d.badge)
          : typeof d?.badge === 'string'
            ? d.badge
            : '') ||
      pushPriority !==
        (d?.priority === MessagePriority.High
          ? MessagePriority.High
          : MessagePriority.Normal) ||
      pushContentAvailable !== Boolean(d?.contentAvailable) ||
      pushCritical !== Boolean(d?.critical)
    return (
      titleChanged ||
      bodyChanged ||
      imageChanged ||
      dataChanged ||
      advChanged ||
      hasUsersChanged
    )
  }, [
    message,
    pushTitle,
    pushBody,
    pushImage,
    pushCustomData,
    pushAction,
    pushIcon,
    pushSound,
    pushColor,
    pushTag,
    pushBadge,
    pushPriority,
    pushContentAvailable,
    pushCritical,
    hasUsersChanged,
  ])

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/',
      params: { projectId: projectId! },
    })
  }

  const topicsForEstimate = useMemo(() => {
    if (!message) return []
    return message.topics
      .map((id) => topicsById[id])
      .filter((t): t is Models.Topic => Boolean(t))
  }, [message, topicsById])

  const { data: messageLogsData } = useQuery({
    ...messageLogsQueryOptions(projectId, messageId, 0, DEFAULT_PAGE_SIZE),
    enabled: Boolean(projectId && messageId && message),
  })

  const messagingModalInitialSelection = useMemo(() => {
    if (!targetPickerFor) return {}
    const ids =
      targetPickerFor === 'cc'
        ? ccTargetIds
        : targetPickerFor === 'bcc'
          ? bccTargetIds
          : selectedTargetIds
    const map: Record<string, Models.Target | undefined> = {}
    for (const id of ids) {
      map[id] = displayTargetById[id]
    }
    return map
  }, [
    targetPickerFor,
    ccTargetIds,
    bccTargetIds,
    selectedTargetIds,
    displayTargetById,
  ])

  // Get message description for delete dialog
  const getMessageDescription = () => {
    if (message?.providerType === 'email' && message.data?.subject) {
      return message.data.subject
    }
    if (message?.providerType === 'sms' && message.data?.content) {
      return (
        message.data.content.substring(0, 50) +
        (message.data.content.length > 50 ? '...' : '')
      )
    }
    if (message?.providerType === 'push' && message.data?.title) {
      return message.data.title
    }
    return null
  }

  const isMessageSettingsPath = useMemo(
    () => location.pathname.replace(/\/$/, '').endsWith('/settings'),
    [location.pathname],
  )

  const hasComposeSettingsTabs =
    message?.providerType === 'email' ||
    message?.providerType === 'sms' ||
    message?.providerType === 'push'

  const messageDetailTabs: Tab[] | undefined = useMemo(() => {
    if (!hasComposeSettingsTabs || !projectId || !messageId) {
      return undefined
    }
    return [
      {
        id: 'compose',
        label: 'Compose',
        to: '/projects/$projectId/messaging/$messageId',
        params: { projectId, messageId },
      },
      {
        id: 'settings',
        label: 'Settings',
        to: '/projects/$projectId/messaging/$messageId/settings',
        params: { projectId, messageId },
      },
    ]
  }, [hasComposeSettingsTabs, projectId, messageId])

  const messageDetailActiveTab = isMessageSettingsPath ? 'settings' : 'compose'

  const showMessageMain =
    !!message && (!hasComposeSettingsTabs || !isMessageSettingsPath)
  const showMessageSettings =
    !!message && hasComposeSettingsTabs && isMessageSettingsPath

  const messageServiceHeaderTitle = useMemo(() => {
    if (message?.providerType === 'email') {
      const full = emailSubject.trim()
      if (!full) {
        return { label: 'Message', nativeTitle: undefined as string | undefined }
      }
      const label = trimForPageTitle(full)
      return {
        label,
        nativeTitle: label !== full ? full : undefined,
      }
    }
    if (message?.providerType === 'sms') {
      const full = smsContent.trim()
      if (!full) {
        return { label: 'SMS', nativeTitle: undefined as string | undefined }
      }
      const label = trimForPageTitle(full)
      return {
        label,
        nativeTitle: label !== full ? full : undefined,
      }
    }
    if (message?.providerType === 'push') {
      const full = pushTitle.trim()
      if (!full) {
        return { label: 'Push', nativeTitle: undefined as string | undefined }
      }
      const label = trimForPageTitle(full)
      return {
        label,
        nativeTitle: label !== full ? full : undefined,
      }
    }
    return {
      label: 'Message',
      nativeTitle: undefined as string | undefined,
    }
  }, [message?.providerType, emailSubject, smsContent, pushTitle])

  if (!message) {
    if (messageLoading) {
      return null
    }
    return (
      <div className="flex items-center justify-center p-6 py-16">
        <EmptyState
          icon={AlertCircle}
          title="Message not found"
          description="This message may have been deleted or the link is incorrect."
          variant="card"
          iconSize="md"
        />
      </div>
    )
  }

  const getMessageStatusBadge = () => {
    if (message.status === 'sent') {
      return (
        <Badge variant="success" className="text-[10px] shrink-0">
          Sent
        </Badge>
      )
    }
    if (message.status === 'processing') {
      return (
        <div className="flex items-center gap-2">
          <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
          <Badge variant="processing" className="text-[10px] shrink-0">
            Processing
          </Badge>
        </div>
      )
    }
    if (message.status === 'failed') {
      return (
        <Badge variant="error" className="text-[10px] shrink-0">
          Failed
        </Badge>
      )
    }
    if (message.status === 'draft') {
      return (
        <Badge variant="info" className="text-[10px] shrink-0">
          Draft
        </Badge>
      )
    }
    if (message.status === 'scheduled') {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          Scheduled
        </Badge>
      )
    }
    return (
      <Badge variant="info" className="text-[10px] shrink-0 capitalize">
        {message.status}
      </Badge>
    )
  }

  const getMessageTypeIcon = () => {
    if (message.providerType === 'email') return Mail
    if (message.providerType === 'sms') return Phone
    if (message.providerType === 'push') return Bell
    return Mail
  }

  const TypeIcon = getMessageTypeIcon()

  const handleUpdateMessage = () => {
    if (message.providerType === 'email') {
      updateEmailMutation.mutate()
    } else if (message.providerType === 'sms') {
      updateSMSMutation.mutate()
    } else if (message.providerType === 'push') {
      updatePushMutation.mutate()
    }
  }

  const handleAddCustomData = () => {
    if (pushCustomData[pushCustomData.length - 1]?.key) {
      setPushCustomData([...pushCustomData, { key: '', value: '' }])
    }
  }

  const handleRemoveCustomData = (index: number) => {
    if (pushCustomData.length > 1) {
      setPushCustomData(pushCustomData.filter((_, i) => i !== index))
    } else {
      setPushCustomData([{ key: '', value: '' }])
    }
  }

  const handleCustomDataKeyChange = (index: number, key: string) => {
    const newData = [...pushCustomData]
    newData[index].key = key
    setPushCustomData(newData)
  }

  const handleCustomDataValueChange = (index: number, value: string) => {
    const newData = [...pushCustomData]
    newData[index].value = value
    setPushCustomData(newData)
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 flex-1 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span
              className="min-w-0 truncate"
              title={messageServiceHeaderTitle.nativeTitle}
            >
              {messageServiceHeaderTitle.label}
            </span>
            <CopyableId id={message.$id} size="xs" className="shrink-0" />
          </div>
        }
        tabs={messageDetailTabs}
        activeTab={messageDetailTabs ? messageDetailActiveTab : undefined}
        titleRightContent={
          !hasComposeSettingsTabs ? getMessageStatusBadge() : undefined
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Email: compose (metadata and delete live under Settings) */}
          {message.providerType === 'email' && showMessageMain && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <div className="flex items-center justify-between gap-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Content
                  </h3>
                  {getMessageStatusBadge()}
                </div>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4 @container">
                <div className="flex flex-col gap-6 @[600px]:flex-row">
                  <div className="@[600px]:w-64 shrink-0">
                    <p className="text-[13px] text-muted-foreground">
                      Write the subject and body, enable HTML if your content uses tags,
                      add optional CC and BCC targets, and attach files from Storage.
                    </p>
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="w-full min-w-0 space-y-4">
                    <div>
                      <Label
                        htmlFor="email-subject"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Subject
                      </Label>
                      <Input
                        id="email-subject"
                        value={emailSubject}
                        onChange={(e) => setEmailSubject(e.target.value)}
                        disabled={!isDraft}
                        placeholder="Email subject"
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="email-content"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Body
                      </Label>
                      <Textarea
                        id="email-content"
                        value={emailContent}
                        onChange={(e) => setEmailContent(e.target.value)}
                        disabled={!isDraft}
                        placeholder="Email content"
                        className="mt-1.5 min-h-32 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                      />
                    </div>
                    <div className="flex items-center justify-between rounded-md border border-border bg-card p-4">
                      <div>
                        <Label
                          htmlFor="email-html"
                          className="text-[13px] font-medium text-foreground"
                        >
                          HTML mode
                        </Label>
                        <p className="text-[12px] text-muted-foreground mt-0.5">
                          Enable the HTML mode if your message contains HTML
                          tags.
                        </p>
                      </div>
                      <Switch
                        id="email-html"
                        checked={emailHtml}
                        onCheckedChange={setEmailHtml}
                        disabled={!isDraft}
                      />
                    </div>
                    <div className="space-y-3 border-t border-border pt-4 mt-4">
                      <div className="flex items-center justify-between gap-2">
                        <Label className="text-[13px] font-medium text-foreground">
                          CC targets
                        </Label>
                        {isDraft && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 text-[12px]"
                            onClick={() => setTargetPickerFor('cc')}
                          >
                            <Plus className="me-1.5 h-3.5 w-3.5" />
                            Add
                          </Button>
                        )}
                      </div>
                      {ccTargetIds.size > 0 ? (
                        <ul className="space-y-1 text-[13px] text-muted-foreground">
                          {[...ccTargetIds].map((id) => (
                            <li
                              key={id}
                              className="flex items-center justify-between gap-2"
                            >
                              <span className="font-mono text-[12px] break-all">
                                {displayTargetById[id]?.identifier || id}
                              </span>
                              {isDraft && (
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="h-7 w-7 p-0"
                                  onClick={() => {
                                    const n = new Set(ccTargetIds)
                                    n.delete(id)
                                    setCcTargetIds(n)
                                  }}
                                >
                                  <X className="h-4 w-4" />
                                </Button>
                              )}
                            </li>
                          ))}
                        </ul>
                      ) : (
                        <p className="text-[12px] text-muted-foreground">
                          No CC targets
                        </p>
                      )}
                    </div>
                    <div className="space-y-3 border-t border-border pt-4 mt-4">
                      <div className="flex items-center justify-between gap-2">
                        <Label className="text-[13px] font-medium text-foreground">
                          BCC targets
                        </Label>
                        {isDraft && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 text-[12px]"
                            onClick={() => setTargetPickerFor('bcc')}
                          >
                            <Plus className="me-1.5 h-3.5 w-3.5" />
                            Add
                          </Button>
                        )}
                      </div>
                      {bccTargetIds.size > 0 ? (
                        <ul className="space-y-1 text-[13px] text-muted-foreground">
                          {[...bccTargetIds].map((id) => (
                            <li
                              key={id}
                              className="flex items-center justify-between gap-2"
                            >
                              <span className="font-mono text-[12px] break-all">
                                {displayTargetById[id]?.identifier || id}
                              </span>
                              {isDraft && (
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="h-7 w-7 p-0"
                                  onClick={() => {
                                    const n = new Set(bccTargetIds)
                                    n.delete(id)
                                    setBccTargetIds(n)
                                  }}
                                >
                                  <X className="h-4 w-4" />
                                </Button>
                              )}
                            </li>
                          ))}
                        </ul>
                      ) : (
                        <p className="text-[12px] text-muted-foreground">
                          No BCC targets
                        </p>
                      )}
                    </div>
                    <div className="space-y-3 border-t border-border pt-4 mt-4">
                      <div className="flex items-center justify-between gap-2">
                        <Label className="text-[13px] font-medium text-foreground">
                          Attachments
                        </Label>
                        {isDraft && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 text-[12px]"
                            onClick={() => setAttachmentExplorerOpen(true)}
                          >
                            <Plus className="me-1.5 h-3.5 w-3.5" />
                            Add
                          </Button>
                        )}
                      </div>
                      {attachmentCompoundIds.length === 0 ? (
                        <EmptyState
                          icon={Paperclip}
                          title="No attachments"
                          description="Add files from your project's Storage buckets."
                          variant="card"
                          iconSize="md"
                        />
                      ) : projectId ? (
                        <ul className="space-y-2">
                          {attachmentCompoundIds.map((val, idx) => (
                            <EmailAttachmentRow
                              key={`${val}-${idx}`}
                              projectId={projectId}
                              compoundId={val}
                              buckets={buckets}
                              isDraft={isDraft}
                              onRemove={() =>
                                setAttachmentCompoundIds((rows) =>
                                  rows.filter((_, i) => i !== idx),
                                )
                              }
                            />
                          ))}
                        </ul>
                      ) : (
                        <ul className="space-y-2">
                          {attachmentCompoundIds.map((val, idx) => (
                            <li
                              key={`${val}-${idx}`}
                              className="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2"
                            >
                              <span className="min-w-0 flex-1 font-mono text-[12px] text-foreground break-all">
                                {val}
                              </span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                    </div>
                  </div>
                </div>
              </div>
              <MessageComposeCardFooter
                messageStatus={message.status}
                hasContentChanges={hasEmailChanges}
                updatePending={updateEmailMutation.isPending}
                onSchedule={() => setScheduleDialogOpen(true)}
                onUpdateDraft={handleUpdateMessage}
                onSend={() => setSendDialogOpen(true)}
                onCancelSchedule={() => setCancelScheduleOpen(true)}
                onReschedule={() => setScheduleDialogOpen(true)}
                onOpenLogs={() => setMessageLogsDialogOpen(true)}
              />
            </div>
          )}

          {message.providerType === 'sms' && showMessageMain && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <div className="flex items-center justify-between gap-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Content
                  </h3>
                  {getMessageStatusBadge()}
                </div>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4 @container">
                <div className="flex flex-col gap-6 @[600px]:flex-row">
                  <div className="@[600px]:w-64 shrink-0">
                    <p className="text-[13px] text-muted-foreground">
                      Enter the SMS body for this message. Delivery uses topics,
                      users, and targets you add on this page.
                    </p>
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="w-full min-w-0 space-y-4">
                      <div>
                        <Label
                          htmlFor="sms-content"
                          className="text-[13px] font-medium text-foreground"
                        >
                          Body
                        </Label>
                        <Textarea
                          id="sms-content"
                          value={smsContent}
                          onChange={(e) => setSmsContent(e.target.value)}
                          disabled={!isDraft}
                          placeholder="SMS content"
                          className="mt-1.5 min-h-32 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <MessageComposeCardFooter
                messageStatus={message.status}
                hasContentChanges={hasSMSChanges}
                updatePending={updateSMSMutation.isPending}
                onSchedule={() => setScheduleDialogOpen(true)}
                onUpdateDraft={handleUpdateMessage}
                onSend={() => setSendDialogOpen(true)}
                onCancelSchedule={() => setCancelScheduleOpen(true)}
                onReschedule={() => setScheduleDialogOpen(true)}
                onOpenLogs={() => setMessageLogsDialogOpen(true)}
              />
            </div>
          )}

          {message.providerType === 'push' && showMessageMain && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <div className="flex items-center justify-between gap-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Content
                  </h3>
                  {getMessageStatusBadge()}
                </div>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4 @container">
                <div className="flex flex-col gap-6 @[600px]:flex-row">
                  <div className="@[600px]:w-64 shrink-0">
                    <p className="text-[13px] text-muted-foreground">
                      Build title, body, optional image and custom data. Advanced fields
                      control action, appearance, and iOS-specific options.
                    </p>
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="w-full min-w-0 space-y-4">
                    <div>
                      <Label
                        htmlFor="push-title"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Title
                      </Label>
                      <Input
                        id="push-title"
                        value={pushTitle}
                        onChange={(e) => setPushTitle(e.target.value)}
                        disabled={!isDraft}
                        placeholder="Notification title"
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="push-body"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Body
                      </Label>
                      <Textarea
                        id="push-body"
                        value={pushBody}
                        onChange={(e) => setPushBody(e.target.value)}
                        disabled={!isDraft}
                        placeholder="Notification body"
                        className="mt-1.5 min-h-32 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                      />
                    </div>
                    <div>
                      <Label className="text-[13px] font-medium text-foreground">
                        Media (Optional)
                      </Label>
                      <div className="mt-1.5">
                        <input
                          type="file"
                          accept="image/*"
                          disabled={!isDraft}
                          onChange={(e) => {
                            const file = e.target.files?.[0]
                            if (file) {
                              setPushImage(file)
                            }
                          }}
                          className="text-[13px]"
                        />
                        {pushImage && (
                          <p className="mt-1.5 text-[12px] text-muted-foreground">
                            Selected: {pushImage.name}
                          </p>
                        )}
                        {!pushImage && message.data?.image && (
                          <p className="mt-1.5 text-[12px] text-muted-foreground">
                            Current image: {message.data.image}
                          </p>
                        )}
                      </div>
                    </div>
                    {isDraft && buckets.length > 0 && (
                      <div className="space-y-2">
                        <Label
                          htmlFor="push-bucket"
                          className="text-[13px] font-medium text-foreground"
                        >
                          Upload bucket
                        </Label>
                        <Select
                          value={pushBucketId || undefined}
                          onValueChange={setPushBucketId}
                        >
                          <SelectTrigger id="push-bucket" className="h-9">
                            <SelectValue placeholder="Select bucket for image upload" />
                          </SelectTrigger>
                          <SelectContent>
                            {buckets.map((b) => (
                              <SelectItem key={b.$id} value={b.$id}>
                                {b.name}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <p className="text-[12px] text-muted-foreground">
                          Uploading replaces the push image with a Storage file
                          reference (bucket:file).
                        </p>
                      </div>
                    )}
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <Label className="text-[13px] font-medium text-foreground">
                          Custom Data
                        </Label>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 text-[12px]"
                          onClick={handleAddCustomData}
                          disabled={
                            !isDraft ||
                            !pushCustomData[pushCustomData.length - 1]?.key
                          }
                        >
                          <Plus className="me-1 h-3.5 w-3.5" />
                          Add
                        </Button>
                      </div>
                      <div className="space-y-2">
                        {pushCustomData.map((item, index) => (
                          <div key={index} className="flex items-center gap-2">
                            <Input
                              placeholder="Enter key"
                              value={item.key}
                              onChange={(e) =>
                                handleCustomDataKeyChange(index, e.target.value)
                              }
                              disabled={!isDraft}
                              className="h-9 text-[13px]"
                            />
                            <Input
                              placeholder="Enter value"
                              value={item.value}
                              onChange={(e) =>
                                handleCustomDataValueChange(
                                  index,
                                  e.target.value,
                                )
                              }
                              disabled={!isDraft}
                              className="h-9 text-[13px]"
                            />
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-9 w-9 p-0"
                              onClick={() => handleRemoveCustomData(index)}
                              disabled={
                                !isDraft ||
                                (pushCustomData.length === 1 &&
                                  !item.key &&
                                  !item.value)
                              }
                            >
                              <X className="h-4 w-4" />
                            </Button>
                          </div>
                        ))}
                      </div>
                    </div>
                    <div className="border-t border-border pt-4 space-y-3">
                      <h4 className="text-[13px] font-semibold text-foreground">
                        Advanced
                      </h4>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                          <Label htmlFor="push-action" className="text-[12px]">
                            Action
                          </Label>
                          <Input
                            id="push-action"
                            value={pushAction}
                            onChange={(e) => setPushAction(e.target.value)}
                            disabled={!isDraft}
                            className="mt-1 h-9 text-[13px]"
                          />
                        </div>
                        <div>
                          <Label htmlFor="push-icon" className="text-[12px]">
                            Icon
                          </Label>
                          <Input
                            id="push-icon"
                            value={pushIcon}
                            onChange={(e) => setPushIcon(e.target.value)}
                            disabled={!isDraft}
                            className="mt-1 h-9 text-[13px]"
                          />
                        </div>
                        <div>
                          <Label htmlFor="push-sound" className="text-[12px]">
                            Sound
                          </Label>
                          <Input
                            id="push-sound"
                            value={pushSound}
                            onChange={(e) => setPushSound(e.target.value)}
                            disabled={!isDraft}
                            className="mt-1 h-9 text-[13px]"
                          />
                        </div>
                        <div>
                          <Label htmlFor="push-color" className="text-[12px]">
                            Color
                          </Label>
                          <Input
                            id="push-color"
                            value={pushColor}
                            onChange={(e) => setPushColor(e.target.value)}
                            disabled={!isDraft}
                            className="mt-1 h-9 text-[13px]"
                          />
                        </div>
                        <div>
                          <Label htmlFor="push-tag" className="text-[12px]">
                            Tag
                          </Label>
                          <Input
                            id="push-tag"
                            value={pushTag}
                            onChange={(e) => setPushTag(e.target.value)}
                            disabled={!isDraft}
                            className="mt-1 h-9 text-[13px]"
                          />
                        </div>
                        <div>
                          <Label htmlFor="push-badge" className="text-[12px]">
                            Badge (iOS)
                          </Label>
                          <Input
                            id="push-badge"
                            value={pushBadge}
                            onChange={(e) => setPushBadge(e.target.value)}
                            disabled={!isDraft}
                            className="mt-1 h-9 text-[13px]"
                          />
                        </div>
                      </div>
                      <div>
                        <Label className="text-[12px]">Priority</Label>
                        <Select
                          value={pushPriority}
                          onValueChange={(v) =>
                            setPushPriority(v as MessagePriority)
                          }
                          disabled={!isDraft}
                        >
                          <SelectTrigger className="mt-1 h-9">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value={MessagePriority.Normal}>
                              Normal
                            </SelectItem>
                            <SelectItem value={MessagePriority.High}>
                              High
                            </SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="flex flex-col gap-3 rounded-md border border-border bg-card p-4">
                        <div className="flex items-center justify-between">
                          <div>
                            <Label
                              htmlFor="push-bg"
                              className="text-[13px] font-medium"
                            >
                              Content available (iOS)
                            </Label>
                            <p className="text-[12px] text-muted-foreground mt-0.5">
                              Deliver in the background when possible.
                            </p>
                          </div>
                          <Switch
                            id="push-bg"
                            checked={pushContentAvailable}
                            onCheckedChange={setPushContentAvailable}
                            disabled={!isDraft}
                          />
                        </div>
                        <div className="flex items-center justify-between">
                          <div>
                            <Label
                              htmlFor="push-critical"
                              className="text-[13px] font-medium"
                            >
                              Critical (iOS)
                            </Label>
                            <p className="text-[12px] text-muted-foreground mt-0.5">
                              Requires critical notification entitlement.
                            </p>
                          </div>
                          <Switch
                            id="push-critical"
                            checked={pushCritical}
                            onCheckedChange={setPushCritical}
                            disabled={!isDraft}
                          />
                        </div>
                      </div>
                    </div>
                    </div>
                  </div>
                </div>
              </div>
              <MessageComposeCardFooter
                messageStatus={message.status}
                hasContentChanges={hasPushChanges}
                updatePending={updatePushMutation.isPending}
                onSchedule={() => setScheduleDialogOpen(true)}
                onUpdateDraft={handleUpdateMessage}
                onSend={() => setSendDialogOpen(true)}
                onCancelSchedule={() => setCancelScheduleOpen(true)}
                onReschedule={() => setScheduleDialogOpen(true)}
                onOpenLogs={() => setMessageLogsDialogOpen(true)}
              />
            </div>
          )}

          {showMessageMain && (
          <>
          {/* Update Topics Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex items-center justify-between gap-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Topics
                </h3>
                {isDraft && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 shrink-0 text-[12px]"
                    onClick={() => setTopicsModalOpen(true)}
                  >
                    <Plus className="me-1.5 h-3.5 w-3.5" />
                    Add
                  </Button>
                )}
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex flex-col gap-6 @[600px]:flex-row">
                <div className="@[600px]:w-64 shrink-0">
                  <p className="text-[13px] text-muted-foreground">
                    Link topics so this message reaches their subscribers when you
                    send. Subscriber counts reflect targets registered on each topic.
                  </p>
                </div>
                <div className="flex-1 min-w-0">
                  {selectedTopicIds.size > 0 ? (
                    <div className="rounded-lg border border-border bg-card overflow-hidden">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              Topic name
                            </TableHead>
                            {isDraft && (
                              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]" />
                            )}
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {Array.from(selectedTopicIds).map((topicId) => {
                            const topic = topicsById[topicId]
                            const totalSubscribers = topic
                              ? (topic.emailTotal || 0) +
                                (topic.smsTotal || 0) +
                                (topic.pushTotal || 0)
                              : 0
                            return (
                              <TableRow
                                key={topicId}
                                className="border-b border-border/50"
                              >
                                <TableCell className="px-4 py-3">
                                  {topic ? (
                                    <div>
                                      <p className="text-[13px] font-medium text-foreground">
                                        {topic.name} ({totalSubscribers} targets)
                                      </p>
                                      <CopyableId id={topic.$id} size="xs" />
                                    </div>
                                  ) : (
                                    <div>
                                      <p className="text-[13px] text-muted-foreground">
                                        Topic not found
                                      </p>
                                      <CopyableId id={topicId} size="xs" />
                                    </div>
                                  )}
                                </TableCell>
                                {isDraft && (
                                  <TableCell className="px-4 py-3 text-end">
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      className="h-7 w-7 p-0"
                                      onClick={() => {
                                        const newSelected = new Set(
                                          selectedTopicIds,
                                        )
                                        newSelected.delete(topicId)
                                        setSelectedTopicIds(newSelected)
                                      }}
                                    >
                                      <X className="h-4 w-4" />
                                    </Button>
                                  </TableCell>
                                )}
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                  ) : isDraft ? (
                    <EmptyState
                      icon={Hash}
                      title="No topics yet"
                      description="Select topics using Add to reach their subscribers when you send."
                      variant="card"
                      iconSize="md"
                    />
                  ) : (
                    <EmptyState
                      icon={Hash}
                      title="No topics"
                      description="This message has no linked topics."
                      variant="card"
                      iconSize="md"
                    />
                  )}
                </div>
              </div>
            </div>
            {isDraft && (
              <div className="flex justify-end px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    !hasTopicsChanged ||
                    updateEmailMutation.isPending ||
                    updateSMSMutation.isPending ||
                    updatePushMutation.isPending
                  }
                  onClick={handleUpdateMessage}
                >
                  Update
                </Button>
              </div>
            )}
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex items-center justify-between gap-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Users
                </h3>
                {isDraft && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 shrink-0 text-[12px]"
                    onClick={() => setRecipientUsersModalOpen(true)}
                  >
                    <Plus className="me-1.5 h-3.5 w-3.5" />
                    Add
                  </Button>
                )}
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex flex-col gap-6 @[600px]:flex-row">
                <div className="@[600px]:w-64 shrink-0">
                  <p className="text-[13px] text-muted-foreground">
                    Add project users to deliver to every matching channel target on
                    their account (email, SMS, or push), alongside any topics you
                    selected.
                  </p>
                </div>
                <div className="flex-1 min-w-0">
                  {selectedUserIds.size > 0 ? (
                    <div className="rounded-lg border border-border bg-card overflow-hidden">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              User
                            </TableHead>
                            {isDraft && (
                              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]" />
                            )}
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {[...selectedUserIds].map((uid) => {
                            const u = usersById[uid]
                            return (
                              <TableRow key={uid} className="border-b border-border/50">
                                <TableCell className="px-4 py-3">
                                  <p className="text-[13px] font-medium text-foreground">
                                    {u?.name || u?.email || uid}
                                  </p>
                                  <CopyableId id={uid} size="xs" />
                                </TableCell>
                                {isDraft && (
                                  <TableCell className="px-4 py-3 text-end">
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      className="h-7 w-7 p-0"
                                      onClick={() => {
                                        const n = new Set(selectedUserIds)
                                        n.delete(uid)
                                        setSelectedUserIds(n)
                                      }}
                                    >
                                      <X className="h-4 w-4" />
                                    </Button>
                                  </TableCell>
                                )}
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                  ) : isDraft ? (
                    <EmptyState
                      icon={Users}
                      title="No users yet"
                      description="Choose users using Add to target every matching channel target for each user."
                      variant="card"
                      iconSize="md"
                    />
                  ) : (
                    <EmptyState
                      icon={Users}
                      title="No users"
                      description="This message has no selected users."
                      variant="card"
                      iconSize="md"
                    />
                  )}
                </div>
              </div>
            </div>
            {isDraft && (
              <div className="flex justify-end px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    !hasUsersChanged ||
                    updateEmailMutation.isPending ||
                    updateSMSMutation.isPending ||
                    updatePushMutation.isPending
                  }
                  onClick={handleUpdateMessage}
                >
                  Update
                </Button>
              </div>
            )}
          </div>

          {/* Update Targets Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex items-center justify-between gap-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Targets
                </h3>
                {isDraft && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 shrink-0 text-[12px]"
                    onClick={() => setTargetPickerFor('primary')}
                  >
                    <Plus className="me-1.5 h-3.5 w-3.5" />
                    Add
                  </Button>
                )}
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4 @container">
              <div className="flex flex-col gap-6 @[600px]:flex-row">
                <div className="@[600px]:w-64 shrink-0">
                  <p className="text-[13px] text-muted-foreground">
                    Pick specific channel targets for this message. Targets must match
                    the message provider (email, SMS, or push).
                  </p>
                </div>
                <div className="flex-1 min-w-0">
                  {selectedTargetIds.size > 0 ? (
                    <div className="rounded-lg border border-border bg-card overflow-hidden">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              Target
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              User
                            </TableHead>
                            {isDraft && (
                              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]" />
                            )}
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {Array.from(selectedTargetIds).map((targetId) => {
                            const target = displayTargetById[targetId]
                            const user = target?.userId
                              ? usersById[target.userId]
                              : null
                            return (
                              <TableRow
                                key={targetId}
                                className="border-b border-border/50"
                              >
                                <TableCell className="px-4 py-3">
                                  {target ? (
                                    target.providerType === 'push' ? (
                                      <span className="text-[13px] text-foreground">
                                        {target.name || target.identifier}
                                      </span>
                                    ) : (
                                      <span className="text-[13px] text-foreground">
                                        {target.identifier}
                                      </span>
                                    )
                                  ) : (
                                    <div className="space-y-1">
                                      <CopyableId id={targetId} size="xs" />
                                      <p className="text-[12px] text-muted-foreground">
                                        Loading target details…
                                      </p>
                                    </div>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {user ? (
                                    <span className="text-[13px] text-muted-foreground">
                                      {user.name || user.email}
                                    </span>
                                  ) : (
                                    <span className="text-[13px] text-muted-foreground">
                                      {target?.userId ? (
                                        <CopyableId id={target.userId} size="xs" />
                                      ) : (
                                        '-'
                                      )}
                                    </span>
                                  )}
                                </TableCell>
                                {isDraft && (
                                  <TableCell className="px-4 py-3 text-end">
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      className="h-7 w-7 p-0"
                                      onClick={() => {
                                        const newSelected = new Set(
                                          selectedTargetIds,
                                        )
                                        newSelected.delete(targetId)
                                        setSelectedTargetIds(newSelected)
                                        setDraftTargetDetailsById((prev) => {
                                          const next = { ...prev }
                                          delete next[targetId]
                                          return next
                                        })
                                      }}
                                    >
                                      <X className="h-4 w-4" />
                                    </Button>
                                  </TableCell>
                                )}
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                  ) : isDraft ? (
                    <EmptyState
                      icon={Target}
                      title="No targets yet"
                      description="Select targets using Add to deliver this message on the matching channel."
                      variant="card"
                      iconSize="md"
                    />
                  ) : (
                    <EmptyState
                      icon={Target}
                      title="No targets"
                      description="No targets have been selected for this message."
                      variant="card"
                      iconSize="md"
                    />
                  )}
                </div>
              </div>
            </div>
            {isDraft && (
              <div className="flex justify-end px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    !hasTargetsChanged ||
                    updateEmailMutation.isPending ||
                    updateSMSMutation.isPending ||
                    updatePushMutation.isPending
                  }
                  onClick={handleUpdateMessage}
                >
                  Update
                </Button>
              </div>
            )}
          </div>
          </>
          )}

          {showMessageSettings && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Details
                    </h3>
                    <p className="text-[13px] text-muted-foreground mt-2">
                      Message ID and delivery timestamps.
                    </p>
                  </div>
                  <div className="shrink-0 pt-0.5">{getMessageStatusBadge()}</div>
                </div>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="space-y-4">
                  <div className="min-w-0">
                    <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      Message ID
                    </p>
                    <CopyableId id={message.$id} size="sm" />
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                        Created
                      </p>
                      {message.$createdAt ? (
                        <DateTooltip
                          date={message.$createdAt}
                          showFormattedDate
                          className="text-[13px] text-foreground"
                        />
                      ) : (
                        <span className="text-[13px] text-muted-foreground/50 italic">
                          N/A
                        </span>
                      )}
                    </div>
                    <div>
                      <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                        Updated
                      </p>
                      <DateTooltip
                        date={message.$updatedAt || message.$createdAt}
                        showFormattedDate
                        className="text-[13px] text-foreground"
                      />
                    </div>
                  </div>
                  {(message.scheduledAt || message.deliveredAt) && (
                    <div className="grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
                      {message.scheduledAt && (
                        <div>
                          <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                            Scheduled for
                          </p>
                          <DateTooltip
                            date={message.scheduledAt}
                            showFormattedDate
                            className="text-[13px] text-foreground"
                          />
                        </div>
                      )}
                      {message.deliveredAt && (
                        <div>
                          <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                            Sent
                          </p>
                          <DateTooltip
                            date={message.deliveredAt}
                            showFormattedDate
                            className="text-[13px] text-foreground"
                          />
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* Delete Message Card */}
          {message.status !== 'processing' && showMessageSettings && (
            <div className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Delete message
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  Permanently delete this message. This action cannot be undone.
                  {message.status === 'scheduled' && (
                    <span className="block mt-1">
                      This is a scheduled message. Deleting it will result in
                      the cancellation of its delivery.
                    </span>
                  )}
                </p>
              </div>
              <div className="border-t border-red-500/20" />
              <div className="px-6 py-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <TypeIcon className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-medium text-foreground truncate">
                      {getMessageDescription() || 'Message'}
                    </p>
                    {message.$updatedAt && (
                      <p className="text-[12px] text-muted-foreground">
                        Last updated: {formatDateTime(message.$updatedAt)}
                      </p>
                    )}
                  </div>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-red-500/20 bg-destructive/5">
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setDeleteDialogOpen(true)}
                  disabled={deleteMessageMutation.isPending}
                >
                  <Trash2 className="me-1.5 h-4 w-4" />
                  Delete
                </Button>
              </div>
            </div>
          )}
        </div>

        {/* Topics Selection Modal */}
        <TopicsSelectionModal
          open={topicsModalOpen}
          onOpenChange={setTopicsModalOpen}
          onSelect={(topicIds) => {
            setSelectedTopicIds(new Set(topicIds))
            setTopicsModalOpen(false)
          }}
          projectId={projectId}
          providerType={message.providerType}
          existingTopicIds={selectedTopicIds}
        />

        <MessagingTargetsModal
          open={targetPickerFor !== null}
          onOpenChange={(o) => {
            if (!o) setTargetPickerFor(null)
          }}
          title={
            targetPickerFor === 'cc'
              ? 'Select CC targets'
              : targetPickerFor === 'bcc'
                ? 'Select BCC targets'
                : 'Select targets'
          }
          description={
            targetPickerFor === 'cc' || targetPickerFor === 'bcc'
              ? 'Choose email targets for copy. Targets must match the email channel.'
              : 'Choose user targets for this message. Each user can have multiple targets per channel.'
          }
          projectId={projectId}
          providerType={
            targetPickerFor === 'cc' || targetPickerFor === 'bcc'
              ? 'email'
              : message.providerType
          }
          initialSelectedById={messagingModalInitialSelection}
          onConfirm={(selectedById) => {
            const ids = new Set(Object.keys(selectedById))
            if (targetPickerFor === 'cc') setCcTargetIds(ids)
            else if (targetPickerFor === 'bcc') setBccTargetIds(ids)
            else {
              setSelectedTargetIds(ids)
              setDraftTargetDetailsById((prev) => ({ ...prev, ...selectedById }))
            }
            setTargetPickerFor(null)
          }}
        />

        {projectId ? (
          <>
            <MessageSendDialog
              open={sendDialogOpen}
              onOpenChange={setSendDialogOpen}
              projectId={projectId}
              message={message}
              topics={topicsForEstimate}
              onSuccess={() => void refetchMessage()}
            />
            <MessageScheduleDialog
              open={scheduleDialogOpen}
              onOpenChange={setScheduleDialogOpen}
              projectId={projectId}
              message={message}
              topics={topicsForEstimate}
              onSuccess={() => void refetchMessage()}
            />
            <MessageCancelScheduleDialog
              open={cancelScheduleOpen}
              onOpenChange={setCancelScheduleOpen}
              projectId={projectId}
              message={message}
              onSuccess={() => void refetchMessage()}
            />
            <MessagingRecipientUsersModal
              open={recipientUsersModalOpen}
              onOpenChange={setRecipientUsersModalOpen}
              projectId={projectId}
              existingUserIds={selectedUserIds}
              onConfirm={(ids) => setSelectedUserIds(new Set(ids))}
            />
            <StorageFileExplorerDialog
              open={attachmentExplorerOpen}
              onOpenChange={setAttachmentExplorerOpen}
              projectId={projectId}
              title="Add attachment"
              description="Pick a bucket and file from Storage. It will be referenced as bucketId:fileId on the message."
              confirmLabel="Add"
              onConfirm={(sel) => {
                const compound = `${sel.bucketId}:${sel.fileId}`
                if (attachmentCompoundIds.includes(compound)) {
                  toast.error('This file is already attached')
                  return false
                }
                setAttachmentCompoundIds((rows) => [...rows, compound])
              }}
            />
          </>
        ) : null}

        {/* Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>Delete message</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete{' '}
                {getMessageDescription()
                  ? `"${getMessageDescription()}"`
                  : 'this message'}
                ? {message.status === 'draft' && 'This action is irreversible.'}
                {message.status === 'scheduled' &&
                  'This is a scheduled message. Deleting it will result in the cancellation of its delivery. This action is irreversible.'}
                {message.status === 'sent' &&
                  'The message has already been sent. After deleting it, you will no longer see it here.'}
                {message.status === 'failed' &&
                  'The message has been sent with errors. After deleting it, you will no longer see it here.'}
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteMessageMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteMessageMutation.mutate()}
                disabled={deleteMessageMutation.isPending}
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog
          open={messageLogsDialogOpen}
          onOpenChange={setMessageLogsDialogOpen}
        >
          <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] flex flex-col">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>Message logs</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Audit log entries for this message.
                {message.status === 'failed'
                  ? ' When delivery fails, API errors are included below when available.'
                  : ''}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-0 flex-1 min-h-0 overflow-y-auto space-y-6">
              {message.deliveryErrors && message.deliveryErrors.length > 0 ? (
                <div>
                  <h4 className="text-[13px] font-semibold text-foreground mb-2">
                    Delivery errors
                  </h4>
                  <pre className="max-h-[220px] overflow-auto rounded-md border border-border bg-muted/30 p-4 text-[12px]">
                    {JSON.stringify(message.deliveryErrors, null, 2)}
                  </pre>
                </div>
              ) : null}
              <div>
                <h4 className="text-[13px] font-semibold text-foreground mb-2">
                  Log entries
                </h4>
                <MessagingLogsTable
                  logs={messageLogsData?.logs ?? []}
                  emptyLabel="No log entries returned for this message."
                />
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setMessageLogsDialogOpen(false)}
              >
                Close
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}

// Topics Selection Modal
function TopicsSelectionModal({
  open,
  onOpenChange,
  onSelect,
  projectId,
  providerType,
  existingTopicIds,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (topicIds: string[]) => void
  projectId?: string
  providerType?: string
  existingTopicIds: Set<string>
}) {
  const [search, setSearch] = useState('')
  const [selectedTopicIds, setSelectedTopicIds] = useState<Set<string>>(
    new Set(),
  )
  const [page, setPage] = useState(0)
  const pageSize = 25

  const { topics, isLoading } = useProjectTopics(
    projectId || null,
    page,
    pageSize,
    search,
  )

  // Filter topics by provider type if specified
  const filteredTopics = useMemo(() => {
    if (!providerType) return topics
    return topics.filter((topic) => {
      // Topic should support the provider type
      if (providerType === 'email') return (topic.emailTotal || 0) > 0
      if (providerType === 'sms') return (topic.smsTotal || 0) > 0
      if (providerType === 'push') return (topic.pushTotal || 0) > 0
      return true
    })
  }, [topics, providerType])

  const handleToggleTopic = (topicId: string) => {
    const newSelected = new Set(selectedTopicIds)
    if (newSelected.has(topicId)) {
      newSelected.delete(topicId)
    } else {
      newSelected.add(topicId)
    }
    setSelectedTopicIds(newSelected)
  }

  const handleAdd = () => {
    const topicIds = Array.from(selectedTopicIds)
    if (topicIds.length > 0) {
      // Merge with existing topics
      const merged = new Set([...existingTopicIds, ...topicIds])
      onSelect(Array.from(merged))
      setSelectedTopicIds(new Set())
      setSearch('')
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    setSelectedTopicIds(new Set())
    setSearch('')
    onOpenChange(false)
  }

  useEffect(() => {
    if (!open) {
      setSelectedTopicIds(new Set())
      setSearch('')
      setPage(0)
    }
  }, [open])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[80dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>Select topics</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Choose one or more topics to send this message to.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 flex-1 overflow-hidden flex flex-col">
          <div className="space-y-4">
            <Input
              placeholder="Search topics..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(0)
              }}
              className="h-9"
            />

            <div className="flex-1 overflow-y-auto space-y-1 min-h-0">
              {isLoading ? (
                <div className="text-center py-8 text-sm text-muted-foreground">
                  Loading topics...
                </div>
              ) : filteredTopics.length === 0 ? (
                <EmptyState
                  icon={Hash}
                  isEmpty={!search}
                  hasFilters={!!search}
                  className="py-8"
                />
              ) : (
                filteredTopics.map((topic) => {
                  const isSelected = selectedTopicIds.has(topic.$id)
                  const isAlreadyAdded = existingTopicIds.has(topic.$id)
                  const totalSubscribers =
                    (topic.emailTotal || 0) +
                    (topic.smsTotal || 0) +
                    (topic.pushTotal || 0)

                  return (
                    <div
                      key={topic.$id}
                      onClick={() =>
                        !isAlreadyAdded && handleToggleTopic(topic.$id)
                      }
                      className={cn(
                        'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                        isAlreadyAdded
                          ? 'border-border bg-muted/30 opacity-50 cursor-not-allowed'
                          : isSelected
                            ? 'border-primary bg-primary/5 cursor-pointer'
                            : 'border-border hover:bg-muted/50 cursor-pointer',
                      )}
                    >
                      <Checkbox
                        checked={isSelected}
                        onCheckedChange={() => {
                          if (!isAlreadyAdded) handleToggleTopic(topic.$id)
                        }}
                        disabled={isAlreadyAdded}
                        onClick={(e) => e.stopPropagation()}
                        className="cursor-pointer"
                      />
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">
                          {topic.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {totalSubscribers} subscriber
                          {totalSubscribers !== 1 ? 's' : ''}
                        </p>
                      </div>
                    </div>
                  )
                })
              )}
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            Cancel
          </Button>
          <Button onClick={handleAdd} disabled={selectedTopicIds.size === 0}>
            Add {selectedTopicIds.size > 0 ? `(${selectedTopicIds.size})` : ''}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
