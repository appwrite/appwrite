import { useEffect, useState } from 'react'
import { Query } from '@appwrite.io/console'
import { Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  useSaveAnalyticsGoal,
  type AnalyticsGoal,
  type AnalyticsGoalType,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

const MAX_PROPS = 5

type PropMatch = { key: string; value: string }

/** Only `equal` prop filters are editable here; any other stored filter is kept as is. */
function splitQueries(queries: string[]): {
  props: PropMatch[]
  kept: string[]
} {
  const props: PropMatch[] = []
  const kept: string[] = []
  for (const query of queries) {
    try {
      const parsed = JSON.parse(query) as {
        method?: string
        attribute?: string
        values?: unknown[]
      }
      if (
        parsed.method === 'equal' &&
        parsed.attribute?.startsWith('props.') &&
        parsed.values?.length === 1
      ) {
        props.push({
          key: parsed.attribute.slice('props.'.length),
          value: String(parsed.values[0]),
        })
        continue
      }
    } catch {
      // Not JSON: keep it untouched.
    }
    kept.push(query)
  }
  return { props, kept }
}

/** Create a goal, or edit one when `goal` is set. `eventName` prefills a new event goal. */
export function SaveGoal({
  open,
  onOpenChange,
  projectId,
  propertyId,
  goal,
  eventName,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  propertyId: string
  goal?: AnalyticsGoal
  eventName?: string
}) {
  const t = useT()
  const mutation = useSaveAnalyticsGoal(projectId, propertyId)
  const [name, setName] = useState('')
  const [type, setType] = useState<AnalyticsGoalType>('event')
  const [event, setEvent] = useState('')
  const [path, setPath] = useState('')
  const [props, setProps] = useState<PropMatch[]>([])
  const [kept, setKept] = useState<string[]>([])

  useEffect(() => {
    if (!open) return
    const split = splitQueries(goal?.queries ?? [])
    setName(goal?.name ?? eventName ?? '')
    setType(goal?.type ?? 'event')
    setEvent(goal?.eventName ?? eventName ?? '')
    setPath(goal?.pagePath ?? '')
    setProps(split.props)
    setKept(split.kept)
  }, [open, goal, eventName])

  const target = type === 'event' ? event.trim() : path.trim()
  const filledProps = props.filter((prop) => prop.key.trim() !== '')
  const canSave = name.trim() !== '' && target !== '' && !mutation.isPending

  const save = () => {
    if (!canSave) return
    mutation.mutate(
      {
        goalId: goal?.$id,
        name,
        type,
        eventName: event,
        pagePath: path,
        queries: [
          ...kept,
          ...filledProps.map((prop) =>
            Query.equal(`props.${prop.key.trim()}`, [prop.value]),
          ),
        ],
      },
      {
        onSuccess: () => {
          toast.success(goal ? t('Goal updated') : t('Goal added'))
          onOpenChange(false)
        },
        onError: (error) => toast.error(getErrorMessage(error)),
      },
    )
  }

  const updateProp = (index: number, change: Partial<PropMatch>) =>
    setProps((current) =>
      current.map((prop, i) => (i === index ? { ...prop, ...change } : prop)),
    )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="p-0 sm:max-w-md">
        <form
          onSubmit={(submitEvent) => {
            submitEvent.preventDefault()
            save()
          }}
        >
          <DialogHeader className="px-6 pb-4 pt-6 text-left">
            <DialogTitle>{goal ? t('Edit goal') : t('Add goal')}</DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              {t(
                'A goal counts visitors who complete an event or view a page, and adds a conversion rate to every breakdown.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 py-4">
            <div className="space-y-2">
              <Label htmlFor="goal-name">{t('Name')}</Label>
              <Input
                id="goal-name"
                value={name}
                onChange={(changeEvent) => setName(changeEvent.target.value)}
                maxLength={128}
                placeholder={t('Signed up')}
                autoFocus
              />
            </div>
            <div className="space-y-2">
              <Label>{t('Completes on')}</Label>
              <ToggleGroup
                type="single"
                variant="outline"
                value={type}
                onValueChange={(value) =>
                  value && setType(value as AnalyticsGoalType)
                }
                className="justify-start"
              >
                <ToggleGroupItem value="event" className="text-[13px]">
                  {t('Custom event')}
                </ToggleGroupItem>
                <ToggleGroupItem value="page" className="text-[13px]">
                  {t('Page view')}
                </ToggleGroupItem>
              </ToggleGroup>
            </div>
            {type === 'event' ? (
              <div className="space-y-2">
                <Label htmlFor="goal-event">{t('Event name')}</Label>
                <Input
                  id="goal-event"
                  value={event}
                  onChange={(changeEvent) => setEvent(changeEvent.target.value)}
                  maxLength={64}
                  placeholder="signup"
                  className="font-mono"
                />
              </div>
            ) : (
              <div className="space-y-2">
                <Label htmlFor="goal-path">{t('Page path')}</Label>
                <Input
                  id="goal-path"
                  value={path}
                  onChange={(changeEvent) => setPath(changeEvent.target.value)}
                  maxLength={2048}
                  placeholder="/pricing"
                  className="font-mono"
                />
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Use * for one path segment and ** for any depth, e.g. /blog/**.',
                  )}
                </p>
              </div>
            )}
            <div className="space-y-2">
              <Label>{t('Matching properties')}</Label>
              {props.map((prop, index) => (
                <div key={index} className="flex items-center gap-2">
                  <Input
                    value={prop.key}
                    onChange={(changeEvent) =>
                      updateProp(index, { key: changeEvent.target.value })
                    }
                    placeholder={t('Key')}
                    aria-label={t('Property key')}
                    className="font-mono"
                  />
                  <Input
                    value={prop.value}
                    onChange={(changeEvent) =>
                      updateProp(index, { value: changeEvent.target.value })
                    }
                    placeholder={t('Value')}
                    aria-label={t('Property value')}
                    className="font-mono"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9 shrink-0"
                    aria-label={t('Remove')}
                    onClick={() =>
                      setProps((current) =>
                        current.filter((_, i) => i !== index),
                      )
                    }
                  >
                    <X className="h-3.5 w-3.5" />
                  </Button>
                </div>
              ))}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                disabled={props.length + kept.length >= MAX_PROPS}
                onClick={() =>
                  setProps((current) => [...current, { key: '', value: '' }])
                }
              >
                <Plus className="mr-1.5 h-3.5 w-3.5" />
                {t('Add property')}
              </Button>
            </div>
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={!canSave}>
              {goal ? t('Update') : t('Add')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
