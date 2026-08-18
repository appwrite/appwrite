import {
  createContext,
  useContext,
  useLayoutEffect,
  useMemo,
  useRef,
  type ReactNode,
} from 'react'

export type MysqlDatabaseHeaderSlotProps = {
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

type MysqlDatabaseHeaderSlotContextValue = {
  setSlot: (slot: MysqlDatabaseHeaderSlotProps) => void
}

const MysqlDatabaseHeaderSlotContext =
  createContext<MysqlDatabaseHeaderSlotContextValue | null>(null)

export function MysqlDatabaseHeaderSlotProvider({
  setSlot,
  children,
}: {
  setSlot: (slot: MysqlDatabaseHeaderSlotProps) => void
  children: ReactNode
}) {
  const setSlotRef = useRef(setSlot)
  setSlotRef.current = setSlot

  const value = useMemo(
    () => ({
      setSlot: (slot: MysqlDatabaseHeaderSlotProps) => {
        setSlotRef.current(slot)
      },
    }),
    [],
  )

  return (
    <MysqlDatabaseHeaderSlotContext.Provider value={value}>
      {children}
    </MysqlDatabaseHeaderSlotContext.Provider>
  )
}

function headerSlotEffectDeps(slot: MysqlDatabaseHeaderSlotProps) {
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

export function useMysqlDatabaseHeaderSlot(
  slot: MysqlDatabaseHeaderSlotProps,
) {
  const context = useContext(MysqlDatabaseHeaderSlotContext)
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
