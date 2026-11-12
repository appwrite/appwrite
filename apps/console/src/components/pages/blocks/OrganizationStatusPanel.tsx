import { useState } from 'react'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { useUpdateOrganizationStatus } from '@/lib/react-query/hooks/manager'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'

type StatusIntent = 'block' | 'unblock'

export function OrganizationStatusPanel() {
  const [teamId, setTeamId] = useState('')
  const [reason, setReason] = useState('')
  const [intent, setIntent] = useState<StatusIntent>('block')

  const mutation = useUpdateOrganizationStatus()

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const v = teamId.trim()
    if (!v) return
    mutation.mutate(
      {
        teamId: v,
        status: intent === 'unblock',
        reason: intent === 'block' ? reason.trim() || undefined : undefined,
      },
      {
        onSuccess: (organization) => {
          toast.success(
            intent === 'block'
              ? 'Organization blocked'
              : 'Organization reinstated',
            { description: organization.name || organization.$id },
          )
          setTeamId('')
          setReason('')
        },
        onError: (err) => {
          toast.error('Could not update organization status', {
            description: err instanceof Error ? err.message : String(err),
          })
        },
      },
    )
  }

  const disabled = !teamId.trim() || mutation.isPending

  return (
    <section className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Organization status
        </h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Block or reinstate an organization by its ID. Changes take effect
          immediately.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />

        <div className="px-6 py-4 space-y-4">
          {/* Intent tabs */}
          <div className="flex items-center gap-1 border-b border-border">
            {(['block', 'unblock'] as StatusIntent[]).map((i) => {
              const active = intent === i
              return (
                <button
                  key={i}
                  type="button"
                  onClick={() => setIntent(i)}
                  aria-selected={active}
                  role="tab"
                  className={cn(
                    'relative px-3 py-2 text-[13px] font-medium transition-colors capitalize',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset rounded-sm',
                    active
                      ? 'text-foreground'
                      : 'text-muted-foreground hover:text-foreground/80',
                  )}
                >
                  {i === 'block' ? 'Block' : 'Unblock'}
                  {active && (
                    <span className="absolute bottom-[-1px] start-0 end-0 h-[2px] bg-foreground" />
                  )}
                </button>
              )
            })}
          </div>

          <div className="space-y-2">
            <Label htmlFor="organization-id">Organization ID</Label>
            <Input
              id="organization-id"
              value={teamId}
              onChange={(e) => setTeamId(e.target.value)}
              placeholder="69eb0a7a607272ce4656"
              autoComplete="off"
              spellCheck={false}
              className="h-9 max-w-lg border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>

          <div
            className={cn(
              'space-y-2 transition-opacity',
              intent === 'unblock' && 'invisible',
            )}
            aria-hidden={intent === 'unblock'}
          >
            <Label htmlFor="organization-reason">Reason (optional)</Label>
            <Textarea
              id="organization-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={2}
              maxLength={255}
              placeholder="Recorded for the audit trail only; not shown to organization members."
              tabIndex={intent === 'unblock' ? -1 : 0}
              disabled={intent === 'unblock'}
              className="max-w-lg resize-none border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex items-center justify-end gap-2">
          <Button
            type="submit"
            size="sm"
            variant={intent === 'block' ? 'destructive' : 'default'}
            className="h-9 text-[13px]"
            disabled={disabled}
          >
            {mutation.isPending && (
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
            )}
            {intent === 'block'
              ? 'Block organization'
              : 'Reinstate organization'}
          </Button>
        </div>
      </form>
    </section>
  )
}
