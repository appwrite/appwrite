/**
 * Domain transfers between organizations (`/v1/domains/transfers`), which the
 * pinned Console SDK does not carry yet: Cloud added them and the SDK is
 * generated from Cloud's spec on its next release. Delete this for
 * `domains.createTransfer()`, `listTransfers()`, `getTransfer()`,
 * `deleteTransfer()` and `updateTransferStatus()` once the pin has them.
 *
 * Domains routes check the caller's role in each organization, so these go
 * through the console client: a session that sends `X-Appwrite-Organization`
 * is held to that organization's role scopes, which leave out the domains API.
 */

import type { Client, Models } from '@appwrite.io/console'

function apiHeaders(client: Client): Record<string, string> {
  return {
    'X-Appwrite-Project': client.config.project,
    'content-type': 'application/json',
    accept: 'application/json',
  }
}

function transfersUrl(client: Client, path = ''): URL {
  return new URL(`${client.config.endpoint}/domains/transfers${path}`)
}

export async function createDomainTransfer(
  client: Client,
  params: { domainId: string; recipientId?: string; expire?: string },
): Promise<Models.Transfer> {
  return (await client.call(
    'post',
    transfersUrl(client),
    apiHeaders(client),
    params,
  )) as Models.Transfer
}

/** The domain's pending transfers, with their secrets; only those who can update the domain may list them. */
export async function listDomainTransfers(
  client: Client,
  params: { domainId: string; queries?: string[] },
): Promise<Models.TransferList> {
  return (await client.call(
    'get',
    transfersUrl(client),
    apiHeaders(client),
    params,
  )) as Models.TransferList
}

/** Without a secret only the sender finds the transfer; anyone else gets it with the secret hidden. */
export async function getDomainTransfer(
  client: Client,
  params: { transferId: string; secret?: string },
): Promise<Models.Transfer> {
  const { transferId, ...query } = params
  return (await client.call(
    'get',
    transfersUrl(client, `/${encodeURIComponent(transferId)}`),
    apiHeaders(client),
    query,
  )) as Models.Transfer
}

export async function deleteDomainTransfer(
  client: Client,
  params: { transferId: string },
): Promise<void> {
  await client.call(
    'delete',
    transfersUrl(client, `/${encodeURIComponent(params.transferId)}`),
    apiHeaders(client),
  )
}

/** Claim the domain into `teamId`, which the caller must own. */
export async function updateDomainTransferStatus(
  client: Client,
  params: { transferId: string; secret: string; teamId: string },
): Promise<Models.Domain> {
  const { transferId, ...payload } = params
  return (await client.call(
    'patch',
    transfersUrl(client, `/${encodeURIComponent(transferId)}/status`),
    apiHeaders(client),
    payload,
  )) as Models.Domain
}
