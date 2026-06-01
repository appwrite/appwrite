export type OrgSummary = {
  $id: string
  name: string
}

export const NEW_ORG_CHOICE = '__new_organization__'

export function resolveOrgToDelete(
  keepChoiceId: string,
  otherFreeOrg: OrgSummary,
  currentOrg: OrgSummary | null | undefined,
  showCurrentOrgOption: boolean,
): OrgSummary | null {
  if (keepChoiceId === NEW_ORG_CHOICE) return otherFreeOrg
  if (keepChoiceId === currentOrg?.$id) return otherFreeOrg
  if (keepChoiceId === otherFreeOrg.$id && showCurrentOrgOption && currentOrg) {
    return currentOrg
  }
  return null
}
