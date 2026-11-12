import { Permission, Role } from '@appwrite.io/console'

export function buildInitTicketFilePermissions(): string[] {
  return [Permission.read(Role.any())]
}
