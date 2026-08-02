import {
  createContext,
  memo,
  useCallback,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { startOfDay, subDays } from 'date-fns'
import {
  ImageFormat,
  Query,
  type RealtimeResponseEvent,
} from '@appwrite.io/console'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { ConsoleHeaderLogo } from '@/components/global/shared/ConsoleHeaderLogo'
import { getConsoleHeaderLogoClass } from '@/lib/html-theme'
import { useTheme } from 'next-themes'
import { toast } from 'sonner'
import {
  Check,
  ChevronsUpDown,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  Archive,
  ArrowUp,
  Circle,
  Copy,
  Paperclip,
  Loader2,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Send,
  Square,
  Trash2,
  X,
  ZoomIn,
  ZoomOut,
  ExternalLink,
  Maximize2,
  Minimize2,
} from 'lucide-react'
import {
  ThinkingBubble,
  scaleSphereSize,
  defaultParticleCountForSize,
  SPHERE_PARTICLE_COUNT_MIN,
  SPHERE_PARTICLE_COUNT_MAX,
  SPHERE_PARTICLE_COUNT_STEP,
  SPHERE_SIZE_SCALE_DEFAULT,
  SPHERE_SIZE_SCALE_MIN,
  SPHERE_SIZE_SCALE_MAX,
  SPHERE_SIZE_SCALE_STEP,
  type SphereColorMode,
  type SphereShapeMode,
} from '@/components/global/shared/ThinkingBubble'
import { Slider } from '@/components/ui/slider'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { StreamingMarkdown } from '@/components/global/shared/StreamingMarkdown'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  useAssistantConversations,
  useAssistantAttachmentFiles,
  useAssistantMcpConnections,
  useAssistantMessages,
  ASSISTANT_MESSAGES_PAGE_SIZE,
  fetchAssistantMessages,
  useCreateAssistantConversation,
  useCreateAssistantMessage,
  useDeleteAssistantConversation,
  useUpdateAssistantConversation,
  useUpdateAssistantMessage,
  useUploadAssistantAttachments,
  ASSISTANT_ATTACHMENTS_BUCKET_ID,
  useProject,
  consoleAccountQueryOptions,
  useAIChatActiveConversationId,
  useAIChatExpanded,
  useAIChatPanelOpen,
  useAIChatPinnedConversationIds,
  type AssistantConversation,
  type AssistantMessage,
} from '@/lib/react-query/hooks'
import { isClientQueryEnabled } from '@/lib/react-query/hooks/constants'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleRightPane } from '@/components/global/providers/ConsoleRightPaneContext'
import { AssistantConversationsResizableLayout } from '@/components/global/providers/ai-chat/AssistantConversationsResizableLayout'
import { AssistantMessageDebugCard } from '@/components/global/providers/ai-chat/AssistantMessageDebugCard'
import { AssistantTurnActivity } from '@/components/global/providers/ai-chat/AssistantTurnActivity'
import { McpConnections } from '@/components/global/providers/ai-chat/McpConnections'
import { AssistantConversationContextMenu } from '@/components/global/providers/ai-chat/AssistantConversationContextMenu'
import { AssistantRenameDialog } from '@/components/global/providers/ai-chat/AssistantRenameDialog'
import { ConversationResourceSummary } from '@/components/global/providers/ai-chat/ConversationResourceSummary'
import { AssistantEmptyState } from '@/components/global/providers/ai-chat/AssistantEmptyState'
import { useIsMarketingPage } from '@/hooks/use-is-marketing-page'
import { isConsoleRightPanePath } from '@/lib/docs/docs-preview-context'
import { listConsoleProjects } from '@/lib/appwrite/console-projects'
import { getApiEndpoint, sdk } from '@/lib/appwrite/sdk'
import { applyAssistantRealtimePayload } from '@/lib/assistant/realtime-cache'
import {
  buildTurnView,
  getAssistantBubblePhase,
  getAssistantConversationStatusDotClass,
  getAssistantConversationStatusLabel,
  getAssistantConversationStatusTone,
  isAssistantConversationInFlight,
  isAssistantMessageInFlight,
  type AssistantBubblePhase,
} from '@/lib/assistant/turn-view'
import { useAvifSupport } from '@/lib/avif-support'
import { registerConsoleRealtimeListener } from '@/lib/realtime/console-hub'

function nonEmptyId(value: string | null | undefined): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

/** API may return projectId on conversations; SDK types omit optional fields at times. */
function assistantConversationProjectId(
  conversation: AssistantConversation | undefined | null,
): string | undefined {
  if (!conversation) return undefined
  const extended = conversation as AssistantConversation & {
    projectId?: string | null
  }
  return nonEmptyId(extended.projectId)
}

interface AIChatContextValue {
  isOpen: boolean
  activeConversationId: string | null
  openChat: () => void
  closeChat: () => void
  toggleChat: () => void
  setActiveConversationId: (conversationId: string | null) => void
}

const AIChatContext = createContext<AIChatContextValue | null>(null)
const AUTH_ROUTE_PATHNAMES = new Set([
  '/sign-in',
  '/sign-up',
  '/recovery',
  '/mfa',
  '/join',
  '/sign-out',
  '/verify-email',
])

function isAssistantBlockedPath(pathname: string): boolean {
  return AUTH_ROUTE_PATHNAMES.has(pathname)
}

const CONVERSATION_TIME_GROUPS = [
  'Today',
  'Yesterday',
  'Previous 7 days',
  'Previous 30 days',
  'Older',
] as const

type ConversationTimeGroup = (typeof CONVERSATION_TIME_GROUPS)[number]

function getConversationTimeGroup(
  dateValue: string | undefined,
  now = new Date(),
): ConversationTimeGroup {
  const date = dateValue ? new Date(dateValue) : now
  if (Number.isNaN(date.getTime())) return 'Older'

  const day = startOfDay(date).getTime()
  const today = startOfDay(now).getTime()
  const yesterday = startOfDay(subDays(now, 1)).getTime()
  const previous7 = startOfDay(subDays(now, 7)).getTime()
  const previous30 = startOfDay(subDays(now, 30)).getTime()

  if (day >= today) return 'Today'
  if (day >= yesterday) return 'Yesterday'
  if (day >= previous7) return 'Previous 7 days'
  if (day >= previous30) return 'Previous 30 days'
  return 'Older'
}

function groupConversationsByTime(
  conversations: AssistantConversation[],
): Array<{ label: ConversationTimeGroup; items: AssistantConversation[] }> {
  const buckets = new Map<ConversationTimeGroup, AssistantConversation[]>()
  for (const conversation of conversations) {
    const label = getConversationTimeGroup(
      conversation.$updatedAt || conversation.$createdAt,
    )
    const existing = buckets.get(label)
    if (existing) {
      existing.push(conversation)
    } else {
      buckets.set(label, [conversation])
    }
  }

  return CONVERSATION_TIME_GROUPS.filter((label) => buckets.has(label)).map(
    (label) => ({
      label,
      items: buckets.get(label) ?? [],
    }),
  )
}

export function AIChatProvider({ children }: { children: React.ReactNode }) {
  const location = useLocation()
  const { activeContent, showAssistant, hideRightPane } = useConsoleRightPane()
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const isMarketingPage = useIsMarketingPage()
  const isConsolePath = useMemo(
    () => isConsoleRightPanePath(location.pathname),
    [location.pathname],
  )
  const { data: account } = useQuery({
    ...consoleAccountQueryOptions(),
    enabled: !isAssistantBlocked && isClientQueryEnabled,
  })
  const { isOpen, setIsOpen } = useAIChatPanelOpen(account)
  const { activeConversationId, setActiveConversationId } =
    useAIChatActiveConversationId(account)
  const hasRestoredOpenPrefRef = useRef(false)

  const openChat = useCallback(() => {
    if (isAssistantBlocked || isMarketingPage || !isConsolePath) return
    showAssistant()
    setIsOpen(true)
  }, [isAssistantBlocked, isConsolePath, isMarketingPage, setIsOpen, showAssistant])
  const closeChat = useCallback(() => {
    setIsOpen(false)
    hideRightPane()
  }, [hideRightPane, setIsOpen])
  const toggleChat = useCallback(() => {
    if (isAssistantBlocked || isMarketingPage || !isConsolePath) return
    if (activeContent === 'assistant') {
      closeChat()
      return
    }
    openChat()
  }, [activeContent, closeChat, isAssistantBlocked, isConsolePath, isMarketingPage, openChat])

  useEffect(() => {
    if (!isConsolePath || isMarketingPage) {
      if (isOpen) {
        setIsOpen(false)
      }
      if (activeContent === 'assistant') {
        hideRightPane()
      }
    }
  }, [activeContent, hideRightPane, isConsolePath, isMarketingPage, isOpen, setIsOpen])

  useEffect(() => {
    if (isAssistantBlocked && isOpen) {
      setIsOpen(false)
      hideRightPane()
    }
  }, [hideRightPane, isAssistantBlocked, isOpen, setIsOpen])

  useEffect(() => {
    if (hasRestoredOpenPrefRef.current || !account || isAssistantBlocked || !isOpen) {
      return
    }
    hasRestoredOpenPrefRef.current = true
    showAssistant()
  }, [account, isAssistantBlocked, isOpen, showAssistant])

  return (
    <AIChatContext.Provider
      value={{
        isOpen,
        activeConversationId,
        openChat,
        closeChat,
        toggleChat,
        setActiveConversationId,
      }}
    >
      {children}
    </AIChatContext.Provider>
  )
}

export function useAIChat() {
  const context = useContext(AIChatContext)
  if (!context) {
    // Return no-op functions if used outside provider
    return {
      isOpen: false,
      activeConversationId: null,
      openChat: () => {},
      closeChat: () => {},
      toggleChat: () => {},
      setActiveConversationId: () => {},
    }
  }
  return context
}

const suggestedQuestions = [
  'How do I create a new database?',
  'How do I set up authentication?',
  'How do I upload files to storage?',
  'How do I deploy a function?',
]

/** Action-oriented prompts when an enabled MCP server can run tools. */
const mcpSuggestedQuestions = [
  'List the databases and tables in this project',
  'Show me Auth users created this week',
  'What storage buckets do I have?',
  'Create a todos table with title and done columns',
]

const PLACEHOLDER_TOKENS = [
  '{{APPWRITE_ENDPOINT}}',
  '{{APPWRITE_REGION}}',
  '{{APPWRITE_PROJECT_ID}}',
  '{{APPWRITE_PROJECT_NAME}}',
  '{{APPWRITE_TEAM_ID}}',
  '{{APPWRITE_ORGANIZATION_ID}}',
  '{{APPWRITE_USER_ID}}',
] as const

type PlaceholderToken = (typeof PLACEHOLDER_TOKENS)[number]

const PLACEHOLDER_LABELS: Record<PlaceholderToken, string> = {
  '{{APPWRITE_ENDPOINT}}': 'Appwrite endpoint',
  '{{APPWRITE_REGION}}': 'Region',
  '{{APPWRITE_PROJECT_ID}}': 'Project ID',
  '{{APPWRITE_PROJECT_NAME}}': 'Project name',
  '{{APPWRITE_TEAM_ID}}': 'Team ID',
  '{{APPWRITE_ORGANIZATION_ID}}': 'Organization ID',
  '{{APPWRITE_USER_ID}}': 'User ID',
}

function dedupeValues(values: Array<string | null | undefined>): string[] {
  const seen = new Set<string>()
  for (const value of values) {
    const trimmed = value?.trim()
    if (trimmed) seen.add(trimmed)
  }
  return [...seen]
}

function extractPlaceholderTokens(content: string): PlaceholderToken[] {
  const found: PlaceholderToken[] = []
  for (const token of PLACEHOLDER_TOKENS) {
    if (content.includes(token)) found.push(token)
  }
  return found
}

function applyPlaceholderValues(
  content: string,
  values: Partial<Record<PlaceholderToken, string>>,
): string {
  let resolved = content
  for (const token of PLACEHOLDER_TOKENS) {
    const value = values[token]
    if (!value) continue
    resolved = resolved.split(token).join(value)
  }
  return resolved
}

/** Distance from bottom that still counts as "following" the conversation. */
const AUTO_SCROLL_BOTTOM_THRESHOLD = 96

interface AssistantMessageRowProps {
  message: AssistantMessage
  messageAttachments?: string[]
  deferCodeBlocks: boolean
  placeholderCandidates: Partial<Record<PlaceholderToken, string[]>>
  copied: boolean
  showDebug?: boolean
  onCopyMessage: (messageId: string, text: string) => void
  onStartEditResend: (
    messageId: string,
    text: string,
    attachmentIds: string[],
  ) => void
  onRetry?: (messageId: string) => void
  canRetry?: boolean
}

interface MessageAttachmentsProps {
  attachmentIds?: string[]
  alignment: 'left' | 'right'
}

interface ComposerPendingAttachment {
  localId: string
  name: string
  mimeType: string
  size: number
  status: 'uploading' | 'ready' | 'failed'
  fileId?: string
}

interface QueuedComposerMessage {
  id: string
  content: string
  attachmentIds: string[]
}

interface MessageAttachmentItem {
  id: string
  name: string
  mimeType: string
  size?: number
  isImage: boolean
  previewUrl: string | null
  fullscreenPreviewUrl: string | null
  openUrl: string
  downloadUrl: string
}

function formatAttachmentSize(size?: number): string | null {
  if (!size || size <= 0) return null
  const units = ['B', 'KB', 'MB', 'GB']
  let value = size
  let unitIndex = 0
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }
  const fractionDigits = value >= 10 || unitIndex === 0 ? 0 : 1
  return `${value.toFixed(fractionDigits)} ${units[unitIndex]}`
}

function getPreviewAspectClass(isPortrait: boolean): string {
  return isPortrait ? 'aspect-[9/16]' : 'aspect-video'
}

function isRtlMessageText(text: string): boolean {
  // Detect first strong-direction character and treat Arabic/Hebrew scripts as RTL.
  for (const character of text) {
    if (/\s/.test(character)) continue
    if (/[A-Za-z0-9]/.test(character)) return false
    if (
      /[\u0590-\u05FF\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(character)
    ) {
      return true
    }
  }
  return false
}

function MessageAttachments({
  attachmentIds = [],
  alignment,
}: MessageAttachmentsProps) {
  const t = useT()
  const uniqueAttachmentIds = useMemo(
    () => [...new Set(attachmentIds.filter(Boolean))],
    [attachmentIds],
  )
  const [fullscreenAttachment, setFullscreenAttachment] = useState<
    number | null
  >(null)
  const [fullscreenZoom, setFullscreenZoom] = useState(1)
  const [fullscreenPan, setFullscreenPan] = useState({ x: 0, y: 0 })
  const [loadedImageKeys, setLoadedImageKeys] = useState<Set<string>>(new Set())
  const avifSupported = useAvifSupport()
  const [imageOrientations, setImageOrientations] = useState<
    Record<string, 'portrait' | 'landscape'>
  >({})
  const [imageDimensions, setImageDimensions] = useState<
    Record<string, { width: number; height: number }>
  >({})
  const thumbnailStripRef = useRef<HTMLDivElement>(null)
  const thumbnailButtonRefs = useRef<Record<string, HTMLButtonElement | null>>(
    {},
  )
  const isPanningRef = useRef(false)
  const panStartRef = useRef({ x: 0, y: 0 })
  const { data: filesData } = useAssistantAttachmentFiles(uniqueAttachmentIds)
  const filesById = useMemo(() => {
    return new Map((filesData ?? []).map((file) => [file.$id, file]))
  }, [filesData])
  const attachments: MessageAttachmentItem[] = useMemo(
    () =>
      uniqueAttachmentIds.map((fileId) => {
        const file = filesById.get(fileId)
        return {
          id: fileId,
          name: file?.name ?? fileId,
          mimeType: file?.mimeType ?? '',
          size: file?.sizeOriginal,
          isImage: file?.mimeType?.startsWith('image/') ?? false,
          previewUrl: file?.mimeType?.startsWith('image/')
            ? sdk.forConsole.storage.getFilePreview({
                bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                fileId,
                height: 640,
                output: avifSupported ? ImageFormat.Avif : undefined,
              })
            : null,
          fullscreenPreviewUrl: file?.mimeType?.startsWith('image/')
            ? sdk.forConsole.storage.getFilePreview({
                bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                fileId,
                height: 900,
                output: avifSupported ? ImageFormat.Avif : undefined,
              })
            : null,
          openUrl: file?.mimeType?.startsWith('image/')
            ? sdk.forConsole.storage.getFilePreview({
                bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                fileId,
                height: 900,
              })
            : sdk.forConsole.storage.getFileDownload({
                bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                fileId,
              }),
          downloadUrl: sdk.forConsole.storage.getFileDownload({
            bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
            fileId,
          }),
        }
      }),
    [filesById, uniqueAttachmentIds, avifSupported],
  )
  const imageAttachments = useMemo(
    () =>
      attachments.filter(
        (attachment) =>
          attachment.isImage &&
          attachment.previewUrl &&
          attachment.mimeType !== 'image/svg+xml' &&
          !attachment.name.toLowerCase().endsWith('.svg'),
      ),
    [attachments],
  )
  const fileAttachments = useMemo(
    () => attachments.filter((attachment) => !attachment.isImage),
    [attachments],
  )
  const visibleImages = useMemo(
    () => imageAttachments.slice(0, 4),
    [imageAttachments],
  )
  const hiddenImageCount = Math.max(
    0,
    imageAttachments.length - visibleImages.length,
  )
  const activeFullscreenAttachment = useMemo(() => {
    if (fullscreenAttachment === null) return null
    return imageAttachments[fullscreenAttachment] ?? null
  }, [fullscreenAttachment, imageAttachments])
  const fullscreenLoadedKey = activeFullscreenAttachment
    ? `fullscreen:${activeFullscreenAttachment.id}`
    : null
  const isFullscreenImageLoaded = fullscreenLoadedKey
    ? loadedImageKeys.has(fullscreenLoadedKey)
    : false
  const activeFullscreenDimensions = activeFullscreenAttachment
    ? imageDimensions[activeFullscreenAttachment.id]
    : undefined
  const canGoToPreviousImage =
    fullscreenAttachment !== null && fullscreenAttachment > 0
  const canGoToNextImage =
    fullscreenAttachment !== null &&
    fullscreenAttachment < imageAttachments.length - 1
  const canZoomOut = fullscreenZoom > 0.5
  const canZoomIn = fullscreenZoom < 3

  const openFullscreenById = useCallback(
    (attachmentId: string) => {
      const nextIndex = imageAttachments.findIndex(
        (attachment) => attachment.id === attachmentId,
      )
      if (nextIndex < 0) return
      setFullscreenAttachment(nextIndex)
    },
    [imageAttachments],
  )

  const goToPreviousImage = useCallback(() => {
    setFullscreenAttachment((current) => {
      if (current === null || current <= 0) return current
      return current - 1
    })
  }, [])

  const goToNextImage = useCallback(() => {
    setFullscreenAttachment((current) => {
      if (current === null || current >= imageAttachments.length - 1)
        return current
      return current + 1
    })
  }, [imageAttachments.length])

  const zoomOut = useCallback(() => {
    setFullscreenZoom((current) =>
      Math.max(0.5, Number((current - 0.25).toFixed(2))),
    )
  }, [])

  const zoomIn = useCallback(() => {
    setFullscreenZoom((current) =>
      Math.min(3, Number((current + 0.25).toFixed(2))),
    )
  }, [])

  const resetZoom = useCallback(() => {
    setFullscreenZoom(1)
    setFullscreenPan({ x: 0, y: 0 })
  }, [])

  const handleFullscreenWheel = useCallback(
    (event: React.WheelEvent<HTMLDivElement>) => {
      event.preventDefault()
      const zoomStep = event.deltaY > 0 ? -0.1 : 0.1
      setFullscreenZoom((current) => {
        const next = Math.min(
          3,
          Math.max(0.5, Number((current + zoomStep).toFixed(2))),
        )
        return next
      })
    },
    [],
  )

  const handleFullscreenMouseDown = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      if (fullscreenZoom <= 1) return
      isPanningRef.current = true
      panStartRef.current = {
        x: event.clientX - fullscreenPan.x,
        y: event.clientY - fullscreenPan.y,
      }
    },
    [fullscreenPan.x, fullscreenPan.y, fullscreenZoom],
  )

  const handleFullscreenMouseMove = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      if (!isPanningRef.current || fullscreenZoom <= 1) return
      setFullscreenPan({
        x: event.clientX - panStartRef.current.x,
        y: event.clientY - panStartRef.current.y,
      })
    },
    [fullscreenZoom],
  )

  const stopFullscreenPanning = useCallback(() => {
    isPanningRef.current = false
  }, [])

  useEffect(() => {
    if (fullscreenAttachment === null) return
    if (fullscreenAttachment >= imageAttachments.length) {
      setFullscreenAttachment(
        imageAttachments.length > 0 ? imageAttachments.length - 1 : null,
      )
    }
  }, [fullscreenAttachment, imageAttachments.length])

  useEffect(() => {
    setFullscreenZoom(1)
    setFullscreenPan({ x: 0, y: 0 })
  }, [activeFullscreenAttachment?.id])

  useEffect(() => {
    if (fullscreenZoom <= 1) {
      setFullscreenPan({ x: 0, y: 0 })
    }
  }, [fullscreenZoom])

  useEffect(() => {
    if (fullscreenAttachment === null) return
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'ArrowLeft') {
        event.preventDefault()
        goToPreviousImage()
      } else if (event.key === 'ArrowRight') {
        event.preventDefault()
        goToNextImage()
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [fullscreenAttachment, goToNextImage, goToPreviousImage])

  useEffect(() => {
    const currentIds = new Set(
      imageAttachments.map((attachment) => attachment.id),
    )
    for (const attachmentId of Object.keys(thumbnailButtonRefs.current)) {
      if (!currentIds.has(attachmentId)) {
        delete thumbnailButtonRefs.current[attachmentId]
      }
    }
  }, [imageAttachments])

  useEffect(() => {
    if (fullscreenAttachment === null) return
    const activeAttachment = imageAttachments[fullscreenAttachment]
    if (!activeAttachment) return

    const strip = thumbnailStripRef.current
    const thumbnail = thumbnailButtonRefs.current[activeAttachment.id]
    if (!strip || !thumbnail) return

    const stripRect = strip.getBoundingClientRect()
    const thumbnailRect = thumbnail.getBoundingClientRect()
    const isOutOfView =
      thumbnailRect.left < stripRect.left ||
      thumbnailRect.right > stripRect.right

    if (isOutOfView) {
      thumbnail.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'center',
      })
    }
  }, [fullscreenAttachment, imageAttachments])

  useEffect(() => {
    const handleMouseUp = () => {
      stopFullscreenPanning()
    }
    window.addEventListener('mouseup', handleMouseUp)
    return () => {
      window.removeEventListener('mouseup', handleMouseUp)
    }
  }, [stopFullscreenPanning])

  if (attachments.length === 0) return null

  return (
    <>
      <div
        className={cn(
          'mt-2 flex',
          alignment === 'right' ? 'justify-end' : 'justify-start',
        )}
      >
        <div className="w-full max-w-[88%] space-y-2">
          {visibleImages.length > 0 ? (
            <div
              className={cn(
                'grid gap-1.5',
                visibleImages.length === 1 && 'grid-cols-1',
                visibleImages.length === 2 && 'grid-cols-2',
                visibleImages.length >= 3 && 'grid-cols-2',
              )}
            >
              {visibleImages.map((attachment, index) => {
                const isThreeImageMainTile =
                  visibleImages.length === 3 && index === 0
                const isOverflowTile =
                  hiddenImageCount > 0 && index === visibleImages.length - 1
                return (
                  <button
                    key={attachment.id}
                    type="button"
                    onClick={() => openFullscreenById(attachment.id)}
                    className={cn(
                      'group relative overflow-hidden rounded-md border border-border bg-muted/20',
                      isThreeImageMainTile && 'row-span-2',
                    )}
                  >
                    <img
                      src={attachment.previewUrl!}
                      alt={attachment.name}
                      onLoad={(event) => {
                        setLoadedImageKeys((previous) => {
                          const next = new Set(previous)
                          next.add(`preview:${attachment.id}`)
                          return next
                        })
                        const image = event.currentTarget as HTMLImageElement
                        const orientation =
                          image.naturalHeight > image.naturalWidth
                            ? 'portrait'
                            : 'landscape'
                        setImageOrientations((previous) =>
                          previous[attachment.id] === orientation
                            ? previous
                            : { ...previous, [attachment.id]: orientation },
                        )
                        setImageDimensions((previous) => {
                          const existing = previous[attachment.id]
                          if (
                            existing &&
                            existing.width === image.naturalWidth &&
                            existing.height === image.naturalHeight
                          ) {
                            return previous
                          }
                          return {
                            ...previous,
                            [attachment.id]: {
                              width: image.naturalWidth,
                              height: image.naturalHeight,
                            },
                          }
                        })
                      }}
                      className={cn(
                        getPreviewAspectClass(
                          imageOrientations[attachment.id] === 'portrait',
                        ),
                        'w-full object-cover transition-[opacity,transform] duration-300 group-hover:scale-[1.01]',
                        loadedImageKeys.has(`preview:${attachment.id}`)
                          ? 'opacity-100'
                          : 'opacity-35',
                      )}
                      loading="lazy"
                    />
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/30">
                      {isOverflowTile ? (
                        <div className="rounded-md bg-black/70 px-2.5 py-1.5 text-[12px] font-medium text-white">
                          +{hiddenImageCount}
                        </div>
                      ) : (
                        <div className="rounded-md bg-black/60 p-1.5 text-white opacity-0 transition-opacity group-hover:opacity-100">
                          <Maximize2 className="h-3.5 w-3.5" />
                        </div>
                      )}
                    </div>
                  </button>
                )
              })}
            </div>
          ) : null}
          {fileAttachments.length > 0 ? (
            <div
              className={cn(
                'space-y-1.5',
                fileAttachments.length > 4 && 'max-h-44 overflow-y-auto pe-1',
              )}
            >
              {fileAttachments.map((attachment) => {
                const fileSize = formatAttachmentSize(attachment.size)
                return (
                  <div
                    key={attachment.id}
                    className="rounded-lg border border-border bg-card/60 px-2.5 py-2"
                  >
                    <div className="flex items-center gap-1.5">
                      <Paperclip className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[12px] font-medium text-foreground">
                          {attachment.name}
                        </p>
                        <p className="truncate text-[10px] text-muted-foreground">
                          {attachment.mimeType || t('File')}
                          {fileSize ? ` - ${fileSize}` : ''}
                        </p>
                      </div>
                      <Button
                        asChild
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                      >
                        <a
                          href={attachment.openUrl}
                          target="_blank"
                          rel="noreferrer"
                          aria-label={`${t('Open')} ${attachment.name}`}
                        >
                          <ExternalLink className="h-3.5 w-3.5" />
                        </a>
                      </Button>
                    </div>
                  </div>
                )
              })}
            </div>
          ) : null}
        </div>
      </div>
      {activeFullscreenAttachment?.fullscreenPreviewUrl ? (
        <WizardLayout
          title={activeFullscreenAttachment.name}
          fullscreen
          useSidebar={false}
          constrainWidth={false}
          constrainFooterWidth={false}
          contentPadding={false}
          onClose={() => setFullscreenAttachment(null)}
          contentClassName="-mx-6"
          headerActions={
            <div className="flex items-center gap-1">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={goToPreviousImage}
                disabled={!canGoToPreviousImage}
                aria-label={t('Previous image')}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <span className="min-w-[56px] text-center text-[11px] text-muted-foreground">
                {(fullscreenAttachment ?? 0) + 1}/{imageAttachments.length}
              </span>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={goToNextImage}
                disabled={!canGoToNextImage}
                aria-label={t('Next image')}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
              <Button
                asChild
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
              >
                <a
                  href={activeFullscreenAttachment.openUrl}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={`${t('Open')} ${activeFullscreenAttachment.name}`}
                >
                  <ExternalLink className="h-4 w-4" />
                </a>
              </Button>
              <div className="mx-1 h-4 w-px bg-border" />
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={zoomOut}
                disabled={!canZoomOut}
                aria-label={t('Zoom out')}
              >
                <ZoomOut className="h-4 w-4" />
              </Button>
              <span className="min-w-[44px] text-center text-[11px] text-muted-foreground">
                {Math.round(fullscreenZoom * 100)}%
              </span>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={zoomIn}
                disabled={!canZoomIn}
                aria-label={t('Zoom in')}
              >
                <ZoomIn className="h-4 w-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={resetZoom}
                disabled={fullscreenZoom === 1}
                aria-label={t('Fit image to screen')}
              >
                <Maximize2 className="h-4 w-4" />
              </Button>
            </div>
          }
        >
          <div className="flex min-h-[calc(100dvh-140px)] w-full flex-col bg-background p-4 sm:p-6">
            <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-auto p-2 sm:p-4">
              {!isFullscreenImageLoaded ? (
                <div className="absolute inset-0 flex items-center justify-center bg-background/70 backdrop-blur-[1px]">
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    {t('Loading image...')}
                  </div>
                </div>
              ) : null}
              <img
                src={activeFullscreenAttachment.fullscreenPreviewUrl}
                alt={activeFullscreenAttachment.name}
                onLoad={() =>
                  setLoadedImageKeys((previous) => {
                    const next = new Set(previous)
                    next.add(`fullscreen:${activeFullscreenAttachment.id}`)
                    return next
                  })
                }
                className={cn(
                  'h-auto max-h-[calc(100dvh-320px)] w-full max-w-[1400px] rounded-lg object-contain transition-opacity duration-300 select-none',
                  fullscreenZoom > 1
                    ? isPanningRef.current
                      ? 'cursor-grabbing'
                      : 'cursor-grab'
                    : '',
                  isFullscreenImageLoaded ? 'opacity-100' : 'opacity-20',
                )}
                onWheel={handleFullscreenWheel}
                onMouseDown={handleFullscreenMouseDown}
                onMouseMove={handleFullscreenMouseMove}
                onMouseUp={stopFullscreenPanning}
                onMouseLeave={stopFullscreenPanning}
                style={
                  activeFullscreenDimensions
                    ? {
                        aspectRatio: `${activeFullscreenDimensions.width} / ${activeFullscreenDimensions.height}`,
                        transform: `translate(${fullscreenPan.x}px, ${fullscreenPan.y}px) scale(${fullscreenZoom})`,
                        transformOrigin: 'center center',
                      }
                    : {
                        transform: `translate(${fullscreenPan.x}px, ${fullscreenPan.y}px) scale(${fullscreenZoom})`,
                        transformOrigin: 'center center',
                      }
                }
              />
            </div>
            <div className="mx-auto mt-3 flex w-full max-w-2xl items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-8 shrink-0 p-0"
                onClick={goToPreviousImage}
                disabled={!canGoToPreviousImage}
                aria-label={t('Previous image')}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <div ref={thumbnailStripRef} className="flex-1 overflow-x-auto">
                <div className="flex min-w-max gap-1.5">
                  {imageAttachments.map((imageAttachment, index) => (
                    <button
                      key={imageAttachment.id}
                      ref={(element) => {
                        thumbnailButtonRefs.current[imageAttachment.id] =
                          element
                      }}
                      type="button"
                      onClick={() => setFullscreenAttachment(index)}
                      className={cn(
                        'relative aspect-square w-28 shrink-0 cursor-pointer overflow-hidden rounded-md border bg-muted/20 transition-colors sm:w-32',
                        index === fullscreenAttachment
                          ? 'border-primary ring-1 ring-primary/50 dark:border-sidebar-accent dark:ring-sidebar-accent/70'
                          : 'border-border hover:border-primary/50 dark:hover:border-sidebar-accent',
                      )}
                    >
                      <img
                        src={imageAttachment.previewUrl!}
                        alt={imageAttachment.name}
                        className="h-full w-full object-cover"
                        loading="lazy"
                      />
                    </button>
                  ))}
                </div>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-8 shrink-0 p-0"
                onClick={goToNextImage}
                disabled={!canGoToNextImage}
                aria-label={t('Next image')}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          </div>
        </WizardLayout>
      ) : null}
    </>
  )
}

const AssistantMessageRow = memo(
  function AssistantMessageRow({
    message,
    messageAttachments,
    deferCodeBlocks,
    placeholderCandidates,
    copied,
    showDebug = false,
    onCopyMessage,
    onStartEditResend,
    onRetry,
    canRetry = false,
  }: AssistantMessageRowProps) {
    const t = useT()
    const messageId = message.$id
    const role = message.role
    const messageText = message.contentText || ''
    const isUserMessage = role.toLowerCase() === 'user'
    const isRtlMessage = useMemo(
      () => isRtlMessageText(messageText),
      [messageText],
    )
    const alignRight = isUserMessage ? !isRtlMessage : isRtlMessage
    const attachmentsAlignment: 'left' | 'right' = alignRight ? 'right' : 'left'
    const placeholderTokens = useMemo(
      () => extractPlaceholderTokens(messageText),
      [messageText],
    )
    const [selectedPlaceholderValues, setSelectedPlaceholderValues] = useState<
      Partial<Record<PlaceholderToken, string>>
    >({})

    const autoResolvedValues = useMemo(() => {
      const values: Partial<Record<PlaceholderToken, string>> = {}
      for (const token of placeholderTokens) {
        const options = placeholderCandidates[token] ?? []
        if (options.length === 1) {
          values[token] = options[0]
        }
      }
      return values
    }, [placeholderTokens, placeholderCandidates])

    const unresolvedSelectableTokens = useMemo(
      () =>
        placeholderTokens.filter((token) => {
          if (autoResolvedValues[token]) return false
          return (placeholderCandidates[token]?.length ?? 0) > 0
        }),
      [placeholderCandidates, placeholderTokens, autoResolvedValues],
    )

    const resolvedAssistantText = useMemo(() => {
      const values: Partial<Record<PlaceholderToken, string>> = {
        ...autoResolvedValues,
        ...selectedPlaceholderValues,
      }
      return applyPlaceholderValues(messageText, values)
    }, [autoResolvedValues, messageText, selectedPlaceholderValues])

    useEffect(() => {
      setSelectedPlaceholderValues({})
    }, [messageText])

    return (
      <div className="group/message cursor-default space-y-1.5">
        <div
          className={cn('flex', alignRight ? 'justify-end' : 'justify-start')}
        >
          {isUserMessage ? (
            <div
              className={cn(
                'flex max-w-[88%] flex-col',
                alignRight ? 'items-end' : 'items-start',
              )}
            >
              <div
                dir={isRtlMessage ? 'rtl' : 'ltr'}
                className={cn(
                  'inline-block max-w-full cursor-default rounded-md bg-primary px-2.5 py-1.5 text-[13px] whitespace-pre-wrap text-primary-foreground dark:bg-sidebar-accent dark:text-sidebar-foreground',
                )}
              >
                {messageText}
              </div>
              <MessageAttachments
                attachmentIds={messageAttachments}
                alignment={attachmentsAlignment}
              />
              {showDebug ? (
                <div className="mt-1 w-full">
                  <AssistantMessageDebugCard
                    message={message}
                    align={alignRight ? 'end' : 'start'}
                  />
                </div>
              ) : null}
              <div
                dir="ltr"
                className={cn(
                  'mt-1 flex h-5 w-full items-center gap-0.5 opacity-0 transition-opacity duration-150 pointer-events-none group-hover/message:opacity-100 group-hover/message:pointer-events-auto group-focus-within/message:opacity-100 group-focus-within/message:pointer-events-auto',
                  alignRight ? 'justify-end' : 'justify-start',
                )}
              >
                {/* time ago hidden for now */}
                {alignRight ? (
                  <>
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() => onCopyMessage(messageId, messageText)}
                      aria-label={t('Copy message')}
                    >
                      {copied ? (
                        <Check className="h-2.5 w-2.5" />
                      ) : (
                        <Copy className="h-2.5 w-2.5" />
                      )}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() =>
                        onStartEditResend(
                          messageId,
                          messageText,
                          messageAttachments ?? [],
                        )
                      }
                      aria-label={t('Edit and resend message')}
                    >
                      <Pencil className="h-2.5 w-2.5" />
                    </Button>
                  </>
                ) : (
                  <>
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() =>
                        onStartEditResend(
                          messageId,
                          messageText,
                          messageAttachments ?? [],
                        )
                      }
                      aria-label={t('Edit and resend message')}
                    >
                      <Pencil className="h-2.5 w-2.5" />
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() => onCopyMessage(messageId, messageText)}
                      aria-label={t('Copy message')}
                    >
                      {copied ? (
                        <Check className="h-2.5 w-2.5" />
                      ) : (
                        <Copy className="h-2.5 w-2.5" />
                      )}
                    </Button>
                  </>
                )}
                {/* time ago hidden for now */}
              </div>
            </div>
          ) : (
            <div
              dir={isRtlMessage ? 'rtl' : 'ltr'}
              className="max-w-[88%] cursor-default px-2.5 py-1.5 text-[13px] text-foreground"
            >
              <div className="space-y-2">
                <AssistantTurnActivity message={message} />
                {unresolvedSelectableTokens.length > 0 && (
                  <div className="rounded-md border border-border bg-muted/20 p-2">
                    <p className="mb-1.5 text-[11px] text-muted-foreground">
                      {t('Select values for placeholders')}
                    </p>
                    <div className="space-y-1.5">
                      {unresolvedSelectableTokens.map((token) => (
                        <div
                          key={token}
                          className="flex items-center gap-2 text-[12px]"
                        >
                          <span className="w-[122px] shrink-0 text-muted-foreground">
                            {t(PLACEHOLDER_LABELS[token])}
                          </span>
                          <Select
                            value={selectedPlaceholderValues[token]}
                            onValueChange={(value) =>
                              setSelectedPlaceholderValues((prev) => ({
                                ...prev,
                                [token]: value,
                              }))
                            }
                          >
                            <SelectTrigger className="h-8 min-w-0 flex-1 text-[12px]">
                              <SelectValue placeholder={token} />
                            </SelectTrigger>
                            <SelectContent>
                              {(placeholderCandidates[token] ?? []).map(
                                (option) => (
                                  <SelectItem
                                    key={`${token}-${option}`}
                                    value={option}
                                    className="text-[12px]"
                                  >
                                    {option}
                                  </SelectItem>
                                ),
                              )}
                            </SelectContent>
                          </Select>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
                {messageText.trim() ? (
                  <StreamingMarkdown
                    content={resolvedAssistantText}
                    deferCodeBlocks={deferCodeBlocks}
                  />
                ) : null}
                {showDebug ? (
                  <AssistantMessageDebugCard
                    message={message}
                    align={alignRight ? 'end' : 'start'}
                  />
                ) : null}
                <div
                  dir={isRtlMessage ? 'rtl' : 'ltr'}
                  className={cn(
                    'mt-1 flex h-5 w-full items-center justify-start gap-0.5 opacity-0 transition-opacity duration-150 pointer-events-none group-hover/message:opacity-100 group-hover/message:pointer-events-auto group-focus-within/message:opacity-100 group-focus-within/message:pointer-events-auto',
                  )}
                >
                  {/* time ago hidden for now */}
                  {messageText.trim() ? (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() => onCopyMessage(messageId, messageText)}
                      aria-label={t('Copy message')}
                    >
                      {copied ? (
                        <Check className="h-2.5 w-2.5" />
                      ) : (
                        <Copy className="h-2.5 w-2.5" />
                      )}
                    </Button>
                  ) : null}
                  {canRetry && onRetry ? (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() => onRetry(messageId)}
                      aria-label={t('Retry')}
                    >
                      <RefreshCw className="h-2.5 w-2.5" />
                    </Button>
                  ) : null}
                  {/* time ago hidden for now */}
                </div>
              </div>
            </div>
          )}
        </div>
        {!isUserMessage ? (
          <MessageAttachments
            attachmentIds={messageAttachments}
            alignment={attachmentsAlignment}
          />
        ) : null}
      </div>
    )
  },
  (prev, next) =>
    prev.message === next.message &&
    prev.messageAttachments === next.messageAttachments &&
    prev.deferCodeBlocks === next.deferCodeBlocks &&
    prev.canRetry === next.canRetry &&
    prev.showDebug === next.showDebug &&
    prev.placeholderCandidates === next.placeholderCandidates &&
    prev.copied === next.copied,
)

const TYPING_IDLE_ACTIVITY = 0.15
const THINKING_ACTIVITY = 0.55

const BUBBLE_PHASE_ACTIVITY: Record<AssistantBubblePhase, number> = {
  idle: TYPING_IDLE_ACTIVITY,
  waiting: 0.48,
  routing: 0.62,
  working: 0.78,
  answering: 0.42,
}

export type BubbleActivityDebugMode =
  | 'auto'
  | 'idle'
  | 'slow'
  | 'fast'
  | 'thinking'
  | 'max'

const DEBUG_MODE_ACTIVITY: Record<
  Exclude<BubbleActivityDebugMode, 'auto'>,
  number
> = {
  idle: TYPING_IDLE_ACTIVITY,
  slow: 0.42,
  fast: 0.88,
  thinking: THINKING_ACTIVITY,
  max: 1.12,
}

function useTypingSpeedActivity(
  isActive: boolean,
  phase: AssistantBubblePhase,
  debugMode: BubbleActivityDebugMode = 'auto',
) {
  const activityRef = useRef(TYPING_IDLE_ACTIVITY)
  const lastKeystrokeRef = useRef<number | null>(null)
  /** Smoothed ms between keystrokes - lower means faster typing */
  const emaIntervalRef = useRef(320)
  const phaseRef = useRef(phase)
  const isActiveRef = useRef(isActive)
  const debugModeRef = useRef(debugMode)

  useEffect(() => {
    phaseRef.current = phase
  }, [phase])

  useEffect(() => {
    isActiveRef.current = isActive
  }, [isActive])

  useEffect(() => {
    debugModeRef.current = debugMode
  }, [debugMode])

  const registerKeystroke = useCallback(() => {
    const now = performance.now()
    const last = lastKeystrokeRef.current

    if (last != null) {
      const interval = Math.max(now - last, 25)
      emaIntervalRef.current = emaIntervalRef.current * 0.3 + interval * 0.7
    }

    lastKeystrokeRef.current = now
  }, [])

  useEffect(() => {
    if (!isActive) {
      activityRef.current = TYPING_IDLE_ACTIVITY
      lastKeystrokeRef.current = null
      emaIntervalRef.current = 320
      return
    }

    let raf = 0
    let lastTime = performance.now()

    const tick = (now: number) => {
      if (!isActiveRef.current) return

      const dt = Math.min(0.05, (now - lastTime) / 1000)
      lastTime = now

      const debugOverride = debugModeRef.current
      if (debugOverride !== 'auto') {
        const target = DEBUG_MODE_ACTIVITY[debugOverride]
        const prev = activityRef.current
        activityRef.current =
          prev + (target - prev) * (1 - Math.exp(-12 * dt))
        raf = requestAnimationFrame(tick)
        return
      }

      const phaseActivity = BUBBLE_PHASE_ACTIVITY[phaseRef.current]
      const sinceLast =
        lastKeystrokeRef.current != null
          ? now - lastKeystrokeRef.current
          : Number.POSITIVE_INFINITY

      if (sinceLast > 90) {
        const idleDrift = Math.min(1, (sinceLast - 90) / 500)
        emaIntervalRef.current +=
          (360 - emaIntervalRef.current) * 0.018 * idleDrift
      }

      const interval = Math.max(emaIntervalRef.current, 45)
      const speedT = Math.min(1, Math.max(0, (260 - interval) / 165))
      let typingTarget =
        TYPING_IDLE_ACTIVITY + speedT * (1.05 - TYPING_IDLE_ACTIVITY)

      if (sinceLast < 160) {
        const activeBoost = 1 + (1 - sinceLast / 160) * 0.4
        typingTarget = Math.min(1.12, typingTarget * activeBoost)
      }

      if (sinceLast > 900) {
        typingTarget = TYPING_IDLE_ACTIVITY
      }

      // During a live turn, phase activity owns the floor; typing can still lift it.
      const target =
        phaseRef.current === 'idle'
          ? typingTarget
          : Math.max(phaseActivity, sinceLast < 900 ? typingTarget * 0.35 : 0)

      const prev = activityRef.current
      const smoothRate = target >= prev ? 14 : 3.5
      activityRef.current =
        prev + (target - prev) * (1 - Math.exp(-smoothRate * dt))

      raf = requestAnimationFrame(tick)
    }

    raf = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(raf)
  }, [isActive])

  return { activityRef, registerKeystroke }
}

const BUBBLE_DEBUG_MODES: Array<{
  id: BubbleActivityDebugMode
  label: string
}> = [
  { id: 'auto', label: 'Auto' },
  { id: 'idle', label: 'Idle' },
  { id: 'slow', label: 'Slow type' },
  { id: 'fast', label: 'Fast type' },
  { id: 'thinking', label: 'Thinking' },
  { id: 'max', label: 'Max' },
]

const SPHERE_DEBUG_SLIDER_CLASS =
  'py-1 [&_[data-slot=slider-range]]:bg-purple-600 [&_[data-slot=slider-thumb]]:border-purple-600 dark:[&_[data-slot=slider-range]]:bg-purple-500 dark:[&_[data-slot=slider-thumb]]:border-purple-500'

const SPHERE_COLOR_DEBUG_MODES: Array<{
  id: SphereColorMode
  label: string
}> = [
  { id: 'brand', label: 'Brand' },
  { id: 'blue', label: 'Blue' },
  { id: 'green', label: 'Green' },
  { id: 'purple', label: 'Purple' },
  { id: 'amber', label: 'Amber' },
  { id: 'cyan', label: 'Cyan' },
]

const SPHERE_SHAPE_DEBUG_MODES: Array<{
  id: SphereShapeMode
  label: string
}> = [
  { id: 'sphere', label: 'Sphere' },
  { id: 'torus', label: 'Torus' },
  { id: 'disc', label: 'Disc' },
  { id: 'ring', label: 'Ring' },
  { id: 'cube', label: 'Cube' },
  { id: 'helix', label: 'Helix' },
]

const SPHERE_BASE_SIZES = {
  /** Empty conversation placeholder only */
  empty: 220,
} as const

function debugControlButtonClass(isActive: boolean) {
  return cn(
    'h-7 px-2 text-[11px]',
    isActive
      ? 'border-purple-600 bg-purple-600 text-white hover:bg-purple-600/90 dark:border-purple-500 dark:bg-purple-500 dark:hover:bg-purple-500/90'
      : 'border-purple-500/40 text-purple-600 hover:bg-purple-500/10 hover:text-purple-700 dark:border-purple-400/40 dark:text-purple-400 dark:hover:bg-purple-500/15 dark:hover:text-purple-300',
  )
}

function AssistantBubbleDebugControls({
  expanded,
  onExpandedChange,
  activityMode,
  onActivityModeChange,
  sizeScale,
  sizeScaleOverride,
  onSizeScaleChange,
  onSizeScaleDefault,
  colorMode,
  onColorModeChange,
  shapeMode,
  onShapeModeChange,
  particleCountOverride,
  autoParticleCount,
  onParticleCountChange,
  onParticleCountAuto,
}: {
  expanded: boolean
  onExpandedChange: (expanded: boolean) => void
  activityMode: BubbleActivityDebugMode
  onActivityModeChange: (mode: BubbleActivityDebugMode) => void
  sizeScale: number
  sizeScaleOverride: number | null
  onSizeScaleChange: (scale: number) => void
  onSizeScaleDefault: () => void
  colorMode: SphereColorMode
  onColorModeChange: (mode: SphereColorMode) => void
  shapeMode: SphereShapeMode
  onShapeModeChange: (mode: SphereShapeMode) => void
  particleCountOverride: number | null
  autoParticleCount: number
  onParticleCountChange: (count: number) => void
  onParticleCountAuto: () => void
}) {
  const particleSliderValue = particleCountOverride ?? autoParticleCount
  const sizePercent = Math.round(sizeScale * 100)

  return (
    <div className="rounded-lg border border-purple-500/25 bg-purple-500/5">
      <button
        type="button"
        onClick={() => onExpandedChange(!expanded)}
        aria-expanded={expanded}
        className="flex w-full items-center gap-2 px-2.5 py-2 text-start transition-colors hover:bg-purple-500/10"
      >
        <span className="min-w-0 flex-1 text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
          Bubble debug
        </span>
        {!expanded ? (
          <span className="truncate text-[10px] tabular-nums text-purple-600/70 dark:text-purple-400/70">
            {activityMode} · {sizePercent}% · {shapeMode}
          </span>
        ) : null}
        {expanded ? (
          <ChevronUp className="h-3.5 w-3.5 shrink-0 text-purple-600/80 dark:text-purple-400/80" />
        ) : (
          <ChevronDown className="h-3.5 w-3.5 shrink-0 text-purple-600/80 dark:text-purple-400/80" />
        )}
      </button>
      {expanded ? (
        <div className="space-y-2 border-t border-purple-500/20 p-2.5 pt-2">
          <div>
            <p className="mb-1.5 text-center text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
              Activity
            </p>
            <div className="flex flex-wrap justify-center gap-1.5">
              {BUBBLE_DEBUG_MODES.map(({ id, label }) => (
                <Button
                  key={id}
                  type="button"
                  size="sm"
                  variant="outline"
                  className={debugControlButtonClass(activityMode === id)}
                  onClick={() => onActivityModeChange(id)}
                >
                  {label}
                </Button>
              ))}
            </div>
          </div>
          <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
              <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
                Size
              </p>
              <div className="flex items-center gap-2">
                <span className="text-[11px] tabular-nums text-purple-600 dark:text-purple-400">
                  {sizePercent}%
                  {sizeScaleOverride == null ? ' (default)' : ''}
                </span>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className={debugControlButtonClass(sizeScaleOverride == null)}
                  onClick={onSizeScaleDefault}
                >
                  Default
                </Button>
              </div>
            </div>
            <Slider
              min={SPHERE_SIZE_SCALE_MIN}
              max={SPHERE_SIZE_SCALE_MAX}
              step={SPHERE_SIZE_SCALE_STEP}
              value={[sizeScale]}
              onValueChange={(values) => {
                const next = values[0]
                if (next != null) onSizeScaleChange(next)
              }}
              className={SPHERE_DEBUG_SLIDER_CLASS}
            />
          </div>
          <div>
            <p className="mb-1.5 text-center text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
              Color
            </p>
            <div className="flex flex-wrap justify-center gap-1.5">
              {SPHERE_COLOR_DEBUG_MODES.map(({ id, label }) => (
                <Button
                  key={id}
                  type="button"
                  size="sm"
                  variant="outline"
                  className={debugControlButtonClass(colorMode === id)}
                  onClick={() => onColorModeChange(id)}
                >
                  {label}
                </Button>
              ))}
            </div>
          </div>
          <div>
            <p className="mb-1.5 text-center text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
              Shape
            </p>
            <div className="flex flex-wrap justify-center gap-1.5">
              {SPHERE_SHAPE_DEBUG_MODES.map(({ id, label }) => (
                <Button
                  key={id}
                  type="button"
                  size="sm"
                  variant="outline"
                  className={debugControlButtonClass(shapeMode === id)}
                  onClick={() => onShapeModeChange(id)}
                >
                  {label}
                </Button>
              ))}
            </div>
          </div>
          <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
              <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
                Particles
              </p>
              <div className="flex items-center gap-2">
                <span className="text-[11px] tabular-nums text-purple-600 dark:text-purple-400">
                  {particleSliderValue.toLocaleString()}
                  {particleCountOverride == null ? ' (auto)' : ''}
                </span>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className={debugControlButtonClass(
                    particleCountOverride == null,
                  )}
                  onClick={onParticleCountAuto}
                >
                  Auto
                </Button>
              </div>
            </div>
            <Slider
              min={SPHERE_PARTICLE_COUNT_MIN}
              max={SPHERE_PARTICLE_COUNT_MAX}
              step={SPHERE_PARTICLE_COUNT_STEP}
              value={[particleSliderValue]}
              onValueChange={(values) => {
                const next = values[0]
                if (next != null) onParticleCountChange(next)
              }}
              className={SPHERE_DEBUG_SLIDER_CLASS}
            />
          </div>
        </div>
      ) : null}
    </div>
  )
}

export function AIChatPanelContent({
  variant = 'pane',
}: {
  /** `page` = dedicated /assistant fullscreen surface (public + optional auth). */
  variant?: 'pane' | 'page'
} = {}) {
  const t = useT()
  const navigate = useNavigate()
  const { closeChat, activeConversationId, setActiveConversationId } =
    useAIChat()
  const params = useParams({ strict: false }) as {
    projectId?: string
    orgId?: string
    teamId?: string
  }
  const location = useLocation()
  const isPageVariant = variant === 'page'
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const { isDebugModeOpen } = useDebugMode()
  const [themeMounted, setThemeMounted] = useState(false)
  const { theme, resolvedTheme } = useTheme()
  useEffect(() => {
    setThemeMounted(true)
  }, [])
  const headerLogoClassName = getConsoleHeaderLogoClass(
    theme,
    resolvedTheme,
    themeMounted,
  )
  const [bubbleDebugExpanded, setBubbleDebugExpanded] = useState(false)
  const [bubbleDebugMode, setBubbleDebugMode] =
    useState<BubbleActivityDebugMode>('auto')
  const [sphereSizeScaleOverride, setSphereSizeScaleOverride] =
    useState<number | null>(null)
  const [sphereColorMode, setSphereColorMode] =
    useState<SphereColorMode>('brand')
  const [sphereShapeMode, setSphereShapeMode] =
    useState<SphereShapeMode>('sphere')
  const [sphereParticleCountOverride, setSphereParticleCountOverride] =
    useState<number | null>(null)
  const effectiveSphereSizeScale =
    sphereSizeScaleOverride ?? SPHERE_SIZE_SCALE_DEFAULT
  const sphereAutoParticleCount = useMemo(
    () =>
      defaultParticleCountForSize(
        scaleSphereSize(SPHERE_BASE_SIZES.empty, effectiveSphereSizeScale),
      ),
    [effectiveSphereSizeScale],
  )
  const getSphereRenderSize = useCallback(
    (baseSize: number) =>
      isDebugModeOpen
        ? scaleSphereSize(baseSize, effectiveSphereSizeScale)
        : baseSize,
    [effectiveSphereSizeScale, isDebugModeOpen],
  )
  const effectiveSphereColorMode = isDebugModeOpen ? sphereColorMode : 'brand'
  const effectiveSphereShapeMode = isDebugModeOpen ? sphereShapeMode : 'sphere'
  const effectiveSphereParticleCount = isDebugModeOpen
    ? (sphereParticleCountOverride ?? undefined)
    : undefined
  const [input, setInput] = useState('')
  const [messageQueue, setMessageQueue] = useState<QueuedComposerMessage[]>([])
  const [messageQueueExpanded, setMessageQueueExpanded] = useState(true)
  const [restoredQueueAttachmentIds, setRestoredQueueAttachmentIds] = useState<
    string[]
  >([])
  const isDrainingQueueRef = useRef(false)
  const queuePausedUntilIdleRef = useRef(false)
  const [messagesLimit, setMessagesLimit] = useState(
    ASSISTANT_MESSAGES_PAGE_SIZE,
  )
  const [isLoadingOlderMessages, setIsLoadingOlderMessages] = useState(false)
  const [copiedMessageId, setCopiedMessageId] = useState<string | null>(null)
  const [isCopyingConversation, setIsCopyingConversation] = useState(false)
  const [copiedConversation, setCopiedConversation] = useState(false)
  const copiedConversationTimeoutRef = useRef<number | null>(null)
  const [editingMessageId, setEditingMessageId] = useState<string | null>(null)
  const [editingMessageAttachments, setEditingMessageAttachments] = useState<
    string[]
  >([])
  const [pendingAttachments, setPendingAttachments] = useState<
    ComposerPendingAttachment[]
  >([])
  const [composerImageOrientations, setComposerImageOrientations] = useState<
    Record<string, 'portrait' | 'landscape'>
  >({})
  const composerAvifSupported = useAvifSupport()
  const pendingAttachmentsRef = useRef<ComposerPendingAttachment[]>([])
  const uploadTasksRef = useRef<Map<string, Promise<void>>>(new Map())
  const [isWaitingForAttachments, setIsWaitingForAttachments] = useState(false)
  const [conversationsPopoverOpen, setConversationsPopoverOpen] =
    useState(false)
  const [conversationsMenuTab, setConversationsMenuTab] = useState<
    'agents' | 'automations'
  >('agents')
  const [conversationSearch, setConversationSearch] = useState('')
  const [debouncedConversationSearch, setDebouncedConversationSearch] =
    useState('')
  const [collapsedConversationGroups, setCollapsedConversationGroups] =
    useState<Set<string>>(() => new Set())
  const [archivedSectionOpen, setArchivedSectionOpen] = useState(false)
  const [pinnedSectionOpen, setPinnedSectionOpen] = useState(true)
  const [headerRenameOpen, setHeaderRenameOpen] = useState(false)
  const [pinnedDragId, setPinnedDragId] = useState<string | null>(null)
  const [pinnedDragOverId, setPinnedDragOverId] = useState<string | null>(null)
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const messagesContainerRef = useRef<HTMLDivElement>(null)
  const messagesContentRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const copiedMessageTimeoutRef = useRef<number | null>(null)
  const previousConversationIdRef = useRef<string | null>(null)
  const shouldAutoScrollRef = useRef(true)
  const isProgrammaticScrollRef = useRef(false)
  const [isStickToBottom, setIsStickToBottom] = useState(true)
  const olderMessagesAnchorRef = useRef<{
    scrollTop: number
    scrollHeight: number
  } | null>(null)
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedConversationSearch(conversationSearch.trim())
    }, 300)
    return () => window.clearTimeout(timer)
  }, [conversationSearch])

  const { account, isAuthenticated, isLoading: authLoading } = useAuth()
  const isGuest = !authLoading && !isAuthenticated
  const interactionsDisabled = isGuest || authLoading
  const { isExpanded: isChatExpanded, setIsExpanded: setIsChatExpanded } =
    useAIChatExpanded(account)
  const {
    pinnedConversationIds,
    setPinnedConversationIds,
    pinConversation,
    unpinConversation,
  } = useAIChatPinnedConversationIds(account)
  const effectiveExpanded = isPageVariant || isChatExpanded
  const queryClient = useQueryClient()

  const {
    data: conversationsData,
    isLoading: conversationsLoading,
  } = useAssistantConversations(debouncedConversationSearch || undefined, {
    enabled: isAuthenticated,
  })
  const conversations: AssistantConversation[] = conversationsData ?? []
  const hasConversationSearch = debouncedConversationSearch.length > 0
  const { data: mcpConnections = [] } = useAssistantMcpConnections({
    enabled: isAuthenticated,
  })
  const hasActiveMcp = useMemo(
    () =>
      mcpConnections.some(
        (connection) => connection.hasTokens && connection.enabled,
      ),
    [mcpConnections],
  )
  const emptyStateSuggestions = hasActiveMcp
    ? mcpSuggestedQuestions
    : suggestedQuestions
  const createConversationMutation = useCreateAssistantConversation()
  const deleteConversationMutation = useDeleteAssistantConversation()
  const createMessageMutation = useCreateAssistantMessage()
  const updateMessageMutation = useUpdateAssistantMessage()
  const updateConversationMutation = useUpdateAssistantConversation()
  const uploadAssistantAttachmentsMutation = useUploadAssistantAttachments()

  const activeConversation = useMemo(
    () =>
      conversations.find(
        (conversation) => conversation.$id === activeConversationId,
      ),
    [activeConversationId, conversations],
  )
  const contextProjectId =
    nonEmptyId(params.projectId) ??
    assistantConversationProjectId(activeConversation)
  const { project, isLoading: projectLoading } = useProject(contextProjectId)
  const accountId = (account as { $id?: string } | undefined)?.$id ?? null
  const organizationId =
    nonEmptyId(params.orgId) ??
    nonEmptyId(params.teamId) ??
    nonEmptyId(project?.teamId) ??
    null
  /** On project routes, team channel comes from project fetch - wait so we do not reconnect per layer. */
  const waitingForProjectTeam =
    Boolean(contextProjectId) &&
    !params.orgId &&
    !params.teamId &&
    projectLoading
  const assistantRealtimeChannels = useMemo(
    () =>
      buildAssistantRealtimeChannels({
        projectId: contextProjectId,
        organizationId,
        accountId,
      }),
    [accountId, contextProjectId, organizationId],
  )
  const canSubscribeAssistantRealtime =
    accountId != null && !waitingForProjectTeam

  useEffect(() => {
    if (!canSubscribeAssistantRealtime) return

    let cancelled = false
    let unregister: (() => Promise<void>) | null = null

    const handleRealtimeEvent = (response: RealtimeResponseEvent<unknown>) => {
      const hasAssistantEvent =
        response.events.some((eventName) => eventName.includes('assistant')) ||
        response.channels.some((channel) => channel.includes('assistant'))

      if (!hasAssistantEvent) return

      const payload =
        response.payload && typeof response.payload === 'object'
          ? (response.payload as Record<string, unknown>)
          : null

      // Merge message/tool/conversation payloads for live streaming; avoid refetch storms.
      if (applyAssistantRealtimePayload(queryClient, response.events, payload)) {
        return
      }

      const eventNames = response.events.map((eventName) =>
        eventName.toLowerCase(),
      )
      const isConversationEvent = eventNames.some(
        (eventName) =>
          eventName.includes('assistantconversations') ||
          eventName.includes('assistant.conversations'),
      )
      const conversationId =
        typeof payload?.conversationId === 'string'
          ? payload.conversationId
          : typeof payload?.$id === 'string' && isConversationEvent
            ? payload.$id
            : null

      // Prefer cache merges above. Invalidate only as a last resort for unknown
      // assistant payloads, and never broadcast-invalidate all message queries.
      if (isConversationEvent || !conversationId) {
        queryClient.invalidateQueries({
          queryKey: ['assistant', 'conversations'],
        })
      }

      if (conversationId) {
        queryClient.invalidateQueries({
          queryKey: ['assistant', 'messages', conversationId],
        })
      }
    }

    registerConsoleRealtimeListener(assistantRealtimeChannels, handleRealtimeEvent)
      .then((unreg) => {
        if (cancelled) {
          void unreg()
          return
        }
        unregister = unreg
      })
      .catch(() => {
        // Keep chat usable even if realtime fails.
      })

    return () => {
      cancelled = true
      if (unregister) {
        void unregister()
      }
    }
  }, [
    assistantRealtimeChannels,
    canSubscribeAssistantRealtime,
    queryClient,
  ])

  const placeholderCandidates = useMemo(() => {
    const region =
      project?.region && project.region.toLowerCase() !== 'unknown'
        ? project.region
        : undefined
    const endpoint = getApiEndpoint(region)
    return {
      '{{APPWRITE_ENDPOINT}}': dedupeValues([endpoint]),
      '{{APPWRITE_REGION}}': dedupeValues([region]),
      '{{APPWRITE_PROJECT_ID}}': dedupeValues([
        params.projectId,
        assistantConversationProjectId(activeConversation),
        project?.$id,
      ]),
      '{{APPWRITE_PROJECT_NAME}}': dedupeValues([project?.name]),
      '{{APPWRITE_TEAM_ID}}': dedupeValues([
        params.teamId,
        project?.teamId,
        params.orgId,
      ]),
      '{{APPWRITE_ORGANIZATION_ID}}': dedupeValues([
        params.orgId,
        params.teamId,
        project?.teamId,
      ]),
      '{{APPWRITE_USER_ID}}': dedupeValues([
        (account as { $id?: string } | undefined)?.$id,
      ]),
    } as Partial<Record<PlaceholderToken, string[]>>
  }, [
    activeConversation,
    account,
    params.orgId,
    params.projectId,
    params.teamId,
    project?.$id,
    project?.name,
    project?.region,
    project?.teamId,
  ])

  const { data: messagesData, isFetching: isFetchingMessages } =
    useAssistantMessages(activeConversationId, messagesLimit)
  const messages: AssistantMessage[] = messagesData?.messages ?? []
  const totalMessages = messagesData?.total ?? messages.length
  const hasOlderMessages = totalMessages > messages.length
  const { data: editingAttachmentFilesData } = useAssistantAttachmentFiles(
    editingMessageAttachments,
  )
  const latestMessageId = messages[messages.length - 1]?.$id
  const latestMessage = messages[messages.length - 1]

  const isConversationRunning = useMemo(
    () => isAssistantConversationInFlight(activeConversation),
    [activeConversation],
  )

  const latestAssistantMessage = useMemo(
    () =>
      [...messages]
        .reverse()
        .find((message) => message.role.toLowerCase() !== 'user'),
    [messages],
  )

  const activeAssistantMessageId =
    activeConversation?.activeMessageId ||
    (isAssistantMessageInFlight(latestAssistantMessage?.status)
      ? latestAssistantMessage?.$id
      : undefined)

  const isLatestAssistantMessageRunning = useMemo(
    () => isAssistantMessageInFlight(latestAssistantMessage?.status),
    [latestAssistantMessage?.status],
  )

  const waitingForAssistantReply =
    latestMessage?.role?.toLowerCase() === 'user' && isConversationRunning

  const liveTurnView = useMemo(() => {
    if (!latestAssistantMessage) return null
    return buildTurnView(latestAssistantMessage)
  }, [latestAssistantMessage])

  const liveTurnStatusLabel = liveTurnView?.statusLabel

  const isSending =
    createMessageMutation.isPending || updateMessageMutation.isPending

  const isThinking =
    isSending ||
    isLatestAssistantMessageRunning ||
    waitingForAssistantReply

  const bubblePhase = useMemo(
    () =>
      getAssistantBubblePhase({
        isConversationRunning: isConversationRunning || isSending,
        isSending,
        latestUserWaiting: waitingForAssistantReply,
        message: latestAssistantMessage,
        turn: liveTurnView,
      }),
    [
      isConversationRunning,
      isSending,
      latestAssistantMessage,
      liveTurnView,
      waitingForAssistantReply,
    ],
  )

  const canSendWhileIdle =
    !isConversationRunning &&
    !createMessageMutation.isPending &&
    !updateMessageMutation.isPending &&
    !updateConversationMutation.isPending

  const { activityRef: bubbleActivityRef, registerKeystroke: registerTypingKeystroke } =
    useTypingSpeedActivity(
      !isAssistantBlocked,
      bubblePhase,
      bubbleDebugMode,
    )

  const hasUploadingAttachments = pendingAttachments.some(
    (attachment) => attachment.status === 'uploading',
  )
  const orderedPendingAttachments = useMemo(
    () => [...pendingAttachments].reverse(),
    [pendingAttachments],
  )
  const isInputRtl = useMemo(() => isRtlMessageText(input), [input])
  const focusInput = useCallback((placeCursorAtEnd = false) => {
    if (typeof window === 'undefined') return
    window.setTimeout(() => {
      const inputElement = inputRef.current
      if (!inputElement) return
      inputElement.focus()

      if (placeCursorAtEnd) {
        const endPosition = inputElement.value.length
        inputElement.setSelectionRange(endPosition, endPosition)
      }
    }, 0)
  }, [])

  const isNearBottom = useCallback((container: HTMLDivElement) => {
    const distanceFromBottom =
      container.scrollHeight - (container.scrollTop + container.clientHeight)
    return distanceFromBottom <= AUTO_SCROLL_BOTTOM_THRESHOLD
  }, [])

  const scrollMessagesToBottom = useCallback(() => {
    const container = messagesContainerRef.current
    if (!container) return

    isProgrammaticScrollRef.current = true
    container.scrollTop = container.scrollHeight

    // Second pass after layout (markdown, tools, images) settles.
    requestAnimationFrame(() => {
      const el = messagesContainerRef.current
      if (el && shouldAutoScrollRef.current) {
        el.scrollTop = el.scrollHeight
      }
      requestAnimationFrame(() => {
        isProgrammaticScrollRef.current = false
      })
    })
  }, [])

  const pinToBottom = useCallback(() => {
    shouldAutoScrollRef.current = true
    setIsStickToBottom(true)
    scrollMessagesToBottom()
  }, [scrollMessagesToBottom])

  const handleMessagesScroll = useCallback(() => {
    if (isProgrammaticScrollRef.current) return
    if (olderMessagesAnchorRef.current) return

    const container = messagesContainerRef.current
    if (!container) return

    const nearBottom = isNearBottom(container)
    shouldAutoScrollRef.current = nearBottom
    setIsStickToBottom(nearBottom)
  }, [isNearBottom])

  useLayoutEffect(() => {
    const conversationId = activeConversationId ?? null
    const conversationChanged =
      previousConversationIdRef.current !== conversationId

    if (olderMessagesAnchorRef.current && messagesContainerRef.current) {
      const { scrollTop, scrollHeight } = olderMessagesAnchorRef.current
      const newScrollHeight = messagesContainerRef.current.scrollHeight
      isProgrammaticScrollRef.current = true
      messagesContainerRef.current.scrollTop =
        scrollTop + (newScrollHeight - scrollHeight)
      olderMessagesAnchorRef.current = null
      requestAnimationFrame(() => {
        isProgrammaticScrollRef.current = false
      })
    } else if (conversationChanged) {
      shouldAutoScrollRef.current = true
      setIsStickToBottom(true)
      scrollMessagesToBottom()
    } else if (
      latestMessageId &&
      shouldAutoScrollRef.current &&
      messages.length > 0
    ) {
      // New message while pinned (e.g. user send or first assistant token).
      scrollMessagesToBottom()
    }

    previousConversationIdRef.current = conversationId
  }, [
    activeConversationId,
    latestMessageId,
    messages.length,
    scrollMessagesToBottom,
  ])

  // Follow height growth (streaming text, tools, subagents) while pinned.
  useEffect(() => {
    const container = messagesContainerRef.current
    const content = messagesContentRef.current
    if (!container || !content || messages.length === 0) return

    const observer = new ResizeObserver(() => {
      if (!shouldAutoScrollRef.current) return
      if (olderMessagesAnchorRef.current) return
      scrollMessagesToBottom()
    })
    observer.observe(content)
    return () => observer.disconnect()
  }, [messages.length, scrollMessagesToBottom])

  // Focus input when panel content mounts
  useEffect(() => {
    window.setTimeout(() => inputRef.current?.focus(), 300)
  }, [])

  useEffect(() => {
    return () => {
      if (copiedMessageTimeoutRef.current !== null) {
        window.clearTimeout(copiedMessageTimeoutRef.current)
      }
      if (copiedConversationTimeoutRef.current !== null) {
        window.clearTimeout(copiedConversationTimeoutRef.current)
      }
    }
  }, [])

  useEffect(() => {
    pendingAttachmentsRef.current = pendingAttachments
  }, [pendingAttachments])

  // Restore or pick a conversation once account prefs + list are ready.
  // Keep the current selection while searching even if it is filtered out.
  useEffect(() => {
    if (!account || conversationsLoading) return

    if (activeConversationId) {
      if (hasConversationSearch) return
      if (
        conversations.some(
          (conversation: AssistantConversation) =>
            conversation.$id === activeConversationId,
        )
      ) {
        return
      }
    }

    if (conversations.length > 0) {
      setActiveConversationId(conversations[0].$id)
      return
    }

    if (!hasConversationSearch) {
      setActiveConversationId(null)
    }
  }, [
    account,
    activeConversationId,
    conversations,
    conversationsLoading,
    hasConversationSearch,
    setActiveConversationId,
  ])

  useEffect(() => {
    setMessagesLimit(ASSISTANT_MESSAGES_PAGE_SIZE)
    setMessageQueue([])
    setMessageQueueExpanded(true)
    setRestoredQueueAttachmentIds([])
    isDrainingQueueRef.current = false
    queuePausedUntilIdleRef.current = false
  }, [activeConversationId])

  useEffect(() => {
    // Start each conversation in follow mode.
    shouldAutoScrollRef.current = true
    setIsStickToBottom(true)
  }, [activeConversationId])

  useEffect(() => {
    if (isLoadingOlderMessages && !isFetchingMessages) {
      setIsLoadingOlderMessages(false)
    }
  }, [isFetchingMessages, isLoadingOlderMessages])

  const resolveConversationProjectId = async (): Promise<string | null> => {
    const directContextProjectId =
      nonEmptyId(params.projectId) ??
      assistantConversationProjectId(activeConversation) ??
      assistantConversationProjectId(conversations[0])
    if (directContextProjectId) return directContextProjectId

    try {
      const { fetchOrganizations } = await import(
        '@/lib/react-query/hooks/organizations'
      )
      const orgs = await fetchOrganizations()
      for (const org of orgs.teams ?? []) {
        try {
          const projects = await listConsoleProjects({
            organizationId: org.$id,
            queries: [
              Query.equal('teamId', org.$id),
              Query.or([
                Query.isNull('status'),
                Query.notEqual('status', 'archived'),
              ]),
              Query.orderDesc('$createdAt'),
              Query.limit(1),
            ],
            total: false,
          })
          const projectIdFromOrg = projects.projects?.[0]?.$id
          if (projectIdFromOrg) return projectIdFromOrg
        } catch {
          // Try the next organization.
        }
      }
      return null
    } catch {
      return null
    }
  }

  const handleCreateConversation = async () => {
    if (interactionsDisabled) return
    const conversationProjectId = await resolveConversationProjectId()
    if (!conversationProjectId) {
      toast.error(t('No accessible project found to create an agent.'))
      return
    }

    try {
      const conversation = await createConversationMutation.mutateAsync({
        projectId: conversationProjectId,
        title: 'New agent',
      })
      setActiveConversationId(conversation.$id)
      setConversationsPopoverOpen(false)
      focusInput()
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create agent')))
    }
  }

  const selectNextConversation = (conversationId: string) => {
    if (conversationId !== activeConversationId) return
    const nextConversation = conversations.find(
      (conversation) =>
        conversation.$id !== conversationId &&
        conversation.status?.toLowerCase() !== 'archived',
    )
    setActiveConversationId(nextConversation?.$id ?? null)
  }

  const handleArchiveConversation = async (conversationId: string) => {
    if (interactionsDisabled) return
    try {
      await updateConversationMutation.mutateAsync({
        conversationId,
        status: 'archived',
      })
      selectNextConversation(conversationId)
      toast.success(t('Agent archived'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to archive agent')))
    }
  }

  const handleRenameConversation = async (
    conversationId: string,
    title: string,
  ) => {
    if (interactionsDisabled) return
    try {
      await updateConversationMutation.mutateAsync({
        conversationId,
        title,
      })
      toast.success(t('Agent updated'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update agent')))
      throw error
    }
  }

  const handleDeleteConversation = async (conversationId: string) => {
    if (interactionsDisabled) return
    try {
      await deleteConversationMutation.mutateAsync(conversationId)
      unpinConversation(conversationId)
      selectNextConversation(conversationId)
      toast.success(t('Agent deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete agent')))
      throw error
    }
  }

  const handleAttachmentInputClick = useCallback(() => {
    fileInputRef.current?.click()
  }, [])

  const startAttachmentUpload = useCallback(
    (file: File) => {
      const localId = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
      const pendingAttachment: ComposerPendingAttachment = {
        localId,
        name: file.name,
        mimeType: file.type,
        size: file.size,
        status: 'uploading',
      }
      setPendingAttachments((previous) => [...previous, pendingAttachment])

      const contextForUploadProjectId =
        nonEmptyId(params.projectId) ??
        assistantConversationProjectId(activeConversation)
      const uploadPromise = uploadAssistantAttachmentsMutation
        .mutateAsync({
          files: [file],
          projectId: contextForUploadProjectId,
        })
        .then((attachmentIds) => {
          const fileId = attachmentIds[0]
          setPendingAttachments((previous) =>
            previous.map((attachment) =>
              attachment.localId === localId
                ? {
                    ...attachment,
                    status: fileId ? 'ready' : 'failed',
                    fileId,
                  }
                : attachment,
            ),
          )
        })
        .catch(() => {
          setPendingAttachments((previous) =>
            previous.map((attachment) =>
              attachment.localId === localId
                ? {
                    ...attachment,
                    status: 'failed',
                  }
                : attachment,
            ),
          )
        })
        .finally(() => {
          uploadTasksRef.current.delete(localId)
        })

      uploadTasksRef.current.set(localId, uploadPromise)
    },
    [
      activeConversation,
      params.projectId,
      uploadAssistantAttachmentsMutation,
    ],
  )

  const handleAttachmentFileChange = useCallback(
    (event: React.ChangeEvent<HTMLInputElement>) => {
      const files = Array.from(event.target.files ?? [])
      if (!files.length) {
        focusInput()
        return
      }

      files.forEach((file) => startAttachmentUpload(file))
      event.target.value = ''
      focusInput()
    },
    [focusInput, startAttachmentUpload],
  )

  const handleInputPaste = useCallback(
    (event: React.ClipboardEvent<HTMLTextAreaElement>) => {
      const items = Array.from(event.clipboardData?.items ?? [])
      const imageFiles = items
        .filter(
          (item) =>
            item.kind === 'file' &&
            item.type.toLowerCase().startsWith('image/'),
        )
        .map((item) => item.getAsFile())
        .filter((file): file is File => file !== null)

      if (imageFiles.length === 0) return

      event.preventDefault()
      imageFiles.forEach((file) => startAttachmentUpload(file))
      focusInput()
    },
    [focusInput, startAttachmentUpload],
  )

  const handleRemoveAttachment = useCallback((localId: string) => {
    setPendingAttachments((previous) =>
      previous.filter((attachment) => attachment.localId !== localId),
    )
    setComposerImageOrientations((previous) => {
      if (!(localId in previous)) return previous
      const { [localId]: _removed, ...rest } = previous
      return rest
    })
  }, [])

  const handleRemoveEditingAttachment = useCallback((attachmentId: string) => {
    setEditingMessageAttachments((previous) =>
      previous.filter((id) => id !== attachmentId),
    )
  }, [])

  const waitForPendingAttachmentUploads = useCallback(async () => {
    if (
      !pendingAttachmentsRef.current.some(
        (attachment) => attachment.status === 'uploading',
      )
    ) {
      return
    }
    setIsWaitingForAttachments(true)
    const uploadPromises = pendingAttachmentsRef.current
      .filter((attachment) => attachment.status === 'uploading')
      .map((attachment) => uploadTasksRef.current.get(attachment.localId))
      .filter((promise): promise is Promise<void> => !!promise)
    await Promise.allSettled(uploadPromises)
    setIsWaitingForAttachments(false)
  }, [])

  const collectReadyAttachmentIds = useCallback(() => {
    return pendingAttachmentsRef.current
      .filter(
        (
          attachment,
        ): attachment is ComposerPendingAttachment & { fileId: string } =>
          attachment.status === 'ready' && !!attachment.fileId,
      )
      .map((attachment) => attachment.fileId)
  }, [])

  const submitComposerMessage = useCallback(
    async ({
      content,
      attachmentIds,
      editingId,
    }: {
      content: string
      attachmentIds: string[]
      editingId?: string | null
    }) => {
      const trimmed = content.trim()
      if (!trimmed) return false

      const conversationProjectId = await resolveConversationProjectId()
      if (!activeConversationId && !conversationProjectId) {
        toast.error(
          t('No accessible project found to start a new agent.'),
        )
        return false
      }

      shouldAutoScrollRef.current = true
      setIsStickToBottom(true)
      scrollMessagesToBottom()

      let conversationId = activeConversationId

      try {
        if (!conversationId) {
          if (!conversationProjectId) return false
          const createdConversation =
            await createConversationMutation.mutateAsync({
              projectId: conversationProjectId,
              title: makeConversationTitle(trimmed),
            })
          conversationId = createdConversation.$id
          setActiveConversationId(createdConversation.$id)
        }
        if (!conversationId) return false

        const messageContext = {
          contextTeamId:
            nonEmptyId(params.orgId) ??
            nonEmptyId(params.teamId) ??
            nonEmptyId(project?.teamId),
          contextProjectId:
            nonEmptyId(conversationProjectId) ??
            nonEmptyId(params.projectId) ??
            assistantConversationProjectId(activeConversation),
          contextOrganizationId:
            nonEmptyId(params.orgId) ??
            nonEmptyId(params.teamId) ??
            nonEmptyId(project?.teamId),
          contextPagePath: location.pathname,
          contextPageTitle:
            typeof document !== 'undefined' ? document.title : undefined,
          contextPageUrl:
            typeof window !== 'undefined' ? window.location.href : undefined,
        }

        if (editingId) {
          setEditingMessageId(null)
          setEditingMessageAttachments([])
          await updateMessageMutation.mutateAsync({
            conversationId,
            messageId: editingId,
            contentText: trimmed,
            context: messageContext,
            attachments: attachmentIds,
          })
        } else {
          await createMessageMutation.mutateAsync({
            conversationId,
            contentText: trimmed,
            context: messageContext,
            attachments: attachmentIds,
            continueRun: true,
          })
        }

        return true
      } catch (error) {
        toast.error(getErrorMessage(error, t('Failed to send message')))
        return false
      }
    },
    [
      activeConversation,
      activeConversationId,
      createConversationMutation,
      createMessageMutation,
      location.pathname,
      params.orgId,
      params.projectId,
      params.teamId,
      project?.teamId,
      scrollMessagesToBottom,
      setActiveConversationId,
      t,
      updateMessageMutation,
    ],
  )

  const enqueueComposerMessage = useCallback(
    (content: string, attachmentIds: string[]) => {
      const trimmed = content.trim()
      if (!trimmed) return

      const queued: QueuedComposerMessage = {
        id:
          typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : `queue-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        content: trimmed,
        attachmentIds,
      }
      setMessageQueue((current) => [...current, queued])
    },
    [],
  )

  const handleRemoveQueuedMessage = useCallback((queueId: string) => {
    setMessageQueue((current) =>
      current.filter((message) => message.id !== queueId),
    )
  }, [])

  const handlePromoteQueuedMessage = useCallback((queueId: string) => {
    setMessageQueue((current) => {
      const item = current.find((message) => message.id === queueId)
      if (!item) return current
      return [item, ...current.filter((message) => message.id !== queueId)]
    })
  }, [])

  const handleEditQueuedMessage = useCallback(
    (queueId: string) => {
      const item = messageQueue.find((message) => message.id === queueId)
      if (!item) return

      setMessageQueue((current) =>
        current.filter((message) => message.id !== queueId),
      )
      setEditingMessageId(null)
      setEditingMessageAttachments([])
      setPendingAttachments([])
      setComposerImageOrientations({})
      setRestoredQueueAttachmentIds(item.attachmentIds)
      setInput(item.content)
      focusInput(true)
    },
    [focusInput, messageQueue],
  )

  const handleSend = async (content: string = input) => {
    if (interactionsDisabled) return
    const trimmed = content.trim()
    if (
      !trimmed ||
      createMessageMutation.isPending ||
      updateMessageMutation.isPending ||
      isWaitingForAttachments
    ) {
      return
    }

    // Editing always sends immediately (and is blocked while a run is active).
    if (editingMessageId && isConversationRunning) return

    try {
      await waitForPendingAttachmentUploads()

      const failedAttachments = pendingAttachmentsRef.current.filter(
        (attachment) => attachment.status === 'failed',
      )
      if (failedAttachments.length > 0) {
        toast.error(
          t('Some attachments failed to upload. Remove them and try again.'),
        )
        return
      }

      const pendingAttachmentIds = collectReadyAttachmentIds()
      const editingId = editingMessageId
      const attachmentIds = editingId
        ? Array.from(
            new Set([...editingMessageAttachments, ...pendingAttachmentIds]),
          )
        : Array.from(
            new Set([...restoredQueueAttachmentIds, ...pendingAttachmentIds]),
          )

      // While a turn is running, queue follow-ups instead of dropping them.
      if (isConversationRunning && !editingId) {
        enqueueComposerMessage(trimmed, attachmentIds)
        setInput('')
        setPendingAttachments([])
        setComposerImageOrientations({})
        setRestoredQueueAttachmentIds([])
        setMessageQueueExpanded(true)
        if (inputRef.current) {
          inputRef.current.style.height = 'auto'
        }
        return
      }

      setInput('')
      const sent = await submitComposerMessage({
        content: trimmed,
        attachmentIds,
        editingId,
      })
      if (sent) {
        setPendingAttachments([])
        setComposerImageOrientations({})
        setRestoredQueueAttachmentIds([])
        if (inputRef.current) {
          inputRef.current.style.height = 'auto'
        }
      } else {
        setInput(trimmed)
      }
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to send message')))
    } finally {
      setIsWaitingForAttachments(false)
    }
  }

  // Drain queued follow-ups once the active turn becomes idle.
  useEffect(() => {
    if (isDrainingQueueRef.current) return
    if (
      createMessageMutation.isPending ||
      updateMessageMutation.isPending ||
      isWaitingForAttachments ||
      editingMessageId
    ) {
      return
    }

    // After a successful drain/send, wait until that turn finishes before the next.
    if (queuePausedUntilIdleRef.current) {
      if (isConversationRunning) return
      queuePausedUntilIdleRef.current = false
    }

    if (isConversationRunning) return
    if (messageQueue.length === 0) return

    const next = messageQueue[0]
    isDrainingQueueRef.current = true
    setMessageQueue((current) => current.slice(1))

    void (async () => {
      const sent = await submitComposerMessage({
        content: next.content,
        attachmentIds: next.attachmentIds,
      })
      if (!sent) {
        setMessageQueue((current) => [next, ...current])
        isDrainingQueueRef.current = false
        return
      }
      queuePausedUntilIdleRef.current = true
      isDrainingQueueRef.current = false
    })()
  }, [
    createMessageMutation.isPending,
    editingMessageId,
    isConversationRunning,
    isWaitingForAttachments,
    messageQueue,
    submitComposerMessage,
    updateMessageMutation.isPending,
  ])

  const handleStopConversation = useCallback(async () => {
    if (!activeConversationId || updateConversationMutation.isPending) return
    try {
      await updateConversationMutation.mutateAsync({
        conversationId: activeConversationId,
        controlType: 'stop',
      })
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to stop response')))
    }
  }, [activeConversationId, t, updateConversationMutation])

  const handleRetryMessage = useCallback(
    async (messageId: string) => {
      if (!activeConversationId || updateConversationMutation.isPending) return
      try {
        await updateConversationMutation.mutateAsync({
          conversationId: activeConversationId,
          controlType: 'retry',
          retryFromMessageId: messageId,
        })
      } catch (error) {
        toast.error(getErrorMessage(error, t('Failed to retry response')))
      }
    },
    [activeConversationId, t, updateConversationMutation],
  )

  const handleCopyMessage = useCallback(
    async (messageId: string, text: string) => {
      const trimmed = text.trim()
      if (!trimmed) return
      try {
        await navigator.clipboard.writeText(trimmed)
        setCopiedMessageId(messageId)
        if (copiedMessageTimeoutRef.current !== null) {
          window.clearTimeout(copiedMessageTimeoutRef.current)
        }
        copiedMessageTimeoutRef.current = window.setTimeout(() => {
          setCopiedMessageId(null)
        }, 1500)
      } catch (error) {
        toast.error(getErrorMessage(error, t('Failed to copy message')))
      }
    },
    [t],
  )

  const handleCopyConversationDebug = useCallback(async () => {
    if (!activeConversationId || isCopyingConversation) return

    setIsCopyingConversation(true)
    try {
      const fetchLimit = Math.min(
        Math.max(totalMessages, messages.length, ASSISTANT_MESSAGES_PAGE_SIZE),
        1000,
      )
      const { messages: listedMessages, total } = await fetchAssistantMessages(
        activeConversationId,
        fetchLimit,
      )

      const messagesForExport = await Promise.all(
        listedMessages.map(async (message) => {
          let fullMessage = message
          if (message.role !== 'user') {
            try {
              fullMessage = await sdk.forConsole.assistant.getMessage({
                conversationId: activeConversationId,
                messageId: message.$id,
              })
            } catch {
              // Keep list payload if getMessage fails.
            }
          }

          return {
            message: fullMessage,
            turn:
              fullMessage.role === 'user' ? null : buildTurnView(fullMessage),
          }
        }),
      )

      const payload = {
        exportedAt: new Date().toISOString(),
        source: 'console-ai-chat-debug',
        conversation: activeConversation ?? { $id: activeConversationId },
        messages: messagesForExport,
        meta: {
          total,
          exportedCount: messagesForExport.length,
          truncated: total > messagesForExport.length,
          activeConversationId,
          activeMessageId: activeConversation?.activeMessageId ?? null,
          conversationStatus: activeConversation?.status ?? null,
          contextProjectId: contextProjectId ?? null,
          organizationId,
          pagePath: location.pathname,
          pageUrl:
            typeof window !== 'undefined' ? window.location.href : null,
        },
      }

      await navigator.clipboard.writeText(JSON.stringify(payload, null, 2))
      setCopiedConversation(true)
      if (copiedConversationTimeoutRef.current !== null) {
        window.clearTimeout(copiedConversationTimeoutRef.current)
      }
      copiedConversationTimeoutRef.current = window.setTimeout(() => {
        setCopiedConversation(false)
      }, 1500)
    } catch (error) {
      toast.error(
        getErrorMessage(error, 'Failed to copy conversation JSON'),
      )
    } finally {
      setIsCopyingConversation(false)
    }
  }, [
    activeConversation,
    activeConversationId,
    contextProjectId,
    isCopyingConversation,
    location.pathname,
    messages.length,
    organizationId,
    totalMessages,
  ])

  const handleStartEditResend = useCallback(
    (messageId: string, text: string, attachmentIds: string[]) => {
      if (isConversationRunning) return
      setEditingMessageId(messageId)
      setEditingMessageAttachments(attachmentIds)
      setPendingAttachments([])
      setComposerImageOrientations({})
      setInput(text)
      focusInput(true)
    },
    [focusInput, isConversationRunning],
  )

  const handleCancelEditResend = useCallback(() => {
    setEditingMessageId(null)
    setEditingMessageAttachments([])
    setPendingAttachments([])
    setComposerImageOrientations({})
    setInput('')
  }, [])

  const handleLoadOlderMessages = useCallback(() => {
    if (!hasOlderMessages || isFetchingMessages || isLoadingOlderMessages)
      return

    const container = messagesContainerRef.current
    if (container) {
      olderMessagesAnchorRef.current = {
        scrollTop: container.scrollTop,
        scrollHeight: container.scrollHeight,
      }
    }

    setIsLoadingOlderMessages(true)
    setMessagesLimit((current) =>
      Math.min(current + ASSISTANT_MESSAGES_PAGE_SIZE, totalMessages),
    )
  }, [
    hasOlderMessages,
    isFetchingMessages,
    isLoadingOlderMessages,
    totalMessages,
  ])

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const leaveAssistantPage = useCallback(() => {
    void navigate({ to: '/', replace: false })
  }, [navigate])

  const handleCloseChat = useCallback(() => {
    if (isPageVariant) {
      leaveAssistantPage()
      return
    }
    closeChat()
  }, [closeChat, isPageVariant, leaveAssistantPage])

  useEffect(() => {
    if (!isChatExpanded || isPageVariant) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      // Image previews and other wizard overlays own Escape first
      if (document.querySelector('[data-wizard-layout]')) return
      event.preventDefault()
      setIsChatExpanded(false)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [isChatExpanded, isPageVariant, setIsChatExpanded])

  const conversationById = useMemo(() => {
    const map = new Map<string, AssistantConversation>()
    for (const conversation of conversations) {
      map.set(conversation.$id, conversation)
    }
    return map
  }, [conversations])

  const pinnedConversations = useMemo(() => {
    const items: AssistantConversation[] = []
    for (const id of pinnedConversationIds) {
      const conversation = conversationById.get(id)
      if (conversation) items.push(conversation)
    }
    return items
  }, [conversationById, pinnedConversationIds])

  const pinnedIdSet = useMemo(
    () => new Set(pinnedConversations.map((conversation) => conversation.$id)),
    [pinnedConversations],
  )

  const activeConversations = useMemo(
    () =>
      conversations.filter(
        (conversation) =>
          conversation.status?.toLowerCase() !== 'archived' &&
          !pinnedIdSet.has(conversation.$id),
      ),
    [conversations, pinnedIdSet],
  )
  const archivedConversations = useMemo(
    () =>
      conversations.filter(
        (conversation) =>
          conversation.status?.toLowerCase() === 'archived' &&
          !pinnedIdSet.has(conversation.$id),
      ),
    [conversations, pinnedIdSet],
  )
  const activeConversationGroups = useMemo(
    () => groupConversationsByTime(activeConversations),
    [activeConversations],
  )

  useEffect(() => {
    // Only prune against the unfiltered list. Search results omit non-matching
    // pinned agents and must not clear their pin preference.
    if (!account || conversationsLoading || hasConversationSearch) return
    const existingIds = new Set(
      conversations.map((conversation) => conversation.$id),
    )
    const pruned = pinnedConversationIds.filter((id) => existingIds.has(id))
    if (pruned.length !== pinnedConversationIds.length) {
      setPinnedConversationIds(pruned)
    }
  }, [
    account,
    conversations,
    conversationsLoading,
    hasConversationSearch,
    pinnedConversationIds,
    setPinnedConversationIds,
  ])

  const toggleConversationGroup = useCallback((groupKey: string) => {
    setCollapsedConversationGroups((current) => {
      const next = new Set(current)
      if (next.has(groupKey)) {
        next.delete(groupKey)
      } else {
        next.add(groupKey)
      }
      return next
    })
  }, [])

  const reorderPinnedConversations = useCallback(
    (dragId: string, dropId: string) => {
      if (dragId === dropId) return
      setPinnedConversationIds((current) => {
        const fromIndex = current.indexOf(dragId)
        const toIndex = current.indexOf(dropId)
        if (fromIndex < 0 || toIndex < 0) return current
        const next = [...current]
        const [moved] = next.splice(fromIndex, 1)
        next.splice(toIndex, 0, moved)
        return next
      })
    },
    [setPinnedConversationIds],
  )

  const renderConversationsSearch = () => (
    <div className="relative">
      <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
      <Input
        type="search"
        value={conversationSearch}
        onChange={(event) => setConversationSearch(event.target.value)}
        placeholder={t('Search agents...')}
        className="h-8 border-border bg-background pe-2 ps-8 text-[12px]"
        aria-label={t('Search agents...')}
        disabled={interactionsDisabled}
      />
    </div>
  )

  const renderCreateAgentButton = () => (
    <Button
      type="button"
      variant="outline"
      className="h-8 shrink-0 gap-1.5 px-2.5 text-[12px]"
      onClick={() => {
        void handleCreateConversation()
      }}
      disabled={interactionsDisabled || createConversationMutation.isPending}
    >
      {createConversationMutation.isPending ? (
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
      ) : (
        <Plus className="h-3.5 w-3.5" />
      )}
      {t('Create agent')}
    </Button>
  )

  const renderConversationsMenuBody = (options?: {
    dense?: boolean
    onSelectConversation?: () => void
  }) => (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex h-14 min-h-14 shrink-0 items-center gap-2.5 border-b border-border px-3">
        <ConsoleHeaderLogo
          className={cn('h-6 w-6 shrink-0', headerLogoClassName)}
        />
        <p className="truncate text-[13px] font-semibold text-foreground">
          {t('Appwrite Agents')}
        </p>
      </div>
      <Tabs
        value={conversationsMenuTab}
        onValueChange={(value) => {
          if (value === 'agents' || value === 'automations') {
            setConversationsMenuTab(value)
          }
        }}
        className="flex min-h-0 flex-1 flex-col gap-0"
      >
        <div className="min-h-0 flex-1 overflow-y-auto p-2">
          <TabsList className="mb-3 grid h-9 w-full grid-cols-2 shadow-none">
            <TabsTrigger value="agents" className="text-[12px]">
              {t('Agents')}
            </TabsTrigger>
            <TabsTrigger value="automations" className="text-[12px]">
              {t('Automations')}
            </TabsTrigger>
          </TabsList>
          <TabsContent
            value="agents"
            className="mt-0 data-[state=inactive]:hidden"
          >
            {renderConversationsSearch()}
            <div className="my-8">{renderCreateAgentButton()}</div>
            {renderConversationsList({
              dense: options?.dense,
              onSelect: options?.onSelectConversation,
            })}
          </TabsContent>
          <TabsContent
            value="automations"
            className="mt-0 data-[state=inactive]:hidden"
          >
            <div className="flex min-h-[120px] items-center justify-center px-3 py-8">
              <p className="text-center text-[12px] text-muted-foreground">
                {t('No automations yet.')}
              </p>
            </div>
          </TabsContent>
        </div>
      </Tabs>
    </div>
  )

  const renderConversationRow = (
    conversation: AssistantConversation,
    options?: {
      onSelect?: () => void
      dense?: boolean
      draggable?: boolean
    },
  ) => {
    const isActive = conversation.$id === activeConversationId
    const isArchived = conversation.status?.toLowerCase() === 'archived'
    const isPinned = pinnedIdSet.has(conversation.$id)
    const statusTone = getAssistantConversationStatusTone(conversation)
    const statusLabel = t(getAssistantConversationStatusLabel(statusTone))
    const isArchiving =
      updateConversationMutation.isPending &&
      updateConversationMutation.variables?.conversationId ===
        conversation.$id &&
      updateConversationMutation.variables?.status === 'archived'
    const canDrag = Boolean(options?.draggable) && !interactionsDisabled
    const isDragOver =
      canDrag &&
      pinnedDragOverId === conversation.$id &&
      pinnedDragId !== conversation.$id

    return (
      <AssistantConversationContextMenu
        key={conversation.$id}
        title={conversation.title || t('Untitled agent')}
        disabled={interactionsDisabled}
        isArchived={isArchived}
        isPinned={isPinned}
        onRename={(title) =>
          handleRenameConversation(conversation.$id, title)
        }
        onPin={() => pinConversation(conversation.$id)}
        onUnpin={() => unpinConversation(conversation.$id)}
        onArchive={() => handleArchiveConversation(conversation.$id)}
        onDelete={() => handleDeleteConversation(conversation.$id)}
      >
        <div
          draggable={canDrag}
          onDragStart={
            canDrag
              ? (event) => {
                  setPinnedDragId(conversation.$id)
                  event.dataTransfer.effectAllowed = 'move'
                  event.dataTransfer.setData('text/plain', conversation.$id)
                }
              : undefined
          }
          onDragEnd={
            canDrag
              ? () => {
                  setPinnedDragId(null)
                  setPinnedDragOverId(null)
                }
              : undefined
          }
          onDragOver={
            canDrag
              ? (event) => {
                  event.preventDefault()
                  event.dataTransfer.dropEffect = 'move'
                  setPinnedDragOverId(conversation.$id)
                }
              : undefined
          }
          onDragLeave={
            canDrag
              ? () => {
                  setPinnedDragOverId((current) =>
                    current === conversation.$id ? null : current,
                  )
                }
              : undefined
          }
          onDrop={
            canDrag
              ? (event) => {
                  event.preventDefault()
                  const dragId =
                    event.dataTransfer.getData('text/plain') || pinnedDragId
                  if (dragId) {
                    reorderPinnedConversations(dragId, conversation.$id)
                  }
                  setPinnedDragId(null)
                  setPinnedDragOverId(null)
                }
              : undefined
          }
          className={cn(
            'group flex items-center gap-1 rounded-md border border-transparent px-1.5 py-1 transition-colors',
            canDrag
              ? 'cursor-grab active:cursor-grabbing'
              : 'cursor-pointer',
            isActive
              ? 'border-border bg-accent'
              : 'hover:border-border hover:bg-accent/60',
            isDragOver && 'border-primary bg-primary/10',
            options?.dense && 'py-1.5',
          )}
          aria-label={
            canDrag
              ? `${conversation.title || t('Untitled agent')}, ${t('drag to reorder')}`
              : undefined
          }
        >
          <button
            type="button"
            onClick={() => {
              setActiveConversationId(conversation.$id)
              options?.onSelect?.()
            }}
            className={cn(
              'flex min-w-0 flex-1 flex-col gap-0.5 text-start',
              canDrag ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
            )}
          >
            <span className="flex min-w-0 items-center gap-1.5">
              <span className="min-w-0 truncate text-[12px] font-medium text-foreground">
                {conversation.title || t('Untitled agent')}
              </span>
              {statusTone !== 'ready' ? (
                <span
                  className={cn(
                    'h-1.5 w-1.5 shrink-0 rounded-full',
                    getAssistantConversationStatusDotClass(statusTone),
                  )}
                  title={statusLabel}
                  aria-label={statusLabel}
                  role="img"
                />
              ) : null}
            </span>
            <ConversationResourceSummary conversationId={conversation.$id} />
          </button>
          {!isArchived ? (
            <Button
              type="button"
              size="icon"
              variant="ghost"
              className={cn(
                'h-6 w-6 shrink-0 transition-opacity',
                isArchiving
                  ? 'opacity-100'
                  : 'opacity-0 group-hover:opacity-100 disabled:opacity-0',
              )}
              onClick={(event) => {
                event.stopPropagation()
                void handleArchiveConversation(conversation.$id)
              }}
              disabled={interactionsDisabled || isArchiving}
              aria-label={t('Archive agent')}
            >
              {isArchiving ? (
                <Loader2 className="h-3 w-3 animate-spin" />
              ) : (
                <Archive className="h-3 w-3" />
              )}
            </Button>
          ) : null}
        </div>
      </AssistantConversationContextMenu>
    )
  }

  const renderConversationTimeGroups = (
    groups: Array<{
      label: ConversationTimeGroup
      items: AssistantConversation[]
    }>,
    options?: {
      onSelect?: () => void
      dense?: boolean
    },
  ) =>
    groups.map((group) => {
      const groupKey = group.label
      const isOpen = !collapsedConversationGroups.has(groupKey)
      return (
        <Collapsible
          key={groupKey}
          open={isOpen}
          onOpenChange={() => toggleConversationGroup(groupKey)}
        >
          <div className="space-y-0.5">
            <CollapsibleTrigger asChild>
              <button
                type="button"
                className="group flex w-full items-center gap-1 rounded-md px-1.5 py-1 text-start text-[10px] font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
              >
                <span className="inline-flex min-w-0 items-center gap-0.5">
                  <span className="truncate">{t(group.label)}</span>
                  <ChevronDown
                    className={cn(
                      'h-3 w-3 shrink-0 opacity-0 transition-all group-hover:opacity-100',
                      !isOpen && '-rotate-90',
                    )}
                  />
                </span>
                <span className="ms-auto tabular-nums text-[10px] font-medium normal-case tracking-normal text-muted-foreground/80">
                  {group.items.length}
                </span>
              </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-0.5">
              {group.items.map((conversation) =>
                renderConversationRow(conversation, options),
              )}
            </CollapsibleContent>
          </div>
        </Collapsible>
      )
    })

  const renderConversationsList = (options?: {
    onSelect?: () => void
    dense?: boolean
  }) => {
    // Only block on the initial load. Background refetches (realtime invalidation)
    // must keep the existing list mounted to avoid flicker.
    if (conversationsLoading && conversations.length === 0) {
      return (
        <div className="flex items-center gap-1.5 px-1.5 py-2 text-[11px] text-muted-foreground">
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          {t('Loading agents...')}
        </div>
      )
    }
    if (conversations.length === 0) {
      return (
        <div className="px-1.5 py-2 text-[11px] text-muted-foreground">
          {hasConversationSearch
            ? t('No agents match your search.')
            : t('No agents yet.')}
        </div>
      )
    }
    return (
      <div className="space-y-8">
        <div className="space-y-6">
          {pinnedConversations.length > 0 ? (
            <Collapsible
              open={pinnedSectionOpen}
              onOpenChange={setPinnedSectionOpen}
            >
              <div className="space-y-0.5">
                <CollapsibleTrigger asChild>
                  <button
                    type="button"
                    className="group flex w-full items-center gap-1 rounded-md px-1.5 py-1 text-start text-[10px] font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
                  >
                    <span className="inline-flex min-w-0 items-center gap-0.5">
                      <span className="truncate">{t('Pinned')}</span>
                      <ChevronDown
                        className={cn(
                          'h-3 w-3 shrink-0 opacity-0 transition-all group-hover:opacity-100',
                          !pinnedSectionOpen && '-rotate-90',
                        )}
                      />
                    </span>
                    <span className="ms-auto tabular-nums text-[10px] font-medium normal-case tracking-normal text-muted-foreground/80">
                      {pinnedConversations.length}
                    </span>
                  </button>
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-0.5">
                  {pinnedConversations.map((conversation) =>
                    renderConversationRow(conversation, {
                      ...options,
                      draggable: true,
                    }),
                  )}
                </CollapsibleContent>
              </div>
            </Collapsible>
          ) : null}

          {activeConversationGroups.length > 0
            ? renderConversationTimeGroups(activeConversationGroups, options)
            : pinnedConversations.length === 0 &&
                archivedConversations.length > 0
              ? (
                  <div className="px-1.5 py-1 text-[11px] text-muted-foreground">
                    {hasConversationSearch
                      ? t('No active agents match your search.')
                      : t('No active agents.')}
                  </div>
                )
              : null}
        </div>

        {archivedConversations.length > 0 ? (
          <Collapsible
            open={archivedSectionOpen}
            onOpenChange={setArchivedSectionOpen}
          >
            <div className="space-y-0.5">
              <CollapsibleTrigger asChild>
                <button
                  type="button"
                  className="group flex w-full items-center gap-1 rounded-md px-1.5 py-1 text-start text-[10px] font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
                >
                  <span className="inline-flex min-w-0 items-center gap-0.5">
                    <span className="truncate">{t('Archived')}</span>
                    <ChevronDown
                      className={cn(
                        'h-3 w-3 shrink-0 opacity-0 transition-all group-hover:opacity-100',
                        !archivedSectionOpen && '-rotate-90',
                      )}
                    />
                  </span>
                  <span className="ms-auto tabular-nums text-[10px] font-medium normal-case tracking-normal text-muted-foreground/80">
                    {archivedConversations.length}
                  </span>
                </button>
              </CollapsibleTrigger>
              <CollapsibleContent className="space-y-0.5">
                {archivedConversations.map((conversation) =>
                  renderConversationRow(conversation, options),
                )}
              </CollapsibleContent>
            </div>
          </Collapsible>
        ) : null}
      </div>
    )
  }

  if (isAssistantBlocked) return null

  const conversationsSidebar = (
    <div className="flex h-full min-h-0 flex-col bg-background">
      <div className="min-h-0 flex-1">{renderConversationsMenuBody({ dense: true })}</div>
      <div className="shrink-0 border-t border-border bg-background p-2">
        <Button
          type="button"
          variant="ghost"
          className="h-9 w-full justify-start gap-1.5 px-2 text-[13px] text-muted-foreground hover:text-foreground"
          onClick={() => {
            if (isPageVariant) {
              leaveAssistantPage()
              return
            }
            setIsChatExpanded(false)
          }}
        >
          <ChevronLeft className="h-3.5 w-3.5 shrink-0" />
          {isPageVariant
            ? isAuthenticated
              ? t('Back to console')
              : t('Back to Appwrite')
            : t('Back to console')}
        </Button>
      </div>
    </div>
  )

  const chatMain = (
      <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col">
          <div className="flex h-14 min-h-14 shrink-0 items-center justify-between border-b border-border px-3">
            <div className="min-w-0">
              <div className="flex items-center gap-1">
                {effectiveExpanded ? (
                  <button
                    type="button"
                    className="group flex min-w-0 max-w-full items-center gap-1 rounded-md px-1.5 py-1 text-start transition-colors hover:bg-accent/60 disabled:pointer-events-none disabled:opacity-60"
                    onClick={() => setHeaderRenameOpen(true)}
                    disabled={
                      interactionsDisabled ||
                      !activeConversationId ||
                      !activeConversation
                    }
                    aria-label={t('Update agent')}
                  >
                    <span className="truncate text-[13px] font-semibold text-foreground">
                      {activeConversation?.title || t('New agent')}
                    </span>
                    {activeConversationId && activeConversation ? (
                      <Pencil className="h-3 w-3 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                    ) : null}
                  </button>
                ) : (
                  <Popover
                    open={conversationsPopoverOpen}
                    onOpenChange={setConversationsPopoverOpen}
                  >
                    <div className="flex min-w-0 items-center gap-0.5">
                      <button
                        type="button"
                        className="group flex min-w-0 max-w-[200px] items-center gap-1 rounded-md px-1.5 py-1 text-start transition-colors hover:bg-accent/60 disabled:pointer-events-none disabled:opacity-60"
                        onClick={() => setHeaderRenameOpen(true)}
                        disabled={
                          interactionsDisabled ||
                          !activeConversationId ||
                          !activeConversation
                        }
                        aria-label={t('Update agent')}
                      >
                        <span className="truncate text-[13px] font-semibold text-foreground">
                          {activeConversation?.title || t('New agent')}
                        </span>
                        {activeConversationId && activeConversation ? (
                          <Pencil className="h-3 w-3 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                        ) : null}
                      </button>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8 shrink-0"
                          aria-label={t('Agents')}
                        >
                          <ChevronsUpDown className="h-3.5 w-3.5 text-muted-foreground" />
                        </Button>
                      </PopoverTrigger>
                    </div>

                    <PopoverContent align="start" className="w-[300px] p-0">
                      <div className="flex max-h-[360px] flex-col overflow-hidden">
                        {renderConversationsMenuBody({
                          onSelectConversation: () =>
                            setConversationsPopoverOpen(false),
                        })}
                      </div>
                    </PopoverContent>
                  </Popover>
                )}
              </div>
            </div>
            <div className="flex items-center gap-1">
              {isDebugModeOpen && activeConversationId ? (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7 text-purple-600 hover:bg-purple-500/10 hover:text-purple-700 disabled:text-purple-600/40 dark:text-purple-400 dark:hover:bg-purple-500/15 dark:hover:text-purple-300 dark:disabled:text-purple-400/40"
                  onClick={() => {
                    void handleCopyConversationDebug()
                  }}
                  disabled={isCopyingConversation || messages.length === 0}
                  aria-label="Copy conversation JSON"
                  title="Copy conversation JSON"
                >
                  {isCopyingConversation ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : copiedConversation ? (
                    <Check className="h-3.5 w-3.5" />
                  ) : (
                    <Copy className="h-3.5 w-3.5" />
                  )}
                </Button>
              ) : null}
              {!interactionsDisabled ? <McpConnections /> : null}
              {!isPageVariant ? (
                <button
                  type="button"
                  onClick={() => setIsChatExpanded((current) => !current)}
                  className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                  aria-label={
                    isChatExpanded ? t('Collapse chat') : t('Expand chat')
                  }
                  title={isChatExpanded ? t('Collapse chat') : t('Expand chat')}
                >
                  {isChatExpanded ? (
                    <Minimize2 className="h-3.5 w-3.5" />
                  ) : (
                    <Maximize2 className="h-3.5 w-3.5" />
                  )}
                </button>
              ) : null}
              <button
                type="button"
                onClick={handleCloseChat}
                className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label={t('Close')}
              >
                <X className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <div className="relative flex min-h-0 flex-1 flex-col">
          <div className="relative min-h-0 flex-1">
            <div
              ref={messagesContainerRef}
              onScroll={handleMessagesScroll}
              className="h-full overflow-y-auto"
            >
              <div
                className={cn(
                  'p-3',
                  effectiveExpanded && 'mx-auto w-full max-w-3xl',
                  messages.length === 0 && 'flex min-h-full flex-col',
                )}
              >
              {messages.length === 0 ? (
                <AssistantEmptyState
                  hasActiveMcp={hasActiveMcp && !isGuest}
                  suggestions={emptyStateSuggestions}
                  onSelectSuggestion={(question) => handleSend(t(question))}
                  requireSignIn={isGuest}
                  sphereSize={getSphereRenderSize(SPHERE_BASE_SIZES.empty)}
                  activityRef={bubbleActivityRef}
                  colorMode={effectiveSphereColorMode}
                  shapeMode={effectiveSphereShapeMode}
                  particleCount={effectiveSphereParticleCount}
                  debugSlot={
                    isDebugModeOpen ? (
                      <AssistantBubbleDebugControls
                        expanded={bubbleDebugExpanded}
                        onExpandedChange={setBubbleDebugExpanded}
                        activityMode={bubbleDebugMode}
                        onActivityModeChange={setBubbleDebugMode}
                        sizeScale={effectiveSphereSizeScale}
                        sizeScaleOverride={sphereSizeScaleOverride}
                        onSizeScaleChange={setSphereSizeScaleOverride}
                        onSizeScaleDefault={() =>
                          setSphereSizeScaleOverride(null)
                        }
                        colorMode={sphereColorMode}
                        onColorModeChange={setSphereColorMode}
                        shapeMode={sphereShapeMode}
                        onShapeModeChange={setSphereShapeMode}
                        particleCountOverride={sphereParticleCountOverride}
                        autoParticleCount={sphereAutoParticleCount}
                        onParticleCountChange={setSphereParticleCountOverride}
                        onParticleCountAuto={() =>
                          setSphereParticleCountOverride(null)
                        }
                      />
                    ) : null
                  }
                />
              ) : (
                <div ref={messagesContentRef} className="space-y-3">
                  {hasOlderMessages ? (
                    <div className="flex justify-center">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={isFetchingMessages || isLoadingOlderMessages}
                        className="h-7 px-2 text-[11px] text-muted-foreground"
                        onClick={handleLoadOlderMessages}
                      >
                        {isLoadingOlderMessages ? (
                          <>
                            <Loader2 className="me-1 h-3 w-3 animate-spin" />
                            {t('Loading older messages...')}{' '}
                            {/* pragma: allowlist secret */}
                          </>
                        ) : (
                          t('Load older messages') // pragma: allowlist secret
                        )}
                      </Button>
                    </div>
                  ) : null}
                  {messages.map((message: AssistantMessage) => {
                    const messageText = message.contentText || ''
                    const messageAttachments = getMessageAttachments(message)
                    const isUserMessage = message.role.toLowerCase() === 'user'
                    const isLiveAssistant =
                      !isUserMessage &&
                      (message.$id === activeAssistantMessageId ||
                        isAssistantMessageInFlight(message.status))
                    const turn = isUserMessage ? null : buildTurnView(message)
                    const hasTurnChrome =
                      !!turn &&
                      (!!turn.statusLabel ||
                        !!turn.route?.agent ||
                        turn.agents.length > 0 ||
                        turn.toolOrder.length > 0 ||
                        !!turn.error)

                    // Hide empty completed assistant shells with no activity chrome.
                    if (
                      !isUserMessage &&
                      !messageText.trim() &&
                      !isLiveAssistant &&
                      !hasTurnChrome
                    ) {
                      return null
                    }

                    const messageStatus = message.status?.toLowerCase()
                    const canRetryMessage =
                      !isConversationRunning &&
                      !isUserMessage &&
                      (messageStatus === 'failed' ||
                        messageStatus === 'stopped' ||
                        activeConversation?.status?.toLowerCase() ===
                          'failed' ||
                        activeConversation?.status?.toLowerCase() ===
                          'stopped')

                    return (
                      <AssistantMessageRow
                        key={message.$id}
                        message={message}
                        messageAttachments={messageAttachments}
                        placeholderCandidates={placeholderCandidates}
                        copied={copiedMessageId === message.$id}
                        showDebug={isDebugModeOpen}
                        onCopyMessage={handleCopyMessage}
                        onStartEditResend={handleStartEditResend}
                        onRetry={handleRetryMessage}
                        canRetry={canRetryMessage}
                        deferCodeBlocks={
                          isThinking && message.$id === latestMessageId
                        }
                      />
                    )
                  })}
                  {isThinking &&
                  !latestAssistantMessage &&
                  waitingForAssistantReply ? (
                    <div className="flex">
                      <div className="mb-2 flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] text-muted-foreground">
                        <Loader2 className="h-3 w-3 animate-spin" />
                        <span>{liveTurnStatusLabel || t('Thinking...')}</span>
                      </div>
                    </div>
                  ) : null}
                  <div ref={messagesEndRef} />
                </div>
              )}
              </div>
            </div>
            {!isStickToBottom && messages.length > 0 ? (
              <div className="pointer-events-none absolute inset-x-0 bottom-3 z-10 flex justify-center px-3">
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  className="pointer-events-auto h-8 gap-1.5 rounded-full border border-border bg-background/95 px-3 text-[12px] shadow-md backdrop-blur-sm"
                  onClick={pinToBottom}
                >
                  <ChevronDown className="h-3.5 w-3.5" />
                  {t('Jump to latest')}
                </Button>
              </div>
            ) : null}
          </div>

          <div className="shrink-0 border-t border-border">
            <div
              className={cn(
                'p-3',
                effectiveExpanded && 'mx-auto w-full max-w-3xl',
              )}
            >
            {isDebugModeOpen && messages.length > 0 ? (
              <div className="mb-3">
                <AssistantBubbleDebugControls
                  expanded={bubbleDebugExpanded}
                  onExpandedChange={setBubbleDebugExpanded}
                  activityMode={bubbleDebugMode}
                  onActivityModeChange={setBubbleDebugMode}
                  sizeScale={effectiveSphereSizeScale}
                  sizeScaleOverride={sphereSizeScaleOverride}
                  onSizeScaleChange={setSphereSizeScaleOverride}
                  onSizeScaleDefault={() => setSphereSizeScaleOverride(null)}
                  colorMode={sphereColorMode}
                  onColorModeChange={setSphereColorMode}
                  shapeMode={sphereShapeMode}
                  onShapeModeChange={setSphereShapeMode}
                  particleCountOverride={sphereParticleCountOverride}
                  autoParticleCount={sphereAutoParticleCount}
                  onParticleCountChange={setSphereParticleCountOverride}
                  onParticleCountAuto={() => setSphereParticleCountOverride(null)}
                />
              </div>
            ) : null}
            {editingMessageId ? (
              <div className="mb-2 flex items-center justify-between rounded-md border border-border bg-muted/20 px-2.5 py-1.5">
                <p className="truncate text-[11px] text-muted-foreground">
                  {t('Editing message')}
                  {editingMessageAttachments.length > 0
                    ? ` (${editingMessageAttachments.length} ${editingMessageAttachments.length > 1 ? t('attachments selected') : t('attachment selected')})`
                    : ''}
                </p>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-6 px-2 text-[11px]"
                  onClick={handleCancelEditResend}
                >
                  {t('Cancel')}
                </Button>
              </div>
            ) : null}
            {editingMessageId && editingMessageAttachments.length > 0 ? (
              <div className="mb-2 overflow-x-auto">
                <div className="flex min-w-max flex-nowrap gap-1.5 pb-1">
                  {editingMessageAttachments.map((attachmentId) => {
                    const attachmentFile = editingAttachmentFilesData?.find(
                      (file) => file.$id === attachmentId,
                    )
                    const attachmentName =
                      attachmentFile?.name ||
                      `${t('Attachment')} ${attachmentId.slice(0, 8)}`
                    const isImageAttachment =
                      attachmentFile?.mimeType?.startsWith('image/')
                    const attachmentSize =
                      typeof attachmentFile?.sizeOriginal === 'number'
                        ? formatAttachmentSize(attachmentFile.sizeOriginal)
                        : null

                    return (
                      <div
                        key={attachmentId}
                        className="w-40 shrink-0 rounded-md border border-border bg-muted/20 p-1.5"
                      >
                        {isImageAttachment ? (
                          <img
                            src={sdk.forConsole.storage.getFilePreview({
                              bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                              fileId: attachmentId,
                              height: 240,
                              output: composerAvifSupported
                                ? ImageFormat.Avif
                                : undefined,
                            })}
                            alt={attachmentName}
                            onLoad={(event) => {
                              const image =
                                event.currentTarget as HTMLImageElement
                              const orientation =
                                image.naturalHeight > image.naturalWidth
                                  ? 'portrait'
                                  : 'landscape'
                              setComposerImageOrientations((previous) =>
                                previous[attachmentId] === orientation
                                  ? previous
                                  : {
                                      ...previous,
                                      [attachmentId]: orientation,
                                    },
                              )
                            }}
                            className={cn(
                              'mb-1 w-full rounded object-cover',
                              getPreviewAspectClass(
                                composerImageOrientations[attachmentId] ===
                                  'portrait',
                              ),
                            )}
                            loading="lazy"
                          />
                        ) : (
                          <div className="mb-1 flex aspect-video w-full items-center justify-center rounded bg-muted/40">
                            <Paperclip className="h-4 w-4 text-muted-foreground" />
                          </div>
                        )}
                        <div className="flex items-start gap-1.5">
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-[11px] font-medium text-foreground">
                              {attachmentName}
                            </p>
                            <p className="text-[10px] text-muted-foreground">
                              {attachmentSize ?? t('Ready')}
                            </p>
                          </div>
                          <button
                            type="button"
                            onClick={() =>
                              handleRemoveEditingAttachment(attachmentId)
                            }
                            className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            aria-label={`${t('Remove')} ${attachmentName}`}
                          >
                            <X className="h-3 w-3" />
                          </button>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            ) : null}
            <div className="overflow-hidden rounded-md border border-border bg-card">
              {messageQueue.length > 0 ? (
                <div className="border-b border-border">
                  <button
                    type="button"
                    onClick={() =>
                      setMessageQueueExpanded((current) => !current)
                    }
                    className="flex w-full items-center gap-1.5 px-2.5 py-1.5 text-start text-[12px] text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground"
                  >
                    {messageQueueExpanded ? (
                      <ChevronDown className="h-3.5 w-3.5 shrink-0" />
                    ) : (
                      <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                    )}
                    <span className="tabular-nums font-medium text-foreground">
                      {messageQueue.length}
                    </span>
                    <span>{t('Queued')}</span>
                  </button>
                  {messageQueueExpanded ? (
                    <div className="pb-1">
                      {messageQueue.map((queued, index) => {
                        const queueNumber =
                          index + 1 + (isConversationRunning ? 1 : 0)
                        return (
                          <div
                            key={queued.id}
                            className="group/queue flex items-center gap-2 px-2.5 py-1 hover:bg-muted/30"
                          >
                            <Circle className="h-3 w-3 shrink-0 text-muted-foreground/70" />
                            <span className="w-4 shrink-0 text-[11px] tabular-nums text-muted-foreground">
                              {queueNumber}
                            </span>
                            <p className="min-w-0 flex-1 truncate text-[12px] text-foreground">
                              {queued.content}
                            </p>
                            {queued.attachmentIds.length > 0 ? (
                              <span
                                className="inline-flex shrink-0 items-center gap-0.5 text-[10px] text-muted-foreground"
                                title={t('Attachment')}
                              >
                                <Paperclip className="h-3 w-3" />
                                {queued.attachmentIds.length}
                              </span>
                            ) : null}
                            <div className="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover/queue:opacity-100 group-focus-within/queue:opacity-100">
                              <button
                                type="button"
                                onClick={() =>
                                  handleEditQueuedMessage(queued.id)
                                }
                                className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                aria-label={t('Edit queued message')}
                                title={t('Edit queued message')}
                              >
                                <Pencil className="h-3 w-3" />
                              </button>
                              <button
                                type="button"
                                onClick={() =>
                                  handlePromoteQueuedMessage(queued.id)
                                }
                                disabled={index === 0}
                                className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
                                aria-label={t('Send next')}
                                title={t('Send next')}
                              >
                                <ArrowUp className="h-3 w-3" />
                              </button>
                              <button
                                type="button"
                                onClick={() =>
                                  handleRemoveQueuedMessage(queued.id)
                                }
                                className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                aria-label={t('Remove from queue')}
                                title={t('Remove from queue')}
                              >
                                <Trash2 className="h-3 w-3" />
                              </button>
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  ) : null}
                </div>
              ) : null}
              {pendingAttachments.length > 0 ? (
                <div className="border-b border-border px-2 py-2">
                  <div className="overflow-x-auto">
                    <div className="flex min-w-max flex-nowrap gap-1.5">
                      {orderedPendingAttachments.map((attachment) => (
                        <div
                          key={attachment.localId}
                          className="w-40 shrink-0 rounded-md border border-border bg-muted/20 p-1.5"
                        >
                          {attachment.mimeType.startsWith('image/') &&
                          attachment.fileId ? (
                            <img
                              src={sdk.forConsole.storage.getFilePreview({
                                bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                                fileId: attachment.fileId,
                                height: 240,
                                output: composerAvifSupported
                                  ? ImageFormat.Avif
                                  : undefined,
                              })}
                              alt={attachment.name}
                              onLoad={(event) => {
                                const image =
                                  event.currentTarget as HTMLImageElement
                                const orientation =
                                  image.naturalHeight > image.naturalWidth
                                    ? 'portrait'
                                    : 'landscape'
                                setComposerImageOrientations((previous) =>
                                  previous[attachment.localId] === orientation
                                    ? previous
                                    : {
                                        ...previous,
                                        [attachment.localId]: orientation,
                                      },
                                )
                              }}
                              className={cn(
                                'mb-1 w-full rounded object-cover',
                                getPreviewAspectClass(
                                  composerImageOrientations[
                                    attachment.localId
                                  ] === 'portrait',
                                ),
                              )}
                              loading="lazy"
                            />
                          ) : (
                            <div className="mb-1 flex aspect-video w-full items-center justify-center rounded bg-muted/40">
                              <Paperclip className="h-4 w-4 text-muted-foreground" />
                            </div>
                          )}
                          <div className="flex items-start gap-1.5">
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-[11px] font-medium text-foreground">
                                {attachment.name}
                              </p>
                              <p className="text-[10px] text-muted-foreground">
                                {attachment.status === 'uploading'
                                  ? t('Uploading...')
                                  : attachment.status === 'failed'
                                    ? t('Upload failed')
                                    : (formatAttachmentSize(attachment.size) ??
                                      t('Ready'))}
                              </p>
                            </div>
                            <button
                              type="button"
                              onClick={() =>
                                handleRemoveAttachment(attachment.localId)
                              }
                              className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                              aria-label={`${t('Remove')} ${attachment.name}`}
                            >
                              <X className="h-3 w-3" />
                            </button>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              ) : null}
              {restoredQueueAttachmentIds.length > 0 ? (
                <div className="flex items-center gap-1.5 border-b border-border px-2.5 py-1.5 text-[11px] text-muted-foreground">
                  <Paperclip className="h-3 w-3 shrink-0" />
                  <span className="min-w-0 flex-1 truncate">
                    {restoredQueueAttachmentIds.length} {t('Attachment')}
                  </span>
                  <button
                    type="button"
                    onClick={() => setRestoredQueueAttachmentIds([])}
                    className="rounded p-0.5 transition-colors hover:bg-accent hover:text-foreground"
                    aria-label={t('Remove')}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </div>
              ) : null}
              <div className="flex items-end gap-1.5 p-1.5">
                <input
                  ref={fileInputRef}
                  type="file"
                  multiple
                  className="hidden"
                  onChange={handleAttachmentFileChange}
                />
                <textarea
                  ref={inputRef}
                  value={input}
                  onChange={(e) => {
                    if (interactionsDisabled) return
                    setInput(e.target.value)
                    if (messages.length === 0) {
                      registerTypingKeystroke()
                    }
                  }}
                  onPaste={interactionsDisabled ? undefined : handleInputPaste}
                  onKeyDown={interactionsDisabled ? undefined : handleKeyDown}
                  placeholder={
                    interactionsDisabled
                      ? t('Sign in to ask a question...')
                      : editingMessageId
                        ? t('Edit message...')
                        : isConversationRunning || messageQueue.length > 0
                          ? t('Add a follow-up')
                          : t('Ask a question...')
                  }
                  dir={isInputRtl ? 'rtl' : 'ltr'}
                  rows={1}
                  disabled={interactionsDisabled}
                  className="max-h-32 min-h-[34px] flex-1 resize-none bg-transparent px-1.5 py-1 text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                  style={{
                    height: 'auto',
                    minHeight: '34px',
                  }}
                  onInput={(e) => {
                    const target = e.target as HTMLTextAreaElement
                    target.style.height = 'auto'
                    target.style.height = `${Math.min(target.scrollHeight, 128)}px`
                  }}
                />
                <button
                  type="button"
                  onClick={handleAttachmentInputClick}
                  disabled={
                    interactionsDisabled ||
                    isWaitingForAttachments ||
                    Boolean(editingMessageId)
                  }
                  className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                    interactionsDisabled ||
                      isWaitingForAttachments ||
                      editingMessageId
                      ? 'bg-muted text-muted-foreground'
                      : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                  )}
                  aria-label={t('Attach files')}
                >
                  <Paperclip className="h-3.5 w-3.5" />
                </button>
                {isConversationRunning ? (
                  <button
                    type="button"
                    onClick={() => void handleStopConversation()}
                    disabled={
                      interactionsDisabled ||
                      updateConversationMutation.isPending
                    }
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground transition-colors hover:bg-accent disabled:bg-muted disabled:text-muted-foreground"
                    aria-label={t('Stop')}
                  >
                    {updateConversationMutation.isPending ? (
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    ) : (
                      <Square className="h-3.5 w-3.5 fill-current" />
                    )}
                  </button>
                ) : null}
                <button
                  type="button"
                  onClick={() => void handleSend()}
                  disabled={
                    interactionsDisabled ||
                    !input.trim() ||
                    isWaitingForAttachments ||
                    Boolean(editingMessageId && isConversationRunning) ||
                    (!isConversationRunning && !canSendWhileIdle)
                  }
                  className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                    !interactionsDisabled &&
                      input.trim() &&
                      !isWaitingForAttachments &&
                      !(editingMessageId && isConversationRunning) &&
                      (isConversationRunning || canSendWhileIdle)
                      ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                      : 'bg-muted text-muted-foreground',
                  )}
                  aria-label={
                    isConversationRunning ? t('Add to queue') : t('Send')
                  }
                >
                  {createMessageMutation.isPending ||
                  updateMessageMutation.isPending ||
                  isWaitingForAttachments ||
                  hasUploadingAttachments ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Send className="h-3.5 w-3.5" />
                  )}
                </button>
              </div>
            </div>
            <p className="mt-1.5 text-center text-[11px] text-muted-foreground">
              {hasUploadingAttachments
                ? t(
                    'Attachments upload in background. Sending waits until they are ready.',
                  )
                : isConversationRunning
                  ? t('Press Enter to queue, Shift+Enter for new line')
                  : t('Press Enter to send, Shift+Enter for new line')}
            </p>
            </div>
          </div>
          </div>
      </div>
  )

  return (
    <div
      className={cn(
        'flex min-h-0 bg-background',
        isPageVariant
          ? 'h-full w-full'
          : effectiveExpanded
            ? 'fixed inset-0 z-[125] h-[100dvh] max-h-[100dvh] w-full'
            : 'h-full',
      )}
    >
      {effectiveExpanded ? (
        <AssistantConversationsResizableLayout sidebar={conversationsSidebar}>
          {chatMain}
        </AssistantConversationsResizableLayout>
      ) : (
        chatMain
      )}
      {activeConversationId && activeConversation ? (
        <AssistantRenameDialog
          open={headerRenameOpen}
          onOpenChange={setHeaderRenameOpen}
          title={activeConversation.title || t('Untitled agent')}
          onRename={(title) =>
            handleRenameConversation(activeConversationId, title)
          }
        />
      ) : null}
    </div>
  )
}

/** @deprecated Use {@link ConsoleRightPane} with {@link AIChatPanelContent}. */
export function AIChatPanel() {
  return null
}

function makeConversationTitle(text: string): string {
  const cleanText = text.trim().replace(/\s+/g, ' ')
  if (!cleanText) return 'New agent'
  return cleanText.length > 42 ? `${cleanText.slice(0, 42)}...` : cleanText
}

function getMessageAttachments(message: AssistantMessage): string[] {
  const attachments = (message as { attachments?: unknown }).attachments
  if (!Array.isArray(attachments)) return []
  return attachments.filter(
    (attachment): attachment is string =>
      typeof attachment === 'string' && attachment.length > 0,
  )
}

function buildAssistantRealtimeChannels(scopes: {
  projectId?: string | null
  organizationId?: string | null
  accountId?: string | null
}): string[] {
  const channels = new Set<string>(['console'])
  if (scopes.projectId) channels.add(`projects.${scopes.projectId}`)
  if (scopes.organizationId) channels.add(`teams.${scopes.organizationId}`)
  if (scopes.accountId) channels.add(`account.${scopes.accountId}`)
  return [...channels]
}
