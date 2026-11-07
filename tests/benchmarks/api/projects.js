// Projects API benchmark: a scenario for every /v1/projects endpoint, the
// console's routes over the projects of an organization, called as its owner
// (the console session) on the benchmark's own project. See README.md.

import { define, SEED, pick, unique } from './lib.js';

const REGION = __ENV._APP_REGION || 'default';

// The dev stack's mail catcher (and its login, as in .env): custom templates need custom SMTP.
const SMTP = { host: 'maildev', port: 1025, username: 'user', password: 'password', senderEmail: 'bench@example.com', senderName: 'Bench', enabled: true };

function draft(api) {
    return api.control('POST', '/messaging/messages/email', { messageId: 'unique()', subject: 'Bench', content: 'Bench message', draft: true }, [201]).json('$id');
}

const bench = define({
    service: 'projects',
    // For the state the scenarios act on, set up in the project with its key.
    scopes: ['project.read', 'project.write', 'templates.write', 'messages.read', 'messages.write'],
    seed(api, { admin, projectId, run }) {
        const teamId = api.control('GET', '/project', null, [200]).json('teamId');
        api.control('PATCH', '/project/smtp', SMTP, [200]);
        // Schedules on a draft message: inactive, so nothing runs them.
        const message = draft(api);
        const schedules = Array.from({ length: Math.min(SEED, 20) }, () => api.prep('POST', `/projects/${projectId}/schedules`, {
            resourceType: 'message', resourceId: message, schedule: '0 0 1 1 *', active: false,
        }, [201], admin).json('$id'));
        return { admin, projectId, run, teamId, schedules };
    },
    scenarios: [
        {
            name: 'projects.list', route: 'GET /v1/projects', weight: 10,
            run: (api, d) => api.call('GET', '/projects?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}'), null, [200], d.admin),
        },
        { name: 'projects.search', route: 'GET /v1/projects', weight: 3, note: 'full-text search', run: (api, d) => api.call('GET', '/projects?search=bench', null, [200], d.admin) },
        {
            name: 'projects.create', route: 'POST /v1/projects', weight: 0.5,
            note: 'creates the project\'s platform records; deleting it afterwards removes them in the background',
            run(api, d) {
                const res = api.call('POST', '/projects', { projectId: unique('new'), name: 'Bench throwaway', teamId: d.teamId, region: REGION }, [201], d.admin);
                if (res.status === 201) {
                    api.control('DELETE', '/project', null, [204], { ...d.admin, 'X-Appwrite-Project': res.json('$id'), 'X-Appwrite-Mode': 'admin' });
                }
            },
        },
        {
            name: 'projects.update', route: 'PATCH /v1/projects/:projectId', weight: 2,
            run: (api, d) => api.call('PATCH', `/projects/${d.projectId}`, { name: d.run, description: 'API benchmark' }, [200], d.admin),
        },
        {
            name: 'projects.mockNumbers.update', route: 'PATCH /v1/projects/:projectId/auth/mock-numbers',
            run: (api, d) => api.call('PATCH', `/projects/${d.projectId}/auth/mock-numbers`, { numbers: [{ phone: '+12025550100', otp: '123456' }] }, [200], d.admin),
        },
        {
            name: 'projects.oauth2.update', route: 'PATCH /v1/projects/:projectId/oauth2', note: 'leaves the provider disabled',
            run: (api, d) => api.call('PATCH', `/projects/${d.projectId}/oauth2`, { provider: pick(['github', 'google', 'discord']), appId: 'bench-app-id', enabled: false }, [200], d.admin),
        },
        { name: 'projects.schedules.list', route: 'GET /v1/projects/:projectId/schedules', weight: 4, run: (api, d) => api.call('GET', `/projects/${d.projectId}/schedules`, null, [200], d.admin) },
        {
            name: 'projects.schedules.get', route: 'GET /v1/projects/:projectId/schedules/:scheduleId', weight: 4,
            run: (api, d) => api.call('GET', `/projects/${d.projectId}/schedules/${pick(d.schedules)}`, null, [200], d.admin),
        },
        {
            name: 'projects.schedules.create', route: 'POST /v1/projects/:projectId/schedules',
            note: 'an inactive schedule on a draft message, deleted after: maintenance reaps the schedule (there is no delete route)',
            run(api, d) {
                const message = draft(api);
                api.call('POST', `/projects/${d.projectId}/schedules`, { resourceType: 'message', resourceId: message, schedule: '0 0 1 1 *', active: false }, [201], d.admin);
                api.control('DELETE', `/messaging/messages/${message}`, null, [204]);
            },
        },
        { name: 'projects.stages.list', route: 'GET /v1/projects/:projectId/stages', weight: 4, run: (api, d) => api.call('GET', `/projects/${d.projectId}/stages`, null, [200], d.admin) },
        {
            name: 'projects.stages.update', route: 'PATCH /v1/projects/:projectId/stages/:stageId',
            run: (api, d) => api.call('PATCH', `/projects/${d.projectId}/stages/${pick(['cli.install', 'mcp.install'])}`, { skip: true }, [200], d.admin),
        },
        {
            name: 'projects.team.update', route: 'PATCH /v1/projects/:projectId/team', note: 'to the team that already owns it (self-hosted has one organization)',
            run: (api, d) => api.call('PATCH', `/projects/${d.projectId}/team`, { teamId: d.teamId }, [200], d.admin),
        },
        {
            name: 'projects.templates.email.delete', route: 'DELETE /v1/projects/:projectId/templates/email',
            run(api, d) {
                const type = pick(['verification', 'recovery', 'invitation']);
                api.control('PATCH', '/project/templates/email', { templateId: type, locale: 'en', subject: 'Bench subject', message: '<p>Bench message</p>' }, [200]);
                api.call('DELETE', `/projects/${d.projectId}/templates/email`, { type, locale: 'en' }, [204], d.admin);
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
