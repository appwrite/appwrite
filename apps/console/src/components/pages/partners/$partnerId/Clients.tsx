import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Users } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import {
  PartnerListLoading,
  PartnerPage,
} from '@/components/pages/partners/$partnerId/PageShell'
import {
  createPartnerClient,
  partnerClientsQueryOptions,
} from '@/lib/react-query/hooks'
import {
  isPartnerClientPaymentIssue,
  PARTNER_CLIENT_PLANS,
} from '@/lib/partners'
import { BillingPlanTier } from '@/lib/constants/billing-plan'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getBillingPlanDisplayLabel } from '@/lib/utils/plan-filter'

export function PartnerClients({ partnerId }: { partnerId: string }) {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery(partnerClientsQueryOptions(partnerId))
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [plan, setPlan] = useState<string>(BillingPlanTier.Tier1)
  const [budget, setBudget] = useState('')

  const createMutation = useMutation({
    mutationFn: () =>
      createPartnerClient({
        partnerId,
        name,
        billingPlan: plan,
        budget: budget ? Number(budget) : undefined,
      }),
    onSuccess: async () => {
      toast.success('Client organization created')
      setOpen(false)
      setName('')
      setBudget('')
      await queryClient.invalidateQueries({ queryKey: ['partners', partnerId] })
      await queryClient.invalidateQueries({ queryKey: ['organizations', 'console'] })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, 'Could not create client'))
    },
  })

  const clients = data?.teams ?? []

  return (
    <PartnerPage
      title="Clients"
      createLabel="New client"
      onCreate={() => setOpen(true)}
    >
      {isLoading ? (
        <PartnerListLoading label="Loading clients..." />
      ) : clients.length === 0 ? (
        <EmptyState
          icon={Users}
          title="No clients yet"
          description="Create a paid client organization to hand off projects."
          isEmpty
          variant="card"
        />
      ) : (
        <div className={RESOURCE_CARD_GRID_CLASSNAME}>
          {clients.map((client) => {
            const failed = isPartnerClientPaymentIssue(client)
            return (
              <Link
                key={client.$id}
                to="/organizations/$orgId"
                params={{ orgId: client.$id }}
                target="_blank"
                rel="noopener noreferrer"
                className="block h-full min-w-0"
              >
                <ResourceCard
                  title={client.name || client.$id}
                  resourceId={client.$id}
                  avatar={client.name || client.$id}
                  status={failed ? 'failed' : 'active'}
                  statusLabel={
                    failed ? 'Payment failed' : client.status || 'active'
                  }
                  metadata={[
                    {
                      label: 'Plan',
                      value: getBillingPlanDisplayLabel(client.billingPlan),
                    },
                    {
                      label: 'Status',
                      value: client.status || '-',
                    },
                  ]}
                />
              </Link>
            )
          })}
        </div>
      )}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create client organization</DialogTitle>
            <DialogDescription>
              Choose a paid plan. Payment uses this partner organization&apos;s
              card.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="client-name">Name</Label>
              <Input
                id="client-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label>Plan</Label>
              <Select value={plan} onValueChange={setPlan}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PARTNER_CLIENT_PLANS.map((item) => (
                    <SelectItem key={item.id} value={item.id}>
                      {item.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="client-budget">Budget cap (USD, optional)</Label>
              <Input
                id="client-budget"
                type="number"
                min={0}
                value={budget}
                onChange={(event) => setBudget(event.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button
              disabled={!name.trim() || createMutation.isPending}
              onClick={() => createMutation.mutate()}
            >
              Create
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PartnerPage>
  )
}
