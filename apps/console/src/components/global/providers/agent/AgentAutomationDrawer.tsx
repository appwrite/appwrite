import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  AgentAutomationForm,
  type AgentAutomationFormProps,
} from '@/components/global/providers/agent/AgentAutomationForm'
import type { AssistantAutomation } from '@/lib/react-query/hooks'

type AgentAutomationDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  automation?: AssistantAutomation | null
  disabled?: boolean
  resolveProjectId?: AgentAutomationFormProps['resolveProjectId']
  onAddModel?: AgentAutomationFormProps['onAddModel']
  onSaved?: AgentAutomationFormProps['onSaved']
}

/** Create / update automation form in the shared right drawer. */
export function AgentAutomationDrawer({
  open,
  onOpenChange,
  automation = null,
  disabled = false,
  resolveProjectId,
  onAddModel,
  onSaved,
}: AgentAutomationDrawerProps) {
  const isEditing = Boolean(automation)

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={isEditing ? 'Update automation' : 'Create automation'}
      description="Run a prompt on a schedule. Each run creates a new agent conversation."
      maxWidth="sm:max-w-lg"
    >
      <div className="border-t border-border shrink-0" />
      <AgentAutomationForm
        automation={automation}
        disabled={disabled}
        resolveProjectId={resolveProjectId}
        onAddModel={onAddModel}
        onCancel={() => onOpenChange(false)}
        onSaved={(saved) => {
          // Parent closes the drawer / navigates after save or delete.
          onSaved?.(saved)
        }}
      />
    </BaseDrawer>
  )
}
