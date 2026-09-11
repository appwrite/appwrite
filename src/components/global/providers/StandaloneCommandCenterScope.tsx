import {
  lazy,
  Suspense,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ComponentProps,
  type ReactNode,
} from 'react'
import { useGlobalCommandShortcuts } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import {
  registerCommandCenterOpener,
  type CommandCenterPage,
} from '@/lib/command-center/opener-bridge'
import {
  KeyboardShortcutsContext,
  type KeyboardShortcutsContextValue,
} from '@/components/global/providers/keyboard-shortcuts-context'

const LazyCommandCenter = lazy(async () => {
  const module = await import('@/components/global/shared/CommandCenter')
  return { default: module.CommandCenter }
})

type StandaloneCommandCenterScopeProps = {
  children: ReactNode
} & Omit<
  ComponentProps<typeof LazyCommandCenter>,
  'open' | 'onOpenChange' | 'initialSubPage' | 'onInitialSubPageConsumed'
>

/** Command center + global shortcuts for pages outside project/org providers (e.g. home). */
export function StandaloneCommandCenterScope({
  children,
  context = 'account',
  ...commandCenterProps
}: StandaloneCommandCenterScopeProps) {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [commandCenterMounted, setCommandCenterMounted] = useState(false)
  const [initialSubPage, setInitialSubPage] = useState<string | null>(null)

  const openCommandCenter = useCallback(() => {
    setCommandCenterMounted(true)
    setInitialSubPage(null)
    setCommandCenterOpen(true)
  }, [])

  const openCommandCenterPage = useCallback((page: CommandCenterPage) => {
    setCommandCenterMounted(true)
    setInitialSubPage(page)
    setCommandCenterOpen(true)
  }, [])

  const openBlogSearch = useCallback(() => {
    openCommandCenterPage('blog')
  }, [openCommandCenterPage])

  const openShortcutsHelp = useCallback(() => {
    openCommandCenterPage('shortcuts')
  }, [openCommandCenterPage])

  useEffect(() => {
    return registerCommandCenterOpener((page) => {
      setCommandCenterMounted(true)
      setInitialSubPage(page)
      setCommandCenterOpen(true)
    })
  }, [])

  useEffect(() => {
    if (typeof window === 'undefined') return

    let idleId: number | undefined
    let timeoutId: number | undefined
    const prefetch = () => {
      void import('@/components/global/shared/CommandCenter')
    }

    if (typeof window.requestIdleCallback === 'function') {
      idleId = window.requestIdleCallback(prefetch, { timeout: 4000 })
    } else {
      timeoutId = window.setTimeout(prefetch, 1500)
    }

    return () => {
      if (idleId !== undefined) window.cancelIdleCallback(idleId)
      if (timeoutId !== undefined) window.clearTimeout(timeoutId)
    }
  }, [])

  const closeCommandCenter = useCallback(() => {
    setCommandCenterOpen(false)
    setInitialSubPage(null)
  }, [])

  const handleInitialSubPageConsumed = useCallback(() => {
    setInitialSubPage(null)
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen,
    onOpenCommandCenter: openCommandCenter,
    onOpenShortcutsHelp: openShortcutsHelp,
  })

  const contextValue = useMemo<KeyboardShortcutsContextValue>(
    () => ({
      openCommandCenter,
      openCommandCenterPage,
      openBlogSearch,
      closeCommandCenter,
      isCommandCenterOpen: commandCenterOpen,
    }),
    [
      openCommandCenter,
      openCommandCenterPage,
      openBlogSearch,
      closeCommandCenter,
      commandCenterOpen,
    ],
  )

  return (
    <KeyboardShortcutsContext.Provider value={contextValue}>
      {children}
      {commandCenterMounted ? (
        <Suspense fallback={null}>
          <LazyCommandCenter
            {...commandCenterProps}
            context={context}
            open={commandCenterOpen}
            onOpenChange={(open) => {
              if (open) setCommandCenterMounted(true)
              setCommandCenterOpen(open)
              if (!open) setInitialSubPage(null)
            }}
            initialSubPage={initialSubPage}
            onInitialSubPageConsumed={handleInitialSubPageConsumed}
          />
        </Suspense>
      ) : null}
    </KeyboardShortcutsContext.Provider>
  )
}
