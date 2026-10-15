import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  AgentModelForm,
  type AgentModelFormProps,
} from '@/components/global/providers/agent/AgentModelForm'
import type { AssistantModel } from '@/lib/react-query/hooks'

type AgentModelDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  model?: AssistantModel | null
  disabled?: boolean
  onSaved?: AgentModelFormProps['onSaved']
}

/** Add / update model form in the shared right drawer. */
export function AgentModelDrawer({
  open,
  onOpenChange,
  model = null,
  disabled = false,
  onSaved,
}: AgentModelDrawerProps) {
  const isEditing = Boolean(model)

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isEditing ? 'Update model' : 'Add model'}
      description="Configure the provider, model ID, and API key."
      maxWidth="sm:max-w-lg"
    >
      <div className="border-t border-border shrink-0" />
      <AgentModelForm
        model={model}
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
