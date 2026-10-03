import { Client, Databases, ID } from 'node-appwrite';
import { config } from './config.js';

/**
 * Build a fresh server-side Appwrite client.
 *
 * We deliberately do not memoize the client globally: the node-appwrite SDK
 * keeps internal state on each client (headers, cookies). Creating per-task
 * clients keeps concurrent operations isolated and lets us layer a JWT or a
 * scoped key per-request when needed.
 */
export function buildClient(): Client {
  return new Client()
    .setEndpoint(config.endpoint)
    .setProject(config.projectId)
    .setKey(config.apiKey);
}

export function buildDatabases(): Databases {
  return new Databases(buildClient());
}

export { ID };
