import {
  createContext,
  useContext,
  useLayoutEffect,
  useMemo,
  useRef,
  type ReactNode,
} from 'react'

export type MysqlTableHeaderSlotProps = {
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

type MysqlTableHeaderSlotContextValue = {
  setSlot: (slot: MysqlTableHeaderSlotProps) => void
}

const MysqlTableHeaderSlotContext =
  createContext<MysqlTableHeaderSlotContextValue | null>(null)

export function MysqlTableHeaderSlotProvider({
  setSlot,
  children,
}: {
  setSlot: (slot: MysqlTableHeaderSlotProps) => void
  children: ReactNode
}) {
  const setSlotRef = useRef(setSlot)
  setSlotRef.current = setSlot

  const value = useMemo(
    () => ({
      setSlot: (slot: MysqlTableHeaderSlotProps) => {
        setSlotRef.current(slot)
      },
    }),
    [],
  )

  return (
    <MysqlTableHeaderSlotContext.Provider value={value}>
      {children}
    </MysqlTableHeaderSlotContext.Provider>
  )
}

function headerSlotEffectDeps(slot: MysqlTableHeaderSlotProps) {
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

export function useMysqlTableHeaderSlot(slot: MysqlTableHeaderSlotProps) {
  const context = useContext(MysqlTableHeaderSlotContext)
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
