/**
 * Client-side Appwrite SDK Configuration
 * 
 * This file provides both Console SDK and Project SDK instances for client-side use only.
 * All Appwrite operations are handled client-side.
 */

import {
    Account,
    Assistant,
    Avatars,
    Backups,
    Client,
    Console,
    Functions,
    Health,
    Locale,
    Messaging,
    Migrations,
    Project,
    Project as ProjectApi,
    Projects,
    Proxy,
    Storage,
    Teams,
    Users,
    Vcs,
    Sites,
    Tokens,
    TablesDB,
    Domains,
    Realtime,
    Organizations
} from '@appwrite.io/console';

// Get endpoint from environment variable
function getApiEndpoint(_region?: string): string {
    const baseEndpoint = import.meta.env.VITE_APPWRITE_ENDPOINT || 
                        (typeof window !== 'undefined' ? `${window.location.protocol}//${window.location.host}/v1` : '');
    
    if (!baseEndpoint) {
        throw new Error('VITE_APPWRITE_ENDPOINT is not configured');
    }

    // For multi-region support, you can add region subdomain logic here
    // Example: return `${protocol}//${regionSubdomain}${hostname}/v1`;
    return baseEndpoint;
}

// Create Console SDK instance
function createConsoleSdk(client: Client) {
    return {
        client,
        account: new Account(client),
        avatars: new Avatars(client),
        functions: new Functions(client),
        health: new Health(client),
        locale: new Locale(client),
        projects: new Projects(client),
        teams: new Teams(client),
        users: new Users(client),
        migrations: new Migrations(client),
        console: new Console(client),
        assistant: new Assistant(client),
        sites: new Sites(client),
        domains: new Domains(client),
        storage: new Storage(client),
        realtime: new Realtime(client),
        organizations: new Organizations(client)
    };
}

// Initialize clients
const endpoint = getApiEndpoint();

const clientConsole = new Client();
const clientProject = new Client();
const clientRealtime = new Client();

// Configure Console client
clientConsole.setEndpoint(endpoint).setProject('console');

// Configure Project client (will be set per-project)
clientProject.setEndpoint(endpoint).setMode('admin');

// Configure Realtime client
clientRealtime.setEndpoint(endpoint).setProject('console');

// Create Project SDK instance
const sdkForProject = {
    client: clientProject,
    account: new Account(clientProject),
    avatars: new Avatars(clientProject),
    backups: new Backups(clientProject),
    functions: new Functions(clientProject),
    health: new Health(clientProject),
    locale: new Locale(clientProject),
    messaging: new Messaging(clientProject),
    project: new Project(clientProject),
    projectApi: new ProjectApi(clientProject),
    storage: new Storage(clientProject),
    tokens: new Tokens(clientProject),
    teams: new Teams(clientProject),
    users: new Users(clientProject),
    vcs: new Vcs(clientProject),
    proxy: new Proxy(clientProject),
    migrations: new Migrations(clientProject),
    sites: new Sites(clientProject),
    tablesDB: new TablesDB(clientProject),
    console: new Console(clientProject), // for suggestions API
    realtime: new Realtime(clientProject)
};

// Export SDK instances
export const sdk = {
    // Console SDK - for managing console-level resources
    forConsole: createConsoleSdk(clientConsole),

    // Console SDK for specific region - for managing console-level resources in a specific region
    forConsoleIn(region: string) {
        const regionEndpoint = getApiEndpoint(region);
        const regionClient = new Client();
        regionClient.setEndpoint(regionEndpoint).setProject('console');
        return createConsoleSdk(regionClient);
    },

    // Project SDK - for managing project-specific resources
    // Call this method with the project ID to get a configured SDK instance
    forProject(projectId: string, region?: string) {
        const projectEndpoint = region ? getApiEndpoint(region) : endpoint;
        
        if (projectEndpoint !== clientProject.config.endpoint) {
            clientProject.setEndpoint(projectEndpoint);
        }
        if (projectId !== clientProject.config.project) {
            clientProject.setProject(projectId);
        }

        return sdkForProject;
    }
};

// Export types for TypeScript
export type ConsoleSdk = ReturnType<typeof createConsoleSdk>;
export type ProjectSdk = typeof sdkForProject;

