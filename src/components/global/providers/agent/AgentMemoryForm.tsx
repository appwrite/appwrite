import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useCreateAssistantMemory,
  useDeleteAssistantMemory,
  useUpdateAssistantMemory,
  type AssistantMemory,
} from '@/lib/react-query/hooks'

const MEMORY_CATEGORIES = ['preference', 'instruction', 'fact'] as const
type MemoryCategory = (typeof MEMORY_CATEGORIES)[number]

type MemoryFormState = {
  key: string
  content: string
  category: MemoryCategory
  priority: string
  active: boolean
}

const emptyForm = (): MemoryFormState => ({
  key: '',
  content: '',
  category: 'preference',
  priority: '0',
  active: true,
})

function formFromMemory(memory: AssistantMemory): MemoryFormState {
  const category = MEMORY_CATEGORIES.includes(
    memory.category as MemoryCategory,
  )
    ? (memory.category as MemoryCategory)
    : 'preference'
  return {
    key: memory.key || '',
    content: memory.content || '',
    category,
    priority: String(memory.priority ?? 0),
    active: memory.status !== 'archived',
  }
}

function categoryLabel(category: MemoryCategory, t: (text: string) => string) {
  switch (category) {
    case 'instruction':
      return t('Instruction')
    case 'fact':
      return t('Fact')
    case 'preference':
    default:
      return t('Preference')
  }
}

export type AgentMemoryFormProps = {
  memory?: AssistantMemory | null
  disabled?: boolean
  onCancel: () => void
  /** Called after create/update with the saved memory, or after delete with null. */
  onSaved?: (memory?: AssistantMemory | null) => void
}

export function AgentMemoryForm({
  memory = null,
  disabled = false,
  onCancel,
  onSaved,
}: AgentMemoryFormProps) {
  const t = useT()
  const createMutation = useCreateAssistantMemory()
  const updateMutation = useUpdateAssistantMemory()
  const deleteMutation = useDeleteAssistantMemory()
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [form, setForm] = useState<MemoryFormState>(() =>
    memory ? formFromMemory(memory) : emptyForm(),
  )

  useEffect(() => {
    setForm(memory ? formFromMemory(memory) : emptyForm())
  }, [memory])

  const isEditing = Boolean(memory?.$id)
  const isSaving = createMutation.isPending || updateMutation.isPending
  const isDeleting = deleteMutation.isPending

  const closeDeleteDialog = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
  }

  const handleDelete = async () => {
    if (!memory?.$id || isDeleting || disabled) return
    try {
      await deleteMutation.mutateAsync(memory.$id)
      toast.success(t('Memory deleted'))
      closeDeleteDialog()
      onSaved?.(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete memory')))
    }
  }

  const priorityValue = Number.parseInt(form.priority, 10)
  const canSave =
    form.key.trim().length > 0 &&
    form.content.trim().length > 0 &&
    Number.isFinite(priorityValue)

  const handleSave = async () => {
    if (!canSave || isSaving || isDeleting || disabled) return
    const status = form.active ? 'active' : 'archived'
    try {
      if (isEditing && memory?.$id) {
        const updated = await updateMutation.mutateAsync({
          memoryId: memory.$id,
          content: form.content.trim(),
          category: form.category,
          priority: priorityValue,
          status,
        })
        toast.success(t('Memory updated'))
        onSaved?.(updated)
        return
      }
      const created = await createMutation.mutateAsync({
        scope: 'user',
        key: form.key.trim(),
        content: form.content.trim(),
        category: form.category,
        priority: priorityValue,
        status,
        source: 'user',
      })
      toast.success(t('Memory created'))
      onSaved?.(created)
    } catch (error) {
      toast.error(
        getErrorMessage(
          error,
          isEditing ? t('Failed to update memory') : t('Failed to create memory'),
        ),
      )
    }
  }

  return (
    <>
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="space-y-4 px-6 py-4">
          <div className="space-y-2">
            <Label htmlFor="assistant-memory-key">{t('Key')}</Label>
            <Input
              id="assistant-memory-key"
              value={form.key}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  key: event.target.value,
                }))
              }
              placeholder="style.concise"
              className="h-9 font-mono text-[13px]"
              disabled={disabled || isEditing}
            />
            {isEditing ? (
              <p className="text-[11px] text-muted-foreground">
                {t('Key cannot be changed after creation.')}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground">
                {t('A stable key for this memory within your account.')}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="assistant-memory-content">{t('Content')}</Label>
            <Textarea
              id="assistant-memory-content"
              value={form.content}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  content: event.target.value,
                }))
              }
              placeholder={t('What should the agent remember?')}
              className="min-h-28 text-[13px]"
              disabled={disabled}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="assistant-memory-category">{t('Category')}</Label>
            <Select
              value={form.category}
              onValueChange={(value) =>
                setForm((current) => ({
                  ...current,
                  category: value as MemoryCategory,
                }))
              }
              disabled={disabled}
            >
              <SelectTrigger
                id="assistant-memory-category"
                className="h-9 text-[13px]"
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {MEMORY_CATEGORIES.map((category) => (
                  <SelectItem key={category} value={category}>
                    {categoryLabel(category, t)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="assistant-memory-priority">{t('Priority')}</Label>
            <Input
              id="assistant-memory-priority"
              type="number"
              value={form.priority}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  priority: event.target.value,
                }))
              }
              className="h-9 text-[13px]"
              disabled={disabled}
            />
            <p className="text-[11px] text-muted-foreground">
              {t('Higher values are kept first when space is limited.')}
            </p>
          </div>

          <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {t('Active')}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('Include this memory when the agent runs.')}
              </p>
            </div>
            <Switch
              checked={form.active}
              onCheckedChange={(checked) =>
                setForm((current) => ({ ...current, active: checked }))
              }
              disabled={disabled}
            />
          </div>

          {isEditing ? (
            <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
              <div className="px-4 py-3">
                <h3 className="text-[13px] font-semibold text-foreground">
                  {t('Delete memory')}
                </h3>
                <p className="mt-1 text-[12px] text-muted-foreground">
                  {t('This permanently deletes the memory.')}{' '}
                  {t('This action cannot be undone.')}
                </p>
              </div>
              <div className="border-t border-destructive/20 px-4 py-3 bg-destructive/5">
                <Button
                  type="button"
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={disabled || isDeleting || isSaving}
                  onClick={() => setDeleteOpen(true)}
                >
                  {t('Delete')}
                </Button>
              </div>
            </div>
          ) : null}
        </div>
      </div>

      <div className="flex shrink-0 items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
        <Button
          type="button"
          disabled={!canSave || isSaving || isDeleting || disabled}
          onClick={() => void handleSave()}
        >
          {isEditing ? t('Update') : t('Create')}
        </Button>
        <Button
          type="button"
          variant="outline"
          disabled={isSaving || isDeleting}
          onClick={onCancel}
        >
          {t('Cancel')}
        </Button>
      </div>

      <Dialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open) closeDeleteDialog()
          else setDeleteOpen(true)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete memory')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This permanently deletes the memory.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isDeleting}
              onClick={closeDeleteDialog}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={isDeleting}
              onClick={() => void handleDelete()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
