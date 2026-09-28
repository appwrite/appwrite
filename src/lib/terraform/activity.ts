import type { Models } from '@appwrite.io/console'

type TerraformActivityFields = Pick<
  Models.ActivityEvent,
  'sdk' | 'sdkVersion' | 'userAgent'
>

/** Provider releases before `x-sdk-name: Terraform` only identify themselves here. */
const PROVIDER_USER_AGENT = /(?:^|\s)terraform-provider-appwrite\/(\S+)/i

const TERRAFORM_SDK = 'terraform'

/** Whether the Appwrite Terraform provider made this request. */
export function isTerraformActivity(
  event: Pick<TerraformActivityFields, 'sdk' | 'userAgent'> | null | undefined,
): boolean {
  if (!event) return false
  if (event.sdk?.trim().toLowerCase() === TERRAFORM_SDK) return true
  return PROVIDER_USER_AGENT.test(event.userAgent ?? '')
}

export function getTerraformProviderVersion(
  event: TerraformActivityFields,
): string | null {
  const match = PROVIDER_USER_AGENT.exec(event.userAgent ?? '')
  if (match) return match[1]
  if (event.sdk?.trim().toLowerCase() === TERRAFORM_SDK && event.sdkVersion) {
    return event.sdkVersion
  }
  return null
}

/** First product token of a user agent, e.g. `curl/8.7.1` or `AppwriteCLI/27.3.0`. */
export function getUserAgentClient(userAgent: string): string | null {
  const token = userAgent.trim().split(/\s+/)[0]
  return token || null
}
