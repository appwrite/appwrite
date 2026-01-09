import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'

interface CreateTeamDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (teamData: {
    teamId?: string
    name: string
  }) => void
  isLoading?: boolean
}

export function CreateTeamDrawer({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateTeamDrawerProps) {
  const [teamId, setTeamId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setTeamId(undefined)
      setName('')
    }
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!name.trim()) {
      return
    }

    onCreate({
      teamId,
      name: name.trim(),
    })
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title="Create Team"
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <form onSubmit={handleSubmit} className="flex flex-1 flex-col min-h-0">
        <div className="flex-1 overflow-y-auto">
          <div className="px-6 py-6">
            <div className="space-y-5">
              <div className="space-y-2">
                <Label htmlFor="team-id">Team ID</Label>
                <IdInput
                  id="team-id"
                  value={teamId}
                  onChange={setTeamId}
                  maxLength={36}
                  disabled={isLoading}
                  placeholder="Leave blank to auto-generate"
                />
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="name">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="name"
                  type="text"
                  placeholder="My Team"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  disabled={isLoading}
                  maxLength={128}
                  required
                />
              </div>
            </div>
          </div>
        </div>

        <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
          <Button
            type="submit"
            disabled={isLoading || !name.trim()}
          >
            Create Team
          </Button>
          <Button
            type="button"
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            Cancel
          </Button>
        </div>
      </form>
      </>
    </BaseDrawer>
  )
}

