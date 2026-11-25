import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { useT } from '@/lib/i18n/translate'
import { teamSSOFor } from '@/lib/react-query/hooks'

/** Placeholders the developer swaps for their own values. */
const EXAMPLE_EMAIL = 'jane@example.com'
const EXAMPLE_SUCCESS = 'https://example.com/signed-in'
const EXAMPLE_FAILURE = 'https://example.com/sign-in'

/**
 * What the app sends to sign its users in through the team's identity
 * providers. There is no SDK method for it yet, so the two requests are spelled
 * out with this project's endpoint and ID.
 */
export function AppSignInCard({
  projectId,
  teamId,
}: {
  projectId: string
  teamId: string
}) {
  const t = useT()
  const teamSSO = teamSSOFor({ scope: 'project', projectId, teamId })
  const endpoint = teamSSO.client.config.endpoint
  const lookup = new URL(`${endpoint}/account/sessions/sso/providers`)
  lookup.searchParams.set('email', EXAMPLE_EMAIL)
  const lookupRequest = [
    `curl '${lookup.toString()}' \\`,
    `  -H 'X-Appwrite-Project: ${projectId}'`,
  ].join('\n')
  const signInURL = teamSSO.signInURL(
    'PROVIDER_ID',
    EXAMPLE_SUCCESS,
    EXAMPLE_FAILURE,
  )

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Add sign-in to your app')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'There is no SDK method for it yet: your app sends these two requests.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="space-y-4 px-6 py-4">
        <div className="space-y-2">
          <CodeBlock
            code={lookupRequest}
            language="bash"
            label={t('1. Find the identity providers for an email')}
            wrapLines
          />
          <p className="text-[12px] text-muted-foreground">
            {t(
              'It answers with the identity providers that can sign that email in, each with its $id and name.',
            )}
          </p>
        </div>
        <div className="space-y-2">
          <CodeBlock
            code={signInURL}
            language="plaintext"
            label={t('2. Send the browser to the chosen one')}
            wrapLines
          />
          <ul className="list-disc space-y-1 ps-4 text-[12px] text-muted-foreground">
            <li>
              {t(
                'Both URLs must be on the hostname of a web platform added to this project.',
              )}
            </li>
            <li>
              {t(
                "On success, the browser returns to the success URL with the user's session cookie for this project set.",
              )}
            </li>
            <li>
              {t(
                'On failure, it returns to the failure URL with an error query parameter that holds JSON with message, type and code.',
              )}
            </li>
          </ul>
        </div>
      </div>
    </div>
  )
}
