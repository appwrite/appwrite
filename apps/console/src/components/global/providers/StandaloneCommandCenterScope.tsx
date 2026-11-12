import {
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

type CommandCenterComponent =
  (typeof import('@/components/global/shared/CommandCenter'))['CommandCenter']

type StandaloneCommandCenterScopeProps = {
  children: ReactNode
} & Omit<
  ComponentProps<CommandCenterComponent>,
  'open' | 'onOpenChange' | 'initialSubPage' | 'onInitialSubPageConsumed'
>

function loadCommandCenter(): Promise<CommandCenterComponent> {
  return import('@/components/global/shared/CommandCenter').then(
    (module) => module.CommandCenter,
  )
}

/** Command center + global shortcuts for pages outside project/org providers (e.g. home). */
export function StandaloneCommandCenterScope({
  children,
  context = 'account',
  ...commandCenterProps
}: StandaloneCommandCenterScopeProps) {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [commandCenterMounted, setCommandCenterMounted] = useState(false)
  const [CommandCenterImpl, setCommandCenterImpl] =
    useState<CommandCenterComponent | null>(null)
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
    if (!commandCenterMounted || CommandCenterImpl) return
    let cancelled = false
    void loadCommandCenter().then((component) => {
      if (!cancelled) setCommandCenterImpl(() => component)
    })
    return () => {
      cancelled = true
    }
  }, [commandCenterMounted, CommandCenterImpl])

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
      {commandCenterMounted && CommandCenterImpl ? (
        <CommandCenterImpl
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
      ) : null}
    </KeyboardShortcutsContext.Provider>
  )
}
