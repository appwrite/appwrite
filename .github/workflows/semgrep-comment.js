const fs = require('fs');

const marker = '<!-- semgrep-rules-comment -->';
// GitHub issue comments cap at 65536. Leave headroom for the footer and marker.
const COMMENT_LIMIT = 60000;

module.exports = async ({ github, context, core }) => {
    const findings = readFindings('semgrep.json', core);
    const body = buildComment(findings);

    const pullRequest = context.payload.pull_request;
    if (!pullRequest || pullRequest.head.repo.full_name !== `${context.repo.owner}/${context.repo.repo}`) {
        return;
    }

    try {
        await upsertComment(github, context, pullRequest.number, body);
    } catch (error) {
        core.warning(`Could not post Semgrep comment: ${error.message}`);
    }
};

function readFindings(path, core) {
    if (!fs.existsSync(path)) {
        core.warning(`Semgrep JSON not found at ${path}`);
        return [];
    }

    const data = JSON.parse(fs.readFileSync(path, 'utf8'));
    return (data.results || []).map((result) => {
        const severity = String(result.extra?.severity || '').toUpperCase();
        const id = String(result.check_id || '').replace(/^semgrep\./, '');
        const message = String(result.extra?.message || '').replace(/\s+/g, ' ').trim();
        return {
            severity,
            id,
            path: result.path,
            line: result.start?.line,
            message,
        };
    }).sort((a, b) => {
        if (a.severity !== b.severity) {
            return a.severity === 'ERROR' ? -1 : 1;
        }
        return `${a.path}:${a.line}`.localeCompare(`${b.path}:${b.line}`);
    });
}

function buildComment(findings) {
    const errors = findings.filter((item) => item.severity === 'ERROR');
    const warnings = findings.filter((item) => item.severity === 'WARNING');

    if (findings.length === 0) {
        return [
            marker,
            '## Custom Semgrep rules',
            '',
            'No WARNING or ERROR findings from custom Semgrep rules.',
            '',
        ].join('\n');
    }

    const shownErrors = errors.slice();
    const shownWarnings = warnings.slice();
    let omitted = 0;

    const render = () => assembleComment(shownErrors, shownWarnings, errors.length, warnings.length, omitted);

    while (shownErrors.length + shownWarnings.length > 0 && render().length > COMMENT_LIMIT) {
        if (shownWarnings.length > 0) {
            shownWarnings.pop();
        } else {
            shownErrors.pop();
        }
        omitted = (errors.length - shownErrors.length) + (warnings.length - shownWarnings.length);
    }

    return render();
}

function assembleComment(errors, warnings, errorTotal, warningTotal, omitted) {
    const lines = [
        marker,
        '## Custom Semgrep rules',
        '',
    ];

    if (errorTotal > 0) {
        lines.push(`**ERROR** (${errorTotal}) — this check fails until these are resolved.`, '');
        lines.push(...listFindings(errors));
        lines.push('');
    }

    if (warningTotal > 0) {
        lines.push(`**WARNING** (${warningTotal}) — review signal only; does not fail the job.`, '');
        lines.push(...listFindings(warnings));
        lines.push('');
    }

    if (omitted > 0) {
        lines.push(`_${omitted} more finding${omitted === 1 ? '' : 's'} omitted to stay under the GitHub comment size limit._`);
        lines.push('');
    }

    lines.push('_Posted by `Checks / Rules`. Re-runs update this comment in place._');
    lines.push('');
    return lines.join('\n');
}

function listFindings(rows) {
    const lines = [];
    for (const row of rows) {
        const location = row.line ? `${row.path}:${row.line}` : row.path;
        lines.push(`- \`${location}\` — \`${row.id}\``);
        if (row.message) {
            lines.push(`  ${row.message}`);
        }
        lines.push('');
    }
    if (lines[lines.length - 1] === '') {
        lines.pop();
    }
    return lines;
}

async function upsertComment(github, context, issueNumber, body) {
    const comments = await github.paginate(github.rest.issues.listComments, {
        owner: context.repo.owner,
        repo: context.repo.repo,
        issue_number: issueNumber,
        per_page: 100,
    });

    const existing = comments.find((comment) => comment.body?.includes(marker));

    if (existing) {
        await github.rest.issues.updateComment({
            owner: context.repo.owner,
            repo: context.repo.repo,
            comment_id: existing.id,
            body,
        });
        return;
    }

    await github.rest.issues.createComment({
        owner: context.repo.owner,
        repo: context.repo.repo,
        issue_number: issueNumber,
        body,
    });
}

module.exports.readFindings = readFindings;
module.exports.buildComment = buildComment;
