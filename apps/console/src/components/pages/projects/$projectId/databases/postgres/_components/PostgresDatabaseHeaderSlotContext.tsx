import {
  createContext,
  useContext,
  useLayoutEffect,
  useMemo,
  useRef,
  type ReactNode,
} from 'react'

export type PostgresDatabaseHeaderSlotProps = {
  searchPlaceholder?: string
  searchValue?: string
  onSearchChange?: (value: string) => void
  createLabel?: string
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
  showRefresh?: boolean
  onRefresh?: () => void
  isRefreshing?: boolean
  filterTrigger?: ReactNode
}

type PostgresDatabaseHeaderSlotContextValue = {
  setSlot: (slot: PostgresDatabaseHeaderSlotProps) => void
}

const PostgresDatabaseHeaderSlotContext =
  createContext<PostgresDatabaseHeaderSlotContextValue | null>(null)

export function PostgresDatabaseHeaderSlotProvider({
  setSlot,
  children,
}: {
  setSlot: (slot: PostgresDatabaseHeaderSlotProps) => void
  children: ReactNode
}) {
  const setSlotRef = useRef(setSlot)
  setSlotRef.current = setSlot

  const value = useMemo(
    () => ({
      setSlot: (slot: PostgresDatabaseHeaderSlotProps) => {
        setSlotRef.current(slot)
      },
    }),
    [],
  )

  return (
    <PostgresDatabaseHeaderSlotContext.Provider value={value}>
      {children}
    </PostgresDatabaseHeaderSlotContext.Provider>
  )
}

function headerSlotEffectDeps(slot: PostgresDatabaseHeaderSlotProps) {
  return [
    slot.searchPlaceholder,
    slot.searchValue,
    slot.onSearchChange,
    slot.createLabel,
    slot.onCreate,
    slot.createDisabled,
    slot.createDisabledTooltip,
    slot.showRefresh,
    slot.onRefresh,
    slot.isRefreshing,
    slot.filterTrigger,
  ] as const
}

export function usePostgresDatabaseHeaderSlot(
  slot: PostgresDatabaseHeaderSlotProps,
) {
  const context = useContext(PostgresDatabaseHeaderSlotContext)
  const slotRef = useRef(slot)
  slotRef.current = slot

  useLayoutEffect(() => {
    if (!context) return
    context.setSlot(slotRef.current)
    return () => {
      context.setSlot({})
    }
  }, [context, ...headerSlotEffectDeps(slot)])
}
