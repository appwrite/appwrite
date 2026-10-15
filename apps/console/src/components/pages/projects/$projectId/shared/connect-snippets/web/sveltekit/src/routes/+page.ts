import type { PageLoad } from './$types'
import { Account } from 'appwrite'
import { client } from '$lib/appwrite'

export const ssr = false

export const load: PageLoad = async () => {
  try {
    const account = new Account(client)
    const user = await account.get()
    return { user }
  } catch {
    return { user: null }
  }
}
