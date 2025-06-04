import { readFileSync } from 'fs';
import { CiEnvironments } from './CiEnvironments.js';
import evaluateVariableExpression from './evaluateVariableExpression.js';
export default function detectCiEnvironment(env) {
    for (const ciEnvironment of CiEnvironments) {
        const detected = detect(ciEnvironment, env);
        if (detected) {
            return detected;
        }
    }
}
export function removeUserInfoFromUrl(value) {
    if (!value)
        return value;
    try {
        const url = new URL(value);
        url.password = '';
        url.username = '';
        return url.toString();
    }
    catch (ignore) {
        return value;
    }
}
function detectGit(ciEnvironment, env) {
    var _a, _b, _c;
    const revision = detectRevision(ciEnvironment, env);
    if (!revision) {
        return undefined;
    }
    const remote = evaluateVariableExpression((_a = ciEnvironment.git) === null || _a === void 0 ? void 0 : _a.remote, env);
    if (!remote) {
        return undefined;
    }
    const tag = evaluateVariableExpression((_b = ciEnvironment.git) === null || _b === void 0 ? void 0 : _b.tag, env);
    const branch = evaluateVariableExpression((_c = ciEnvironment.git) === null || _c === void 0 ? void 0 : _c.branch, env);
    return Object.assign(Object.assign({ revision, remote: removeUserInfoFromUrl(remote) }, (tag && { tag })), (branch && { branch }));
}
function detectRevision(ciEnvironment, env) {
    var _a, _b, _c;
    if (env.GITHUB_EVENT_NAME === 'pull_request') {
        if (!env.GITHUB_EVENT_PATH)
            throw new Error('GITHUB_EVENT_PATH not set');
        const json = readFileSync(env.GITHUB_EVENT_PATH, 'utf-8');
        const event = JSON.parse(json);
        const revision = (_b = (_a = event.pull_request) === null || _a === void 0 ? void 0 : _a.head) === null || _b === void 0 ? void 0 : _b.sha;
        if (!revision) {
            throw new Error(`Could not find .pull_request.head.sha in ${env.GITHUB_EVENT_PATH}:\n${JSON.stringify(event, null, 2)}`);
        }
        return revision;
    }
    return evaluateVariableExpression((_c = ciEnvironment.git) === null || _c === void 0 ? void 0 : _c.revision, env);
}
function detect(ciEnvironment, env) {
    const url = evaluateVariableExpression(ciEnvironment.url, env);
    if (url === undefined) {
        // The url is what consumers will use as the primary key for a build
        // If this cannot be determined, we return nothing.
        return undefined;
    }
    const buildNumber = evaluateVariableExpression(ciEnvironment.buildNumber, env);
    const git = detectGit(ciEnvironment, env);
    return Object.assign({ name: ciEnvironment.name, url,
        buildNumber }, (git && { git }));
}
//# sourceMappingURL=detectCiEnvironment.js.map