import {
  createContext,
  useContext,
  useLayoutEffect,
  useMemo,
  useRef,
  type ReactNode,
} from 'react'

export type PostgresTableHeaderSlotProps = {
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

type PostgresTableHeaderSlotContextValue = {
  setSlot: (slot: PostgresTableHeaderSlotProps) => void
}

const PostgresTableHeaderSlotContext =
  createContext<PostgresTableHeaderSlotContextValue | null>(null)

export function PostgresTableHeaderSlotProvider({
  setSlot,
  children,
}: {
  setSlot: (slot: PostgresTableHeaderSlotProps) => void
  children: ReactNode
}) {
  const setSlotRef = useRef(setSlot)
  setSlotRef.current = setSlot

  const value = useMemo(
    () => ({
      setSlot: (slot: PostgresTableHeaderSlotProps) => {
        setSlotRef.current(slot)
      },
    }),
    [],
  )

  return (
    <PostgresTableHeaderSlotContext.Provider value={value}>
      {children}
    </PostgresTableHeaderSlotContext.Provider>
  )
}

function headerSlotEffectDeps(slot: PostgresTableHeaderSlotProps) {
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

export function usePostgresTableHeaderSlot(slot: PostgresTableHeaderSlotProps) {
  const context = useContext(PostgresTableHeaderSlotContext)
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
