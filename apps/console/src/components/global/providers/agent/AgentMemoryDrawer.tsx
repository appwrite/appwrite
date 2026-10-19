import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  AgentMemoryForm,
  type AgentMemoryFormProps,
} from '@/components/global/providers/agent/AgentMemoryForm'
import type { AssistantMemory } from '@/lib/react-query/hooks'

type AgentMemoryDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  memory?: AssistantMemory | null
  disabled?: boolean
  onSaved?: AgentMemoryFormProps['onSaved']
}

/** Add / update memory form in the shared right drawer. */
export function AgentMemoryDrawer({
  open,
  onOpenChange,
  memory = null,
  disabled = false,
  onSaved,
}: AgentMemoryDrawerProps) {
  const isEditing = Boolean(memory)

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isEditing ? 'Update memory' : 'Add memory'}
      description="Preferences, instructions, and facts the Appwrite Agent can reuse across conversations."
      maxWidth="sm:max-w-lg"
    >
      <div className="border-t border-border shrink-0" />
      <AgentMemoryForm
        memory={memory}
        disabled={disabled}
        onCancel={() => onOpenChange(false)}
        onSaved={(saved) => {
          onSaved?.(saved)
          onOpenChange(false)
        }}
      />
    </BaseDrawer>
  )
}
