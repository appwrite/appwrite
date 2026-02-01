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
  Send,
  AlertCircle,
  Users,
  Hash,
} from 'lucide-react'
import {
  useMessage,
  useMessageTargets,
  useTopic,
  fetchTopic,
  fetchUser,
  useProject,
  useProjectTopics,
  useProjectUsers,
} from '@/lib/react-query/hooks'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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
import { formatDateTime } from '@/lib/date-utils'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'

export function View() {
  const { projectId, messageId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  // Fetch message (updated via realtime when backend emits message events)
  const {
    data: message,
    isLoading: messageLoading,
  } = useMessage(projectId, messageId)

  // Fetch project to get project name
  const { project } = useProject(projectId)

  // Fetch message targets
  const { data: targetsData, isLoading: targetsLoading } = useMessageTargets(
    projectId,
    messageId,
    0,
    100,
  ) // Fetch all targets

  const targets = useMemo(
    () => targetsData?.targets || [],
    [targetsData?.targets],
  )

  // Fetch all topics in parallel using Promise.allSettled
  const [topicsById, setTopicsById] = useState<Record<string, Models.Topic>>({})
  const [topicsLoading, setTopicsLoading] = useState(true)

  useEffect(() => {
    if (!projectId || !message?.topics || message.topics.length === 0) {
      setTopicsLoading(false)
      return
    }

    setTopicsLoading(true)
    Promise.allSettled(
      message.topics.map((topicId) => fetchTopic(projectId, topicId)),
    ).then((results) => {
      const topicsMap: Record<string, Models.Topic> = {}
      results.forEach((result, index) => {
        if (result.status === 'fulfilled') {
          const topic = result.value
          topicsMap[topic.$id] = topic
        }
      })
      setTopicsById(topicsMap)
      setTopicsLoading(false)
    })
  }, [projectId, message?.topics])

  // Fetch user details for targets and recipients
  const [usersById, setUsersById] = useState<
    Record<string, Models.User | null>
  >({})
  const [usersLoading, setUsersLoading] = useState(true)

  useEffect(() => {
    if (!projectId || targets.length === 0) {
      setUsersLoading(false)
      return
    }

    setUsersLoading(true)
    const userIds = new Set<string>()

    // Collect all user IDs from targets
    targets.forEach((target) => {
      if (target.userId) {
        userIds.add(target.userId)
      }
    })

    // Collect user IDs from message recipients if available
    if (message?.users) {
      message.users.forEach((userId) => {
        userIds.add(userId)
      })
    }

    if (userIds.size === 0) {
      setUsersLoading(false)
      return
    }

    Promise.allSettled(
      Array.from(userIds).map((userId) =>
        fetchUser(projectId, userId).catch(() => null),
      ),
    ).then((results) => {
      const usersMap: Record<string, Models.User | null> = {}
      Array.from(userIds).forEach((userId, index) => {
        const result = results[index]
        usersMap[userId] = result.status === 'fulfilled' ? result.value : null
      })
      setUsersById(usersMap)
      setUsersLoading(false)
    })
  }, [projectId, targets, message?.users])

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

  // Initialize form state when message loads
  useEffect(() => {
    if (message) {
      if (message.providerType === 'email') {
        setEmailSubject(message.data?.subject || '')
        setEmailContent(message.data?.content || '')
        setEmailHtml(message.data?.html || false)
      } else if (message.providerType === 'sms') {
        setSmsContent(message.data?.content || '')
      } else if (message.providerType === 'push') {
        setPushTitle(message.data?.title || '')
        setPushBody(message.data?.body || '')
        // Parse custom data object into key-value pairs
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
    }
  }, [message])

  // Selected topics and targets for updates
  const [selectedTopicIds, setSelectedTopicIds] = useState<Set<string>>(
    new Set(),
  )
  const [selectedTargetIds, setSelectedTargetIds] = useState<Set<string>>(
    new Set(),
  )

  // Initialize selected items from message
  useEffect(() => {
    if (message) {
      setSelectedTopicIds(new Set(message.topics || []))
    }
  }, [message?.topics])

  // Initialize selected targets separately to avoid infinite loop
  const targetIds = useMemo(() => targets.map((t) => t.$id), [targets])
  useEffect(() => {
    if (targetIds.length > 0) {
      setSelectedTargetIds(new Set(targetIds))
    }
  }, [targetIds.join(',')]) // Use join to create stable dependency

  const [topicsModalOpen, setTopicsModalOpen] = useState(false)
  const [targetsModalOpen, setTargetsModalOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [errorDetailsDialogOpen, setErrorDetailsDialogOpen] = useState(false)

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
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['message', 'project', projectId, messageId],
      })
      toast.success('Message updated successfully')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update message')
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
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['message', 'project', projectId, messageId],
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

      // Filter out empty keys from custom data
      const customData: Record<string, string> = {}
      pushCustomData.forEach(({ key, value }) => {
        if (key.trim()) {
          customData[key] = value
        }
      })

      // TODO: Handle image upload (need to upload to storage first, then get compound ID)
      const image = pushImage ? undefined : message?.data?.image

      return await projectSdk.messaging.updatePush({
        messageId,
        title: pushTitle,
        body: pushBody,
        data: Object.keys(customData).length > 0 ? customData : undefined,
        image,
        topics: Array.from(selectedTopicIds),
        targets: Array.from(selectedTargetIds),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['message', 'project', projectId, messageId],
      })
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

  // Check if message content has changed - must be before early returns
  const hasEmailChanges = useMemo(() => {
    if (!message || message.providerType !== 'email') return false
    return (
      emailSubject !== (message.data?.subject || '') ||
      emailContent !== (message.data?.content || '') ||
      emailHtml !== (message.data?.html || false)
    )
  }, [message, emailSubject, emailContent, emailHtml])

  const hasSMSChanges = useMemo(() => {
    if (!message || message.providerType !== 'sms') return false
    return smsContent !== (message.data?.content || '')
  }, [message, smsContent])

  const hasPushChanges = useMemo(() => {
    if (!message || message.providerType !== 'push') return false
    const titleChanged = pushTitle !== (message.data?.title || '')
    const bodyChanged = pushBody !== (message.data?.body || '')
    const imageChanged = pushImage !== null
    // Check custom data changes
    const currentData = message.data?.data || {}
    const newData: Record<string, string> = {}
    pushCustomData.forEach(({ key, value }) => {
      if (key.trim()) {
        newData[key] = value
      }
    })
    const dataChanged = JSON.stringify(currentData) !== JSON.stringify(newData)
    return titleChanged || bodyChanged || imageChanged || dataChanged
  }, [message, pushTitle, pushBody, pushImage, pushCustomData])

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
    const currentTargets = new Set(targets.map((t) => t.$id))
    return (
      selectedTargetIds.size !== currentTargets.size ||
      Array.from(selectedTargetIds).some((id) => !currentTargets.has(id)) ||
      Array.from(currentTargets).some((id) => !selectedTargetIds.has(id))
    )
  }, [targets, selectedTargetIds])

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/',
      params: { projectId: projectId! },
    })
  }

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

  if (messageLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading message...
          </p>
        </div>
      </div>
    )
  }

  if (!message) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">Message not found</p>
        </div>
      </div>
    )
  }

  const getMessageStatusBadge = () => {
    if (message.status === 'sent') {
      return <Badge variant="success">Sent</Badge>
    }
    if (message.status === 'processing') {
      return (
        <div className="flex items-center gap-2">
          <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
          <Badge variant="secondary">Processing</Badge>
        </div>
      )
    }
    if (message.status === 'failed') {
      return <Badge variant="error">Failed</Badge>
    }
    if (message.status === 'draft') {
      return <Badge variant="secondary">Draft</Badge>
    }
    if (message.status === 'scheduled') {
      return <Badge variant="secondary">Scheduled</Badge>
    }
    return <Badge variant="secondary">{message.status}</Badge>
  }

  const getMessageTypeIcon = () => {
    if (message.providerType === 'email') return Mail
    if (message.providerType === 'sms') return Phone
    if (message.providerType === 'push') return Bell
    return Mail
  }

  const TypeIcon = getMessageTypeIcon()

  const canUpdate =
    isDraft &&
    (hasEmailChanges ||
      hasSMSChanges ||
      hasPushChanges ||
      hasTopicsChanged ||
      hasTargetsChanged)

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
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>Message Details</span>
          </div>
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Overview Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <TypeIcon className="h-5 w-5 text-muted-foreground" />
                </div>
                <h3 className="text-[15px] font-semibold text-foreground capitalize">
                  {message.providerType}
                </h3>
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Left Section - Provider Type */}
                <div className="lg:col-span-1">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                      <TypeIcon className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-[13px] font-medium text-foreground capitalize">
                        {message.providerType}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Right Section - Metadata and Status */}
                <div className="lg:col-span-2 space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="space-y-1">
                      {message.$createdAt && (
                        <p className="text-[13px] text-muted-foreground">
                          Created:{' '}
                          <span className="text-foreground">
                            {formatDateTime(message.$createdAt)}
                          </span>
                        </p>
                      )}
                      {message.scheduledAt && (
                        <p className="text-[13px] text-muted-foreground">
                          Scheduled at:{' '}
                          <span className="text-foreground">
                            {formatDateTime(message.scheduledAt)}
                          </span>
                        </p>
                      )}
                      {message.deliveredAt && (
                        <p className="text-[13px] text-muted-foreground">
                          Sent at:{' '}
                          <span className="text-foreground">
                            {formatDateTime(message.deliveredAt)}
                          </span>
                        </p>
                      )}
                    </div>
                    <div className="flex items-center gap-2">
                      {getMessageStatusBadge()}
                    </div>
                  </div>
                </div>
              </div>
            </div>
            {/* Footer Actions - Only show for draft/scheduled/failed */}
            {message.status !== 'processing' && message.status !== 'sent' && (
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                <div className="flex items-center gap-2">
                  {message.status === 'draft' && (
                    <>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => {
                          // TODO: Implement schedule functionality
                          toast.info('Schedule functionality coming soon')
                        }}
                      >
                        Schedule
                      </Button>
                      <Button
                        variant="secondary"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => {
                          // TODO: Implement send functionality
                          toast.info('Send functionality coming soon')
                        }}
                      >
                        <Send className="mr-1.5 h-4 w-4" />
                        Send message
                      </Button>
                    </>
                  )}
                  {message.status === 'scheduled' && (
                    <>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => {
                          // TODO: Implement cancel scheduling
                          toast.info(
                            'Cancel scheduling functionality coming soon',
                          )
                        }}
                      >
                        Cancel scheduling
                      </Button>
                      <Button
                        variant="secondary"
                        size="sm"
                        className="h-9 text-[13px]"
                        onClick={() => {
                          // TODO: Implement reschedule
                          toast.info('Reschedule functionality coming soon')
                        }}
                      >
                        <Calendar className="mr-1.5 h-4 w-4" />
                        Reschedule
                      </Button>
                    </>
                  )}
                  {message.status === 'failed' && message.deliveryErrors && (
                    <Button
                      variant="secondary"
                      size="sm"
                      className="h-9 text-[13px]"
                      onClick={() => setErrorDetailsDialogOpen(true)}
                    >
                      <AlertCircle className="mr-1.5 h-4 w-4" />
                      View logs
                    </Button>
                  )}
                </div>
              </div>
            )}
          </div>

          {/* Provider-specific Message Content Card */}
          {message.providerType === 'email' && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Email Message
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                  {/* Left Column - Empty for now (could add preview later) */}
                  <div className="lg:col-span-1" />

                  {/* Right Column - Form Fields */}
                  <div className="lg:col-span-2 space-y-4">
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
                        Message
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
                  </div>
                </div>
              </div>
              {isDraft && (
                <div className="px-6 py-4 border-t border-border bg-muted/30">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={!hasEmailChanges || updateEmailMutation.isPending}
                    onClick={handleUpdateMessage}
                  >
                    {updateEmailMutation.isPending && (
                      <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    )}
                    Update
                  </Button>
                </div>
              )}
            </div>
          )}

          {message.providerType === 'sms' && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  SMS Message
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                  {/* Left Column - Phone Preview */}
                  <div className="lg:col-span-1">
                    <SMSPhonePreview
                      content={smsContent}
                      projectName={project?.name}
                    />
                  </div>

                  {/* Right Column - Form Fields */}
                  <div className="lg:col-span-2">
                    <div>
                      <Label
                        htmlFor="sms-content"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Message
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
              {isDraft && (
                <div className="px-6 py-4 border-t border-border bg-muted/30">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={!hasSMSChanges || updateSMSMutation.isPending}
                    onClick={handleUpdateMessage}
                  >
                    {updateSMSMutation.isPending && (
                      <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    )}
                    Update
                  </Button>
                </div>
              )}
            </div>
          )}

          {message.providerType === 'push' && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Push Notification
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                  {/* Left Column - Phone Preview */}
                  <div className="lg:col-span-1">
                    <PushPhonePreview
                      title={pushTitle}
                      body={pushBody}
                      projectName={project?.name}
                    />
                  </div>

                  {/* Right Column - Form Fields */}
                  <div className="lg:col-span-2 space-y-4">
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
                        Message
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
                          <Plus className="mr-1 h-3.5 w-3.5" />
                          Add data
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
                  </div>
                </div>
              </div>
              {isDraft && (
                <div className="px-6 py-4 border-t border-border bg-muted/30">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={!hasPushChanges || updatePushMutation.isPending}
                    onClick={handleUpdateMessage}
                  >
                    {updatePushMutation.isPending && (
                      <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    )}
                    Update
                  </Button>
                </div>
              )}
            </div>
          )}

          {/* Update Topics Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4 flex items-center justify-between">
              <h3 className="text-[15px] font-semibold text-foreground">
                Topics
              </h3>
              {isDraft && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() => setTopicsModalOpen(true)}
                >
                  <Plus className="mr-1.5 h-3.5 w-3.5" />
                  Add
                </Button>
              )}
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              {topicsLoading ? (
                <p className="text-[13px] text-muted-foreground">
                  Loading topics...
                </p>
              ) : selectedTopicIds.size > 0 ? (
                <div className="rounded-lg border border-border bg-card overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Topic name
                        </TableHead>
                        {isDraft && (
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]">
                            Actions
                          </TableHead>
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
                              <TableCell className="px-4 py-3 text-right">
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
                <div
                  className="flex cursor-pointer items-center justify-center rounded-lg border border-dashed border-border bg-card py-8 transition-colors hover:bg-muted/50"
                  onClick={() => setTopicsModalOpen(true)}
                >
                  <p className="text-[13px] text-muted-foreground">
                    Add a topic
                  </p>
                </div>
              ) : (
                <div className="rounded-lg border border-border bg-card py-8 text-center">
                  <p className="text-[13px] text-muted-foreground">
                    No topics were selected
                  </p>
                </div>
              )}
            </div>
            {isDraft && hasTopicsChanged && (
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
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
            <div className="px-6 py-4 flex items-center justify-between">
              <h3 className="text-[15px] font-semibold text-foreground">
                Targets
              </h3>
              {isDraft && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() => setTargetsModalOpen(true)}
                >
                  <Plus className="mr-1.5 h-3.5 w-3.5" />
                  Add
                </Button>
              )}
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              {targetsLoading || usersLoading ? (
                <p className="text-[13px] text-muted-foreground">
                  Loading targets...
                </p>
              ) : selectedTargetIds.size > 0 ? (
                <div className="rounded-lg border border-border bg-card overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Target
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Identifier
                        </TableHead>
                        {isDraft && (
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]">
                            Actions
                          </TableHead>
                        )}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {Array.from(selectedTargetIds).map((targetId) => {
                        const target = targetsById[targetId]
                        const user = target?.userId
                          ? usersById[target.userId]
                          : null
                        return target ? (
                          <TableRow
                            key={targetId}
                            className="border-b border-border/50"
                          >
                            <TableCell className="px-4 py-3">
                              {target.providerType === 'push' ? (
                                <span className="text-[13px] text-foreground">
                                  {target.name || target.identifier}
                                </span>
                              ) : (
                                <span className="text-[13px] text-foreground">
                                  {target.identifier}
                                </span>
                              )}
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              {user ? (
                                <span className="text-[13px] text-muted-foreground">
                                  {user.name || user.email}
                                </span>
                              ) : (
                                <span className="text-[13px] text-muted-foreground">
                                  N/A
                                </span>
                              )}
                            </TableCell>
                            {isDraft && (
                              <TableCell className="px-4 py-3 text-right">
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
                                  }}
                                >
                                  <X className="h-4 w-4" />
                                </Button>
                              </TableCell>
                            )}
                          </TableRow>
                        ) : null
                      })}
                    </TableBody>
                  </Table>
                </div>
              ) : isDraft ? (
                <div
                  className="flex cursor-pointer items-center justify-center rounded-lg border border-dashed border-border bg-card py-8 transition-colors hover:bg-muted/50"
                  onClick={() => setTargetsModalOpen(true)}
                >
                  <p className="text-[13px] text-muted-foreground">
                    Add a target
                  </p>
                </div>
              ) : (
                <div className="rounded-lg border border-border bg-card py-8 text-center">
                  <p className="text-[13px] text-muted-foreground">
                    No targets have been selected.
                  </p>
                </div>
              )}
            </div>
            {isDraft && hasTargetsChanged && (
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
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

          {/* Delete Message Card */}
          {message.status !== 'processing' && (
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
                  <Trash2 className="mr-1.5 h-4 w-4" />
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

        {/* Targets Selection Modal */}
        <TargetsSelectionModal
          open={targetsModalOpen}
          onOpenChange={setTargetsModalOpen}
          onSelect={(targetIds) => {
            setSelectedTargetIds(new Set(targetIds))
            setTargetsModalOpen(false)
          }}
          projectId={projectId}
          providerType={message.providerType}
          existingTargetIds={selectedTargetIds}
        />

        {/* Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
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

        {/* Error Details Dialog */}
        {message.deliveryErrors && (
          <Dialog
            open={errorDetailsDialogOpen}
            onOpenChange={setErrorDetailsDialogOpen}
          >
            <DialogContent className="sm:max-w-2xl p-0">
              <DialogHeader className="px-6 pt-6 text-left">
                <DialogTitle>Delivery Errors</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  The following errors occurred while delivering this message.
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <pre className="mt-4 max-h-[400px] overflow-auto rounded-md border border-border bg-muted/30 p-4 text-[12px]">
                  {JSON.stringify(message.deliveryErrors, null, 2)}
                </pre>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  variant="outline"
                  onClick={() => setErrorDetailsDialogOpen(false)}
                >
                  Close
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        )}
      </div>
    </div>
  )
}

// SMS Phone Preview Component
function SMSPhonePreview({
  content,
  projectName,
}: {
  content: string
  projectName?: string
}) {
  const currentTime = new Date().toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  })

  return (
    <div className="relative mx-auto w-[320px] h-[640px]">
      {/* Phone Frame - iPhone-like with realistic proportions */}
      <div className="absolute inset-0 bg-gradient-to-b from-[#1a1a1a] to-[#0f0f0f] rounded-[50px] border-[6px] border-[#2a2a2a] overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,0.5)]">
        {/* Dynamic Island / Notch - iPhone 14+ style */}
        <div className="absolute top-2 left-1/2 -translate-x-1/2 w-[126px] h-[37px] bg-black rounded-full z-10 shadow-inner">
          {/* Speaker */}
          <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[54px] h-[6px] bg-[#1a1a1a] rounded-full"></div>
        </div>

        {/* Phone Screen Background - Dark with subtle gradient */}
        <div className="absolute inset-[3px] bg-gradient-to-b from-[#000000] to-[#0a0a0a] rounded-[44px] overflow-hidden">
          {/* Status Bar - iPhone style (time only on left) */}
          <div className="absolute top-0 left-0 right-0 h-[54px] flex items-center justify-start px-8 pt-3 z-20">
            <span className="text-[15px] font-semibold text-white">9:41</span>
          </div>

          {/* SMS Content */}
          <div className="absolute inset-0 flex flex-col items-center pt-[100px] px-4">
            {/* Project Avatar and Name */}
            <div className="flex flex-col items-center gap-2 mb-8">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground text-xs font-medium">
                {projectName?.substring(0, 2).toUpperCase() || 'PR'}
              </div>
              <p className="text-[12px] font-medium text-white/90">
                {projectName || 'Project'}
              </p>
            </div>

            {/* Message Bubble */}
            <div className="w-full px-4 mt-auto mb-8">
              <div className="flex items-start gap-2">
                <div className="flex-1">
                  <p className="text-[10px] text-white/60 mb-1">
                    Today {currentTime}
                  </p>
                  <div className="rounded-[20px] bg-[#e9e9eb] dark:bg-[#333333] px-3 py-1.5 max-h-[80px] overflow-hidden">
                    <p className="text-[13px] text-foreground dark:text-[#e0e0e0] line-clamp-4">
                      {content || 'Message content will appear here'}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

// Push Phone Preview Component
function PushPhonePreview({
  title,
  body,
  projectName,
}: {
  title: string
  body: string
  projectName?: string
}) {
  // Get current date and time
  const now = new Date()
  const dateStr = now.toLocaleDateString('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
  })
  const timeStr = now.toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit',
    hour12: false,
  })

  return (
    <div className="relative mx-auto w-[320px] h-[640px]">
      {/* Phone Frame - iPhone-like with realistic proportions */}
      <div className="absolute inset-0 bg-gradient-to-b from-[#1a1a1a] to-[#0f0f0f] rounded-[50px] border-[6px] border-[#2a2a2a] overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,0.5)]">
        {/* Dynamic Island / Notch - iPhone 14+ style */}
        <div className="absolute top-2 left-1/2 -translate-x-1/2 w-[126px] h-[37px] bg-black rounded-full z-10 shadow-inner">
          {/* Speaker */}
          <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[54px] h-[6px] bg-[#1a1a1a] rounded-full"></div>
        </div>

        {/* Phone Screen Background - Dark with subtle gradient */}
        <div className="absolute inset-[3px] bg-gradient-to-b from-[#000000] to-[#0a0a0a] rounded-[44px] overflow-hidden">
          {/* Status Bar - iPhone style (time only on left) */}
          <div className="absolute top-0 left-0 right-0 h-[54px] flex items-center justify-start px-8 pt-3 z-20">
            <span className="text-[15px] font-semibold text-white">9:41</span>
          </div>

          {/* Lock Screen Elements - Date and Clock (centered, partially obscured by notification) */}
          <div className="absolute inset-0 flex flex-col items-center justify-center pt-[100px]">
            <p className="text-white/90 text-[15px] font-medium mb-2">
              {dateStr}
            </p>
            <p className="text-white text-[64px] font-light leading-none tracking-tight">
              {timeStr}
            </p>
          </div>

          {/* Notification Card - Positioned lower left, overlapping lock screen */}
          <div className="absolute bottom-[120px] left-4 right-4 z-30">
            <div className="rounded-[16px] bg-[#1a1a1a] dark:bg-[#2a2a2a] border border-white/10 p-4 backdrop-blur-sm">
              {/* Header */}
              <div className="flex items-center justify-between mb-2.5">
                <div className="flex items-center gap-2">
                  <Bell className="h-4 w-4 text-white/80" />
                  <span className="text-[12px] font-medium text-white/90">
                    {projectName || 'Project'}
                  </span>
                </div>
                <span className="text-[11px] text-white/60">now</span>
              </div>
              {/* Content */}
              <div>
                <p className="text-[14px] font-semibold text-white mb-1.5 leading-tight">
                  {title || 'Message Title'}
                </p>
                <p className="text-[13px] text-white/80 leading-snug line-clamp-4">
                  {body || 'Message body will appear here'}
                </p>
              </div>
            </div>
          </div>
        </div>
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

  const { topics, total, isLoading } = useProjectTopics(
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
      <DialogContent className="sm:max-w-2xl p-0 max-h-[80vh] flex flex-col">
        <DialogHeader className="px-6 pt-6 text-left">
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

// Targets Selection Modal
// Note: Since targets are user-specific, we'll use users for selection
// Targets will be created/selected based on selected users
function TargetsSelectionModal({
  open,
  onOpenChange,
  onSelect,
  projectId,
  providerType,
  existingTargetIds,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (targetIds: string[]) => void
  projectId?: string
  providerType?: string
  existingTargetIds: Set<string>
}) {
  const [search, setSearch] = useState('')
  const [selectedUserIds, setSelectedUserIds] = useState<Set<string>>(new Set())
  const [page, setPage] = useState(0)
  const pageSize = 25

  // For now, we'll use users - targets will be created from selected users
  // TODO: Implement proper target listing when API supports it
  const { users, total, isLoading } = useProjectUsers(
    projectId || null,
    page,
    pageSize,
    search,
  )

  const handleToggleUser = (userId: string) => {
    const newSelected = new Set(selectedUserIds)
    if (newSelected.has(userId)) {
      newSelected.delete(userId)
    } else {
      newSelected.add(userId)
    }
    setSelectedUserIds(newSelected)
  }

  const handleAdd = () => {
    const userIds = Array.from(selectedUserIds)
    if (userIds.length > 0) {
      // For now, we'll use user IDs as target identifiers
      // TODO: Create actual targets from users when API supports it
      // This is a simplified version - in production, you'd create targets from users
      onSelect(userIds) // Using user IDs as placeholders for target IDs
      setSelectedUserIds(new Set())
      setSearch('')
      onOpenChange(false)
    }
  }

  const handleCancel = () => {
    setSelectedUserIds(new Set())
    setSearch('')
    onOpenChange(false)
  }

  useEffect(() => {
    if (!open) {
      setSelectedUserIds(new Set())
      setSearch('')
      setPage(0)
    }
  }, [open])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[80vh] flex flex-col">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Select targets</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Select users to send this message to. Targets will be created from
            selected users.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 flex-1 overflow-hidden flex flex-col">
          <div className="space-y-4">
            <Input
              placeholder="Search users by name, email, or ID..."
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
                  Loading users...
                </div>
              ) : users.length === 0 ? (
                <EmptyState
                  icon={Users}
                  isEmpty={!search}
                  hasFilters={!!search}
                  className="py-8"
                />
              ) : (
                users.map((user) => {
                  const isSelected = selectedUserIds.has(user.$id)
                  const displayName =
                    user.name || user.email || user.phone || user.$id

                  return (
                    <div
                      key={user.$id}
                      onClick={() => handleToggleUser(user.$id)}
                      className={cn(
                        'flex items-center gap-3 rounded-lg border p-3 transition-colors cursor-pointer',
                        isSelected
                          ? 'border-primary bg-primary/5'
                          : 'border-border hover:bg-muted/50',
                      )}
                    >
                      <Checkbox
                        checked={isSelected}
                        onCheckedChange={() => handleToggleUser(user.$id)}
                        onClick={(e) => e.stopPropagation()}
                        className="cursor-pointer"
                      />
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">
                          {displayName}
                        </p>
                        {user.email && (
                          <p className="text-xs text-muted-foreground truncate">
                            {user.email}
                          </p>
                        )}
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
          <Button onClick={handleAdd} disabled={selectedUserIds.size === 0}>
            Add {selectedUserIds.size > 0 ? `(${selectedUserIds.size})` : ''}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
