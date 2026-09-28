import type { Client } from '@appwrite.io/console'
import { translate } from '@/lib/i18n/translate'
import { getSdkCallResourcePath } from '@/lib/terraform/resource'

export type TerraformChangeAction = 'update' | 'delete'

export type TerraformChangeRequest = {
  projectId: string
  resource: string
  action: TerraformChangeAction
  /** SDK call, e.g. `functions.updateVariable`. */
  call: string
}

export type TerraformChangeConfirmer = {
  /** Resolves true to let the write through. Return `true` directly when no prompt is needed. */
  confirm: (request: TerraformChangeRequest) => boolean | Promise<boolean>
  /** Called after a write to a Terraform-managed resource succeeds. */
  changed: (request: TerraformChangeRequest) => void
}

let confirmer: TerraformChangeConfirmer | null = null

export function setTerraformChangeConfirmer(
  next: TerraformChangeConfirmer | null,
): void {
  confirmer = next
}

export class TerraformChangeCancelledError extends Error {
  readonly request: TerraformChangeRequest

  constructor(request: TerraformChangeRequest) {
    super(
      translate('No changes were made. This resource is managed by Terraform.'),
    )
    this.name = 'TerraformChangeCancelledError'
    this.request = request
  }
}

type SdkMethod = (...args: unknown[]) => unknown

function guardService(name: string, service: object, client: Client): object {
  return new Proxy(service, {
    get(target, property, receiver) {
      const value = Reflect.get(target, property, receiver)
      if (typeof value !== 'function' || typeof property !== 'string') {
        return value
      }
      const method = value as SdkMethod
      return (...args: unknown[]) => {
        const resource = getSdkCallResourcePath(name, property, args[0])
        if (!resource || !confirmer) return method.apply(target, args)

        const active = confirmer
        const request: TerraformChangeRequest = {
          projectId: client.config.project,
          resource,
          action: property.startsWith('delete') ? 'delete' : 'update',
          call: `${name}.${property}`,
        }
        const execute = () =>
          Promise.resolve(method.apply(target, args)).then((result) => {
            active.changed(request)
            return result
          })
        const confirmed = active.confirm(request)
        if (confirmed === true) return execute()

        // The project client is shared, so pin it back to this project once the user answers.
        const endpoint = client.config.endpoint
        const project = client.config.project
        return Promise.resolve(confirmed).then((allowed) => {
          if (!allowed) throw new TerraformChangeCancelledError(request)
          client.setEndpoint(endpoint).setProject(project)
          return execute()
        })
      }
    },
  })
}

/**
 * Wraps project SDK services so writes to Terraform-managed resources ask the
 * registered confirmer first. Services pass through untouched when no
 * confirmer is registered (outside project routes).
 */
export function guardTerraformChanges<T extends Record<string, unknown>>(
  services: T,
  client: Client,
): T {
  return Object.fromEntries(
    Object.entries(services).map(([name, service]) => [
      name,
      service && typeof service === 'object' && service !== client
        ? guardService(name, service, client)
        : service,
    ]),
  ) as T
}
