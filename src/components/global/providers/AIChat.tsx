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
import { useQueryClient } from '@tanstack/react-query'
import { useLocation, useParams } from '@tanstack/react-router'
import { Query, type RealtimeResponseEvent } from '@appwrite.io/console'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { toast } from 'sonner'
import {
  Check,
  ChevronsUpDown,
  ChevronLeft,
  ChevronRight,
  Copy,
  Paperclip,
  Loader2,
  Pencil,
  Plus,
  Send,
  Trash2,
  X,
  ZoomIn,
  ZoomOut,
  Lightbulb,
  ExternalLink,
  Maximize2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { StreamingMarkdown } from '@/components/global/shared/StreamingMarkdown'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  useAssistantConversations,
  useAssistantAttachmentFiles,
  useAssistantMessages,
  ASSISTANT_MESSAGES_PAGE_SIZE,
  useCreateAssistantConversation,
  useCreateAssistantMessage,
  useDeleteAssistantConversation,
  useUploadAssistantAttachments,
  ASSISTANT_ATTACHMENTS_BUCKET_ID,
  useProject,
  type AssistantConversation,
  type AssistantMessage,
} from '@/lib/react-query/hooks'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { getApiEndpoint, sdk } from '@/lib/appwrite/sdk'

interface AIChatContextValue {
  isOpen: boolean
  activeConversationId: string | null
  openChat: () => void
  closeChat: () => void
  toggleChat: () => void
  setActiveConversationId: (conversationId: string | null) => void
}

const AIChatContext = createContext<AIChatContextValue | null>(null)
const OPEN_STATE_STORAGE_KEY = 'ai-chat-panel-open'
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

export function AIChatProvider({ children }: { children: React.ReactNode }) {
  const location = useLocation()
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const [isOpen, setIsOpen] = useState(() => {
    if (typeof window === 'undefined') return false
    return localStorage.getItem(OPEN_STATE_STORAGE_KEY) === 'true'
  })
  const [activeConversationId, setActiveConversationId] = useState<string | null>(
    null,
  )

  const openChat = useCallback(() => {
    if (isAssistantBlocked) return
    setIsOpen(true)
  }, [isAssistantBlocked])
  const closeChat = useCallback(() => setIsOpen(false), [])
  const toggleChat = useCallback(() => {
    if (isAssistantBlocked) return
    setIsOpen((prev) => !prev)
  }, [isAssistantBlocked])

  useEffect(() => {
    if (isAssistantBlocked && isOpen) {
      setIsOpen(false)
    }
  }, [isAssistantBlocked, isOpen])

  useEffect(() => {
    if (typeof window === 'undefined') return
    localStorage.setItem(OPEN_STATE_STORAGE_KEY, isOpen ? 'true' : 'false')
  }, [isOpen])

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

const MIN_WIDTH = 320
const MAX_WIDTH = 600
const DEFAULT_WIDTH = 400
const STORAGE_KEY = 'ai-chat-panel-width'

interface AssistantMessageRowProps {
  messageId: string
  role: string
  messageText: string
  messageAttachments?: string[]
  deferCodeBlocks: boolean
  placeholderCandidates: Partial<Record<PlaceholderToken, string[]>>
  copied: boolean
  onCopyMessage: (messageId: string, text: string) => void
  onStartEditResend: (
    messageId: string,
    text: string,
    attachmentIds: string[],
  ) => void
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
    if (/[\u0590-\u05FF\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(character)) {
      return true
    }
  }
  return false
}

function MessageAttachments({
  attachmentIds = [],
  alignment,
}: MessageAttachmentsProps) {
  const uniqueAttachmentIds = useMemo(
    () => [...new Set(attachmentIds.filter(Boolean))],
    [attachmentIds],
  )
  const [fullscreenAttachment, setFullscreenAttachment] =
    useState<number | null>(null)
  const [fullscreenZoom, setFullscreenZoom] = useState(1)
  const [fullscreenPan, setFullscreenPan] = useState({ x: 0, y: 0 })
  const [loadedImageKeys, setLoadedImageKeys] = useState<Set<string>>(new Set())
  const [imageOrientations, setImageOrientations] = useState<
    Record<string, 'portrait' | 'landscape'>
  >({})
  const [imageDimensions, setImageDimensions] = useState<
    Record<string, { width: number; height: number }>
  >({})
  const thumbnailStripRef = useRef<HTMLDivElement>(null)
  const thumbnailButtonRefs = useRef<Record<string, HTMLButtonElement | null>>({})
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
              })
            : null,
          fullscreenPreviewUrl: file?.mimeType?.startsWith('image/')
            ? sdk.forConsole.storage.getFilePreview({
                bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                fileId,
                height: 900,
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
    [filesById, uniqueAttachmentIds],
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
  const visibleImages = useMemo(() => imageAttachments.slice(0, 4), [imageAttachments])
  const hiddenImageCount = Math.max(0, imageAttachments.length - visibleImages.length)
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
      if (current === null || current >= imageAttachments.length - 1) return current
      return current + 1
    })
  }, [imageAttachments.length])

  const zoomOut = useCallback(() => {
    setFullscreenZoom((current) => Math.max(0.5, Number((current - 0.25).toFixed(2))))
  }, [])

  const zoomIn = useCallback(() => {
    setFullscreenZoom((current) => Math.min(3, Number((current + 0.25).toFixed(2))))
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
        const next = Math.min(3, Math.max(0.5, Number((current + zoomStep).toFixed(2))))
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
      setFullscreenAttachment(imageAttachments.length > 0 ? imageAttachments.length - 1 : null)
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
    const currentIds = new Set(imageAttachments.map((attachment) => attachment.id))
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
      thumbnailRect.left < stripRect.left || thumbnailRect.right > stripRect.right

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
      <div className={cn('mt-2 flex', alignment === 'right' ? 'justify-end' : 'justify-start')}>
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
                fileAttachments.length > 4 && 'max-h-44 overflow-y-auto pr-1',
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
                        {attachment.mimeType || 'File'}
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
                        aria-label={`Open ${attachment.name}`}
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
                aria-label="Previous image"
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
                aria-label="Next image"
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
              <Button asChild type="button" variant="ghost" size="sm" className="h-8 w-8 p-0">
                <a
                  href={activeFullscreenAttachment.openUrl}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={`Open ${activeFullscreenAttachment.name}`}
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
                aria-label="Zoom out"
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
                aria-label="Zoom in"
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
                aria-label="Fit image to screen"
              >
                <Maximize2 className="h-4 w-4" />
              </Button>
            </div>
          }
        >
          <div className="flex min-h-[calc(100vh-140px)] w-full flex-col bg-background p-4 sm:p-6">
            <div
              className="relative flex min-h-0 flex-1 items-center justify-center overflow-auto p-2 sm:p-4"
            >
              {!isFullscreenImageLoaded ? (
                <div className="absolute inset-0 flex items-center justify-center bg-background/70 backdrop-blur-[1px]">
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Loading image...
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
                  'h-auto max-h-[calc(100vh-320px)] w-full max-w-[1400px] rounded-lg object-contain transition-opacity duration-300 select-none',
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
                aria-label="Previous image"
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <div ref={thumbnailStripRef} className="flex-1 overflow-x-auto">
                <div className="flex min-w-max gap-1.5">
                  {imageAttachments.map((imageAttachment, index) => (
                    <button
                      key={imageAttachment.id}
                      ref={(element) => {
                        thumbnailButtonRefs.current[imageAttachment.id] = element
                      }}
                      type="button"
                      onClick={() => setFullscreenAttachment(index)}
                      className={cn(
                        'relative aspect-square w-28 shrink-0 cursor-pointer overflow-hidden rounded-md border bg-muted/20 transition-colors sm:w-32',
                        index === fullscreenAttachment
                          ? 'border-primary ring-1 ring-primary/50 classic:border-sidebar-accent classic:ring-sidebar-accent/70'
                          : 'border-border hover:border-primary/50 classic:hover:border-sidebar-accent',
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
                aria-label="Next image"
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
    messageId,
    role,
    messageText,
    messageAttachments,
    deferCodeBlocks,
    placeholderCandidates,
    copied,
    onCopyMessage,
    onStartEditResend,
  }: AssistantMessageRowProps) {
    const isUserMessage = role.toLowerCase() === 'user'
    const isRtlMessage = useMemo(() => isRtlMessageText(messageText), [messageText])
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
                  'inline-block max-w-full cursor-default rounded-md bg-primary px-2.5 py-1.5 text-[13px] whitespace-pre-wrap text-primary-foreground classic:bg-sidebar-accent classic:text-sidebar-foreground',
                )}
              >
                {messageText}
              </div>
              <MessageAttachments
                attachmentIds={messageAttachments}
                alignment={attachmentsAlignment}
              />
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
                      aria-label="Copy message"
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
                      aria-label="Edit and resend message"
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
                      aria-label="Edit and resend message"
                    >
                      <Pencil className="h-2.5 w-2.5" />
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                      onClick={() => onCopyMessage(messageId, messageText)}
                      aria-label="Copy message"
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
                {unresolvedSelectableTokens.length > 0 && (
                  <div className="rounded-md border border-border bg-muted/20 p-2">
                    <p className="mb-1.5 text-[11px] text-muted-foreground">
                      Select values for placeholders
                    </p>
                    <div className="space-y-1.5">
                      {unresolvedSelectableTokens.map((token) => (
                        <div
                          key={token}
                          className="flex items-center gap-2 text-[12px]"
                        >
                          <span className="w-[122px] shrink-0 text-muted-foreground">
                            {PLACEHOLDER_LABELS[token]}
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
                              {(placeholderCandidates[token] ?? []).map((option) => (
                                <SelectItem
                                  key={`${token}-${option}`}
                                  value={option}
                                  className="text-[12px]"
                                >
                                  {option}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
                <StreamingMarkdown
                  content={resolvedAssistantText}
                  deferCodeBlocks={deferCodeBlocks}
                />
                <div
                  dir={isRtlMessage ? 'rtl' : 'ltr'}
                  className={cn(
                    'mt-1 flex h-5 w-full items-center justify-start gap-0.5 opacity-0 transition-opacity duration-150 pointer-events-none group-hover/message:opacity-100 group-hover/message:pointer-events-auto group-focus-within/message:opacity-100 group-focus-within/message:pointer-events-auto',
                  )}
                >
                  {/* time ago hidden for now */}
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="h-4 w-4 p-0 text-muted-foreground hover:text-foreground"
                    onClick={() => onCopyMessage(messageId, messageText)}
                    aria-label="Copy message"
                  >
                    {copied ? (
                      <Check className="h-2.5 w-2.5" />
                    ) : (
                      <Copy className="h-2.5 w-2.5" />
                    )}
                  </Button>
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
    prev.messageId === next.messageId &&
    prev.role === next.role &&
    prev.messageText === next.messageText &&
    prev.messageAttachments === next.messageAttachments &&
    prev.deferCodeBlocks === next.deferCodeBlocks &&
    prev.placeholderCandidates === next.placeholderCandidates &&
    prev.copied === next.copied,
)

export function AIChatPanel() {
  const overrides = useDebugOverrides()
  const {
    isOpen,
    closeChat,
    activeConversationId,
    setActiveConversationId,
  } = useAIChat()
  const params = useParams({ strict: false }) as {
    projectId?: string
    orgId?: string
    teamId?: string
  }
  const location = useLocation()
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const showPanel = overrides.showAIAssistant
  const [input, setInput] = useState('')
  const [messagesLimit, setMessagesLimit] = useState(ASSISTANT_MESSAGES_PAGE_SIZE)
  const [isLoadingOlderMessages, setIsLoadingOlderMessages] = useState(false)
  const [copiedMessageId, setCopiedMessageId] = useState<string | null>(null)
  const [editingMessageId, setEditingMessageId] = useState<string | null>(null)
  const [editingMessageAttachments, setEditingMessageAttachments] = useState<string[]>(
    [],
  )
  const [pendingAttachments, setPendingAttachments] = useState<
    ComposerPendingAttachment[]
  >([])
  const [composerImageOrientations, setComposerImageOrientations] = useState<
    Record<string, 'portrait' | 'landscape'>
  >({})
  const pendingAttachmentsRef = useRef<ComposerPendingAttachment[]>([])
  const uploadTasksRef = useRef<Map<string, Promise<void>>>(new Map())
  const [isWaitingForAttachments, setIsWaitingForAttachments] = useState(false)
  const [conversationsPopoverOpen, setConversationsPopoverOpen] = useState(false)
  const [width, setWidth] = useState(() => {
    if (typeof window !== 'undefined') {
      const saved = localStorage.getItem(STORAGE_KEY)
      if (saved) {
        const parsed = parseInt(saved, 10)
        if (!isNaN(parsed) && parsed >= MIN_WIDTH && parsed <= MAX_WIDTH) {
          return parsed
        }
      }
    }
    return DEFAULT_WIDTH
  })
  const [isResizing, setIsResizing] = useState(false)
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const messagesContainerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const copiedMessageTimeoutRef = useRef<number | null>(null)
  const previousConversationIdRef = useRef<string | null>(null)
  const previousLatestMessageIdRef = useRef<string | null>(null)
  const olderMessagesAnchorRef = useRef<{ scrollTop: number; scrollHeight: number } | null>(
    null,
  )
  const {
    data: conversationsData,
    isLoading: conversationsLoading,
    isFetching: conversationsFetching,
  } = useAssistantConversations()
  const conversations: AssistantConversation[] = conversationsData ?? []
  const createConversationMutation = useCreateAssistantConversation()
  const deleteConversationMutation = useDeleteAssistantConversation()
  const createMessageMutation = useCreateAssistantMessage()
  const uploadAssistantAttachmentsMutation = useUploadAssistantAttachments()

  const activeConversation = useMemo(
    () => conversations.find((conversation) => conversation.$id === activeConversationId),
    [activeConversationId, conversations],
  )
  const contextProjectId = params.projectId ?? activeConversation?.projectId
  const { project } = useProject(contextProjectId)
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountId = (account as { $id?: string } | undefined)?.$id ?? null
  const organizationId =
    params.orgId ?? params.teamId ?? project?.teamId ?? null
  const assistantRealtimeChannels = useMemo(
    () =>
      buildAssistantRealtimeChannels({
        projectId: contextProjectId,
        organizationId,
        accountId,
      }),
    [accountId, contextProjectId, organizationId],
  )

  useEffect(() => {
    let cancelled = false
    let closeSubscription: (() => Promise<void>) | null = null

    const handleRealtimeEvent = (response: RealtimeResponseEvent<unknown>) => {
      const hasAssistantEvent =
        response.events.some((eventName) => eventName.includes('assistant')) ||
        response.channels.some((channel) => channel.includes('assistant'))

      if (!hasAssistantEvent) return

      const payload =
        response.payload && typeof response.payload === 'object'
          ? (response.payload as Record<string, unknown>)
          : null
      const conversationId =
        typeof payload?.conversationId === 'string'
          ? payload.conversationId
          : null

      queryClient.invalidateQueries({ queryKey: ['assistant', 'conversations'] })

      if (conversationId) {
        queryClient.invalidateQueries({
          queryKey: ['assistant', 'messages', conversationId],
        })
      } else {
        queryClient.invalidateQueries({ queryKey: ['assistant', 'messages'] })
      }
    }

    async function subscribe() {
      const realtime = sdk.getConsoleRealtime()
      const subscription = await realtime.subscribe(
        assistantRealtimeChannels,
        handleRealtimeEvent as (event: {
          events: string[]
          channels: string[]
          payload: unknown
        }) => void,
      )

      if (cancelled) {
        await subscription.close()
        return
      }

      closeSubscription = () => subscription.close()
    }

    subscribe().catch(() => {
      // Keep chat usable even if realtime fails.
    })

    return () => {
      cancelled = true
      if (closeSubscription) {
        void closeSubscription()
      }
    }
  }, [assistantRealtimeChannels, queryClient])

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
        activeConversation?.projectId,
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
    activeConversation?.projectId,
    account,
    params.orgId,
    params.projectId,
    params.teamId,
    project?.$id,
    project?.name,
    project?.region,
    project?.teamId,
  ])

  const { data: messagesData, isFetching: isFetchingMessages } = useAssistantMessages(
    activeConversationId,
    messagesLimit,
  )
  const messages: AssistantMessage[] = messagesData?.messages ?? []
  const totalMessages = messagesData?.total ?? messages.length
  const hasOlderMessages = totalMessages > messages.length
  const { data: editingAttachmentFilesData } = useAssistantAttachmentFiles(
    editingMessageAttachments,
  )
  const latestMessageId = messages[messages.length - 1]?.$id
  const latestMessage = messages[messages.length - 1]

  const isConversationRunning = useMemo(() => {
    if (!activeConversation) return false
    const status = activeConversation.status?.toLowerCase()
    const lockState = activeConversation.lockState?.toLowerCase()
    return status === 'running' || status === 'queued' || lockState === 'locked'
  }, [activeConversation])

  const isLatestAssistantMessageRunning = useMemo(() => {
    const latestAssistantMessage = [...messages]
      .reverse()
      .find((message) => message.role.toLowerCase() !== 'user')
    const status = latestAssistantMessage?.status?.toLowerCase()
    return (
      status === 'running' ||
      status === 'queued' ||
      status === 'processing' ||
      status === 'pending'
    )
  }, [messages])

  const waitingForAssistantReply =
    latestMessage?.role?.toLowerCase() === 'user' && isConversationRunning

  const isThinking =
    createMessageMutation.isPending ||
    isLatestAssistantMessageRunning ||
    waitingForAssistantReply
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

  useLayoutEffect(() => {
    const conversationId = activeConversationId ?? null
    const currentLatestMessageId = latestMessageId ?? null
    const conversationChanged = previousConversationIdRef.current !== conversationId

    if (olderMessagesAnchorRef.current && messagesContainerRef.current) {
      const { scrollTop, scrollHeight } = olderMessagesAnchorRef.current
      const newScrollHeight = messagesContainerRef.current.scrollHeight
      messagesContainerRef.current.scrollTop =
        scrollTop + (newScrollHeight - scrollHeight)
      olderMessagesAnchorRef.current = null
    } else {
      const latestMessageChanged =
        previousLatestMessageIdRef.current !== currentLatestMessageId

      if (
        currentLatestMessageId &&
        (conversationChanged || latestMessageChanged)
      ) {
        messagesEndRef.current?.scrollIntoView({
          behavior: conversationChanged ? 'auto' : 'smooth',
        })
      }
    }

    previousConversationIdRef.current = conversationId
    previousLatestMessageIdRef.current = currentLatestMessageId
  }, [activeConversationId, latestMessageId, messages.length])

  // Focus input when panel opens
  useEffect(() => {
    if (isOpen) {
      window.setTimeout(() => inputRef.current?.focus(), 300)
    }
  }, [isOpen])

  // Save width to localStorage when it changes
  useEffect(() => {
    if (typeof window !== 'undefined') {
      localStorage.setItem(STORAGE_KEY, width.toString())
    }
  }, [width])

  useEffect(() => {
    return () => {
      if (copiedMessageTimeoutRef.current !== null) {
        window.clearTimeout(copiedMessageTimeoutRef.current)
      }
    }
  }, [])

  useEffect(() => {
    pendingAttachmentsRef.current = pendingAttachments
  }, [pendingAttachments])

  // Pick initial conversation if none is selected
  useEffect(() => {
    if (
      activeConversationId &&
      conversations.some(
        (conversation: AssistantConversation) => conversation.$id === activeConversationId,
      )
    ) {
      return
    }

    if (conversations.length > 0) {
      setActiveConversationId(conversations[0].$id)
      return
    }

    setActiveConversationId(null)
  }, [activeConversationId, conversations, setActiveConversationId])

  useEffect(() => {
    setMessagesLimit(ASSISTANT_MESSAGES_PAGE_SIZE)
  }, [activeConversationId])

  useEffect(() => {
    if (isLoadingOlderMessages && !isFetchingMessages) {
      setIsLoadingOlderMessages(false)
    }
  }, [isFetchingMessages, isLoadingOlderMessages])

  // Handle resize drag
  const handleMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    setIsResizing(true)
  }, [])

  useEffect(() => {
    if (!isResizing) return

    const handleMouseMove = (e: MouseEvent) => {
      if (!panelRef.current) return

      // Calculate new width based on mouse position from right edge of viewport
      const newWidth = window.innerWidth - e.clientX
      const clampedWidth = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, newWidth))
      setWidth(clampedWidth)
    }

    const handleMouseUp = () => {
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)

    // Add cursor style to body during resize
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [isResizing])

  if (!showPanel || isAssistantBlocked) return null

  const resolveConversationProjectId = async (): Promise<string | null> => {
    const directContextProjectId =
      params.projectId ?? activeConversation?.projectId ?? conversations[0]?.projectId
    if (directContextProjectId) return directContextProjectId

    try {
      const projects = await sdk.forConsole.projects.list({
        queries: [
          Query.or([Query.isNull('status'), Query.notEqual('status', 'archived')]),
          Query.orderDesc('$createdAt'),
          Query.limit(1),
        ],
        total: false,
      })
      return projects.projects?.[0]?.$id ?? null
    } catch {
      return null
    }
  }

  const handleCreateConversation = async () => {
    const conversationProjectId = await resolveConversationProjectId()
    if (!conversationProjectId) {
      toast.error('No accessible project found to create a conversation.')
      return
    }

    try {
      const conversation = await createConversationMutation.mutateAsync({
        projectId: conversationProjectId,
        title: 'New conversation',
      })
      setActiveConversationId(conversation.$id)
      setConversationsPopoverOpen(false)
      focusInput()
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to create conversation'))
    }
  }

  const handleDeleteConversation = async (conversationId: string) => {
    try {
      await deleteConversationMutation.mutateAsync(conversationId)
      if (conversationId === activeConversationId) {
        const nextConversation = conversations.find((c) => c.$id !== conversationId)
        setActiveConversationId(nextConversation?.$id ?? null)
      }
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to delete conversation'))
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

      const contextForUploadProjectId = params.projectId ?? activeConversation?.projectId
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
    [activeConversation?.projectId, params.projectId, uploadAssistantAttachmentsMutation],
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
          (item) => item.kind === 'file' && item.type.toLowerCase().startsWith('image/'),
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

  const handleSend = async (content: string = input) => {
    const trimmed = content.trim()
    if (!trimmed || createMessageMutation.isPending || isWaitingForAttachments) return
    const conversationProjectId = await resolveConversationProjectId()
    if (!activeConversationId && !conversationProjectId) {
      toast.error('No accessible project found to start a new conversation.')
      return
    }

    setInput('')

    let conversationId = activeConversationId

    try {
      if (!conversationId) {
        if (!conversationProjectId) return
        const createdConversation = await createConversationMutation.mutateAsync({
          projectId: conversationProjectId,
          title: makeConversationTitle(trimmed),
        })
        conversationId = createdConversation.$id
        setActiveConversationId(createdConversation.$id)
      }
      if (!conversationId) return

      if (
        pendingAttachmentsRef.current.some(
          (attachment) => attachment.status === 'uploading',
        )
      ) {
        setIsWaitingForAttachments(true)
        const uploadPromises = pendingAttachmentsRef.current
          .filter((attachment) => attachment.status === 'uploading')
          .map((attachment) => uploadTasksRef.current.get(attachment.localId))
          .filter((promise): promise is Promise<void> => !!promise)
        await Promise.allSettled(uploadPromises)
        setIsWaitingForAttachments(false)
      }

      const failedAttachments = pendingAttachmentsRef.current.filter(
        (attachment) => attachment.status === 'failed',
      )
      if (failedAttachments.length > 0) {
        toast.error('Some attachments failed to upload. Remove them and try again.')
        return
      }

      const pendingAttachmentIds = pendingAttachmentsRef.current
        .filter(
          (attachment): attachment is ComposerPendingAttachment & { fileId: string } =>
            attachment.status === 'ready' && !!attachment.fileId,
        )
        .map((attachment) => attachment.fileId)

      const attachmentIds = editingMessageId
        ? Array.from(
            new Set([...editingMessageAttachments, ...pendingAttachmentIds]),
          )
        : pendingAttachmentIds

      if (editingMessageId) {
        setEditingMessageId(null)
        setEditingMessageAttachments([])
      }

      await createMessageMutation.mutateAsync({
        conversationId,
        contentText: trimmed,
        context: {
          contextTeamId: params.orgId ?? params.teamId ?? project?.teamId,
          contextProjectId: params.projectId ?? activeConversation?.projectId,
          contextOrganizationId: params.orgId ?? params.teamId ?? project?.teamId,
          contextPagePath: location.pathname,
          contextPageTitle:
            typeof document !== 'undefined' ? document.title : undefined,
          contextPageUrl:
            typeof window !== 'undefined' ? window.location.href : undefined,
        },
        attachments: attachmentIds,
        continueRun: true,
      })

      setPendingAttachments([])
      setComposerImageOrientations({})
    } catch (error) {
      // Keep selected attachments in place when message send fails.
      toast.error(getErrorMessage(error, 'Failed to send message'))
    } finally {
      setIsWaitingForAttachments(false)
    }
  }

  const handleCopyMessage = useCallback(async (messageId: string, text: string) => {
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
      toast.error(getErrorMessage(error, 'Failed to copy message'))
    }
  }, [])

  const handleStartEditResend = useCallback(
    (messageId: string, text: string, attachmentIds: string[]) => {
      setEditingMessageId(messageId)
      setEditingMessageAttachments(attachmentIds)
      setPendingAttachments([])
      setComposerImageOrientations({})
      setInput(text)
      focusInput(true)
    },
    [focusInput],
  )

  const handleCancelEditResend = useCallback(() => {
    setEditingMessageId(null)
    setEditingMessageAttachments([])
    setPendingAttachments([])
    setComposerImageOrientations({})
    setInput('')
  }, [])

  const handleLoadOlderMessages = useCallback(() => {
    if (!hasOlderMessages || isFetchingMessages || isLoadingOlderMessages) return

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
  }, [hasOlderMessages, isFetchingMessages, isLoadingOlderMessages, totalMessages])

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  // Don't render anything if not open
  if (!isOpen) return null

  return (
    <div
      ref={panelRef}
      style={{ width: `${width}px` }}
      className={cn(
        'relative flex h-full shrink-0 flex-col border-l border-border bg-background [&_button:not(:disabled)]:cursor-pointer',
      )}
    >
      {/* Resize Handle */}
      <div
        onMouseDown={handleMouseDown}
        className={cn(
          'absolute left-0 top-0 z-10 flex h-full w-1.5 cursor-col-resize items-center justify-center transition-colors hover:bg-primary/20 classic:hover:bg-sidebar-accent/60',
          isResizing && 'bg-primary/30 classic:bg-sidebar-accent/70',
        )}
      />

      <div className="flex h-full min-h-0">
        <div className="flex min-w-0 flex-1 flex-col">
          <div className="flex h-14 min-h-14 shrink-0 items-center justify-between border-b border-border px-3">
            <div className="min-w-0">
              <div className="flex items-center gap-1">
                <Popover
                  open={conversationsPopoverOpen}
                  onOpenChange={setConversationsPopoverOpen}
                >
                  <PopoverTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      className="h-8 max-w-[248px] justify-start px-1.5 text-left"
                    >
                      <div className="flex min-w-0 items-center gap-1.5">
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-semibold text-foreground">
                            {activeConversation?.title || 'New conversation'}
                          </p>
                        </div>
                        <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      </div>
                    </Button>
                  </PopoverTrigger>

                  <PopoverContent align="start" className="w-[300px] p-0">
                    <div className="border-b border-border px-2 py-1.5">
                      <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                        Conversations
                      </p>
                    </div>

                    <div className="max-h-[300px] overflow-y-auto p-1.5">
                    {conversationsLoading || conversationsFetching ? (
                      <div className="flex items-center gap-1.5 px-1.5 py-2 text-[11px] text-muted-foreground">
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        Loading conversations...
                      </div>
                    ) : conversations.length === 0 ? (
                      <div className="px-1.5 py-2 text-[11px] text-muted-foreground">
                        No conversations yet.
                      </div>
                    ) : (
                      <div className="space-y-0.5">
                        {conversations.map((conversation: AssistantConversation) => {
                          const isActive = conversation.$id === activeConversationId
                          return (
                            <div
                              key={conversation.$id}
                              className={cn(
                                'group flex cursor-pointer items-center gap-1 rounded-md border border-transparent px-1.5 py-1 transition-colors',
                                isActive
                                  ? 'border-border bg-accent'
                                  : 'hover:border-border hover:bg-accent/60',
                              )}
                            >
                              <button
                                type="button"
                                onClick={() => {
                                  setActiveConversationId(conversation.$id)
                                  setConversationsPopoverOpen(false)
                                }}
                                className="min-w-0 flex-1 cursor-pointer text-left"
                              >
                                <p className="truncate text-[12px] font-medium text-foreground">
                                  {conversation.title || 'Untitled conversation'}
                                </p>
                              </button>
                              <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="h-6 w-6 shrink-0 opacity-0 transition-opacity group-hover:opacity-100"
                                onClick={(event) => {
                                  event.stopPropagation()
                                  handleDeleteConversation(conversation.$id)
                                }}
                                disabled={deleteConversationMutation.isPending}
                              >
                                <Trash2 className="h-3 w-3" />
                              </Button>
                            </div>
                          )
                        })}
                      </div>
                    )}
                    </div>
                  </PopoverContent>
                </Popover>

                <Button
                  type="button"
                  variant="ghost"
                  className="h-8 w-8 shrink-0 p-0"
                  onClick={handleCreateConversation}
                  disabled={createConversationMutation.isPending}
                >
                  {createConversationMutation.isPending ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Plus className="h-3.5 w-3.5" />
                  )}
                </Button>
              </div>
            </div>
            <div className="flex items-center gap-1">
              <button
                onClick={closeChat}
                className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <div ref={messagesContainerRef} className="min-h-0 flex-1 overflow-y-auto p-3">
            {messages.length === 0 ? (
              <div className="flex h-full flex-col items-center justify-center">
                <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 classic:bg-sidebar-accent">
                  <Lightbulb className="h-8 w-8 text-primary classic:text-sidebar-foreground" />
                </div>
                <h3 className="mb-2 text-lg font-semibold text-foreground">
                  How can I help you?
                </h3>
                <p className="mb-6 text-center text-sm text-muted-foreground">
                  I can inspect your project, explain issues, suggest next steps, and run approved actions.
                </p>
                <div className="w-full max-w-md space-y-2">
                  {suggestedQuestions.map((question) => (
                    <button
                      key={question}
                      onClick={() => handleSend(question)}
                      className="w-full rounded-lg border border-border bg-card p-3 text-left text-sm text-foreground transition-colors hover:bg-accent"
                    >
                      {question}
                    </button>
                  ))}
                </div>
              </div>
            ) : (
              <div className="space-y-3">
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
                          <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                          Loading older messages...
                        </>
                      ) : (
                        'Load older messages'
                      )}
                    </Button>
                  </div>
                ) : null}
                {messages.map((message: AssistantMessage) => {
                  const messageText = message.contentText || ''
                  const messageAttachments = getMessageAttachments(message)
                  const isUserMessage = message.role.toLowerCase() === 'user'

                  // Avoid duplicate AI placeholders: when the backend created an empty
                  // assistant message during generation, we only show the "Thinking..."
                  // row below (single AI response state).
                  if (!isUserMessage && !messageText.trim()) {
                    return null
                  }

                  return (
                    <AssistantMessageRow
                      key={message.$id}
                      messageId={message.$id}
                      role={message.role}
                      messageText={messageText}
                      messageAttachments={messageAttachments}
                      placeholderCandidates={placeholderCandidates}
                      copied={copiedMessageId === message.$id}
                      onCopyMessage={handleCopyMessage}
                      onStartEditResend={handleStartEditResend}
                      deferCodeBlocks={
                        isThinking && message.$id === latestMessageId
                      }
                    />
                  )
                })}
                {isThinking && (
                  <div className="flex">
                    <div className="flex items-center gap-1.5 px-2.5 py-1.5 text-[13px] text-muted-foreground">
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                      <span>Thinking...</span>
                    </div>
                  </div>
                )}
                <div ref={messagesEndRef} />
              </div>
            )}
          </div>

          <div className="shrink-0 border-t border-border p-3">
            {editingMessageId ? (
              <div className="mb-2 flex items-center justify-between rounded-md border border-border bg-muted/20 px-2.5 py-1.5">
                <p className="truncate text-[11px] text-muted-foreground">
                  Editing message{editingMessageAttachments.length > 0
                    ? ` (${editingMessageAttachments.length} attachment${editingMessageAttachments.length > 1 ? 's' : ''} selected)`
                    : ''}
                </p>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-6 px-2 text-[11px]"
                  onClick={handleCancelEditResend}
                >
                  Cancel
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
                      `Attachment ${attachmentId.slice(0, 8)}`
                    const isImageAttachment = attachmentFile?.mimeType?.startsWith('image/')
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
                            })}
                            alt={attachmentName}
                            onLoad={(event) => {
                              const image = event.currentTarget as HTMLImageElement
                              const orientation =
                                image.naturalHeight > image.naturalWidth
                                  ? 'portrait'
                                  : 'landscape'
                              setComposerImageOrientations((previous) =>
                                previous[attachmentId] === orientation
                                  ? previous
                                  : { ...previous, [attachmentId]: orientation },
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
                              {attachmentSize ?? 'Ready'}
                            </p>
                          </div>
                          <button
                            type="button"
                            onClick={() =>
                              handleRemoveEditingAttachment(attachmentId)
                            }
                            className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            aria-label={`Remove ${attachmentName}`}
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
            {pendingAttachments.length > 0 ? (
              <div className="mb-2 overflow-x-auto">
                <div className="flex min-w-max flex-nowrap gap-1.5 pb-1">
                  {orderedPendingAttachments.map((attachment) => (
                  <div
                    key={attachment.localId}
                    className="w-40 shrink-0 rounded-md border border-border bg-muted/20 p-1.5"
                  >
                    {attachment.mimeType.startsWith('image/') && attachment.fileId ? (
                      <img
                        src={sdk.forConsole.storage.getFilePreview({
                          bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
                          fileId: attachment.fileId,
                          height: 240,
                        })}
                        alt={attachment.name}
                        onLoad={(event) => {
                          const image = event.currentTarget as HTMLImageElement
                          const orientation =
                            image.naturalHeight > image.naturalWidth
                              ? 'portrait'
                              : 'landscape'
                          setComposerImageOrientations((previous) =>
                            previous[attachment.localId] === orientation
                              ? previous
                              : { ...previous, [attachment.localId]: orientation },
                          )
                        }}
                        className={cn(
                          'mb-1 w-full rounded object-cover',
                          getPreviewAspectClass(
                            composerImageOrientations[attachment.localId] ===
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
                          {attachment.name}
                        </p>
                        <p className="text-[10px] text-muted-foreground">
                          {attachment.status === 'uploading'
                            ? 'Uploading...'
                            : attachment.status === 'failed'
                              ? 'Upload failed'
                              : formatAttachmentSize(attachment.size) ?? 'Ready'}
                        </p>
                      </div>
                      <button
                        type="button"
                        onClick={() => handleRemoveAttachment(attachment.localId)}
                        className="rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        aria-label={`Remove ${attachment.name}`}
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </div>
                  </div>
                  ))}
                </div>
              </div>
            ) : null}
            <div className="flex items-end gap-1.5 rounded-md border border-border bg-card p-1.5">
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
                onChange={(e) => setInput(e.target.value)}
                onPaste={handleInputPaste}
                onKeyDown={handleKeyDown}
                placeholder={editingMessageId ? 'Edit message...' : 'Ask a question...'}
                dir={isInputRtl ? 'rtl' : 'ltr'}
                rows={1}
                className="max-h-32 min-h-[34px] flex-1 resize-none bg-transparent px-1.5 py-1 text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none"
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
                disabled={createMessageMutation.isPending || isWaitingForAttachments}
                className={cn(
                  'flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                  createMessageMutation.isPending || isWaitingForAttachments
                    ? 'bg-muted text-muted-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )}
                aria-label="Attach files"
              >
                <Paperclip className="h-3.5 w-3.5" />
              </button>
              <button
                onClick={() => handleSend()}
                disabled={
                  !input.trim() ||
                  createMessageMutation.isPending ||
                  isWaitingForAttachments
                }
                className={cn(
                  'flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                  input.trim() &&
                    !createMessageMutation.isPending &&
                    !isWaitingForAttachments
                    ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                    : 'bg-muted text-muted-foreground',
                )}
              >
                {createMessageMutation.isPending ||
                isWaitingForAttachments ||
                hasUploadingAttachments ? (
                  <Loader2 className="h-3.5 w-3.5 animate-spin" />
                ) : (
                  <Send className="h-3.5 w-3.5" />
                )}
              </button>
            </div>
            <p className="mt-1.5 text-center text-[11px] text-muted-foreground">
              {hasUploadingAttachments
                ? 'Attachments upload in background. Sending waits until they are ready.'
                : 'Press Enter to send, Shift+Enter for new line'}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}

function makeConversationTitle(text: string): string {
  const cleanText = text.trim().replace(/\s+/g, ' ')
  if (!cleanText) return 'New conversation'
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

