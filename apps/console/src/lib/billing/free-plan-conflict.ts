export type OrgSummary = {
  $id: string
  name: string
}

export function resolveOrgToDelete(
  deleteChoiceId: string | null | undefined,
  otherFreeOrg: OrgSummary,
  currentOrg: OrgSummary | null | undefined,
): OrgSummary | null {
  if (deleteChoiceId === otherFreeOrg.$id) return otherFreeOrg
  if (currentOrg && deleteChoiceId === currentOrg.$id) return currentOrg
  return null
}
