import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { Models } from '@appwrite.io/console'

interface UpdateRecordDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  record: Models.DnsRecord
  onUpdate: (data: {
    type: string
    name: string
    value: string
    ttl: number
    priority?: number
    weight?: number
    port?: number
    comment?: string
  }) => void
  isLoading?: boolean
}

export function UpdateRecordDialog({
  open,
  onOpenChange,
  record,
  onUpdate,
  isLoading = false,
}: UpdateRecordDialogProps) {
  const [name, setName] = useState(record.name || '')
  const [value, setValue] = useState(record.value || '')
  const [ttl, setTtl] = useState(record.ttl?.toString() || '3600')
  const [priority, setPriority] = useState(record.priority?.toString() || '')
  const [weight, setWeight] = useState(record.weight?.toString() || '')
  const [port, setPort] = useState(record.port?.toString() || '')
  const [comment, setComment] = useState(record.comment || '')

  // Update form when record changes
  useEffect(() => {
    if (open && record) {
      setName(record.name || '')
      setValue(record.value || '')
      setTtl(record.ttl?.toString() || '3600')
      setPriority(record.priority?.toString() || '')
      setWeight(record.weight?.toString() || '')
      setPort(record.port?.toString() || '')
      setComment(record.comment || '')
    }
  }, [open, record])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim() || !value.trim()) return

    onUpdate({
      type: record.type,
      name: name.trim(),
      value: value.trim(),
      ttl: parseInt(ttl) || 3600,
      priority:
        record.type === 'MX' || record.type === 'SRV'
          ? parseInt(priority) || undefined
          : undefined,
      weight: record.type === 'SRV' ? parseInt(weight) || undefined : undefined,
      port: record.type === 'SRV' ? parseInt(port) || undefined : undefined,
      comment: comment.trim() || undefined,
    })
  }

  const requiresPriority = record.type === 'MX' || record.type === 'SRV'
  const requiresSRVFields = record.type === 'SRV'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0 max-h-[90vh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Update DNS Record</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Update the DNS record ({record.type}).
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="type">Type</Label>
              <Input id="type" value={record.type} disabled />
            </div>

            <div className="space-y-2">
              <Label htmlFor="name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="@ or subdomain"
                disabled={isLoading}
                autoFocus
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="value">
                Value <span className="text-destructive">*</span>
              </Label>
              <Input
                id="value"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                disabled={isLoading}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="ttl">
                TTL <span className="text-destructive">*</span>
              </Label>
              <Input
                id="ttl"
                type="number"
                value={ttl}
                onChange={(e) => setTtl(e.target.value)}
                min="1"
                disabled={isLoading}
              />
            </div>

            {requiresPriority && (
              <div className="space-y-2">
                <Label htmlFor="priority">
                  Priority <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="priority"
                  type="number"
                  value={priority}
                  onChange={(e) => setPriority(e.target.value)}
                  min="0"
                  disabled={isLoading}
                />
              </div>
            )}

            {requiresSRVFields && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="weight">
                    Weight <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="weight"
                    type="number"
                    value={weight}
                    onChange={(e) => setWeight(e.target.value)}
                    min="0"
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="port">
                    Port <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="port"
                    type="number"
                    value={port}
                    onChange={(e) => setPort(e.target.value)}
                    min="1"
                    max="65535"
                    disabled={isLoading}
                  />
                </div>
              </>
            )}

            <div className="space-y-2">
              <Label htmlFor="comment">Comment (optional)</Label>
              <Input
                id="comment"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Optional comment"
                disabled={isLoading}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim() || !value.trim()}>
              Update Record
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

