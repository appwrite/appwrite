import type { QueryClient } from '@tanstack/react-query'

/**
 * Refetch org billing data after addon or plan changes that may create or update invoices.
 */
export async function refetchOrganizationBillingQueries(
  queryClient: QueryClient,
  organizationId: string,
): Promise<void> {
  await Promise.all([
    queryClient.refetchQueries({
      queryKey: ['invoices', 'organization', organizationId],
      type: 'all',
    }),
    queryClient.refetchQueries({
      queryKey: ['billing-aggregation', 'organization', organizationId],
      type: 'all',
    }),
    queryClient.refetchQueries({
      queryKey: ['organization', organizationId],
    }),
  ])
}
