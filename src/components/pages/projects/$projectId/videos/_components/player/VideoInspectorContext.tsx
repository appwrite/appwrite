import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from '@tanstack/react-router'
import { Activity } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { usePopoutWindow } from './usePopoutWindow'

type OpenInspectorOptions = {
  projectId: string
  videoId: string
  videoName: string
}

type VideoInspectorValue = {
  /** Element inside the inspector window to portal into; `null` while closed. */
  container: HTMLElement | null
  isOpen: boolean
  open: (options: OpenInspectorOptions) => void
  close: () => void
  /** Called by a mounted player; returns the detach function. */
  attach: () => () => void
}

const VideoInspectorContext = createContext<VideoInspectorValue | null>(null)

export function useVideoInspector() {
  return useContext(VideoInspectorContext)
}

export function inspectorWindowTitle(videoName: string, suffix: string) {
  return videoName ? `${videoName} · ${suffix}` : suffix
}

/**
 * Owns the inspector pop-up for the videos workspace. The window has to
 * open from the click itself (pop-up blockers), so menus open it here and the
 * player on the Overview page renders into it once mounted.
 */
export function VideoInspectorProvider({ children }: { children: ReactNode }) {
  const t = useT()
  const navigate = useNavigate()
  const [attached, setAttached] = useState(0)
  const [target, setTarget] = useState<OpenInspectorOptions | null>(null)

  const onBlocked = useCallback(() => {
    toast.error(
      t(
        'Your browser blocked the window. Allow pop-ups for this site to open the inspector.',
      ),
    )
  }, [t])
  const popout = usePopoutWindow({ onBlocked, widthRatio: 1 })
  const { open: openWindow, close, container } = popout

  const open = useCallback(
    (options: OpenInspectorOptions) => {
      setTarget(options)
      void openWindow(inspectorWindowTitle(options.videoName, t('Inspector')))
    },
    [openWindow, t],
  )

  const attach = useCallback(() => {
    setAttached((count) => count + 1)
    return () => setAttached((count) => count - 1)
  }, [])

  const value = useMemo<VideoInspectorValue>(
    () => ({ container, isOpen: popout.isOpen, open, close, attach }),
    [container, popout.isOpen, open, close, attach],
  )

  const goToOverview = () => {
    if (!target) return
    window.focus()
    void navigate({
      to: '/projects/$projectId/videos/$videoId',
      params: { projectId: target.projectId, videoId: target.videoId },
    })
  }

  return (
    <VideoInspectorContext.Provider value={value}>
      {children}
      {container && attached === 0
        ? createPortal(
            <div className="flex h-full items-center justify-center bg-background p-6 text-foreground">
              <div className="max-w-sm text-center">
                <span className="mx-auto flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <Activity className="h-5 w-5" />
                </span>
                <h2 className="mt-4 text-[15px] font-semibold">
                  {t('Waiting for the player')}
                </h2>
                <p className="mt-2 text-[13px] text-muted-foreground">
                  {t(
                    "The inspector follows the video playing on a video's Overview page. Open one to continue inspecting.",
                  )}
                </p>
                <div className="mt-4 flex justify-center gap-2">
                  {target ? (
                    <Button
                      type="button"
                      size="sm"
                      className="h-8 text-[13px]"
                      onClick={goToOverview}
                    >
                      {t('Go to overview')}
                    </Button>
                  ) : null}
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-[13px]"
                    onClick={close}
                  >
                    {t('Close')}
                  </Button>
                </div>
              </div>
            </div>,
            container,
          )
        : null}
    </VideoInspectorContext.Provider>
  )
}
