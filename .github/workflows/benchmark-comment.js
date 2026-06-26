const fs = require('fs');

const marker = '<!-- appwrite-benchmark-results -->';
const serviceLabels = ['Account', 'TablesDB', 'Storage', 'Functions'];

module.exports = async ({ github, context, core }) => {
    const body = buildComment(core);
    fs.writeFileSync('benchmark-comment.txt', body);

    const pullRequest = context.payload.pull_request;
    if (!pullRequest || pullRequest.head.repo.full_name !== `${context.repo.owner}/${context.repo.repo}`) {
        return;
    }

    const comments = await github.paginate(github.rest.issues.listComments, {
        owner: context.repo.owner,
        repo: context.repo.repo,
        issue_number: pullRequest.number,
        per_page: 100,
    });

    const existing = comments.find((comment) => {
        return comment.user?.type === 'Bot' && comment.body?.includes(marker);
    }) || comments.find((comment) => {
        return comment.user?.type === 'Bot' && comment.body?.includes('Benchmark results');
    });

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
        issue_number: pullRequest.number,
        body,
    });
};

function buildComment(core) {
    const before = readSummary('benchmark-before-summary.json', core);
    const after = readSummary('benchmark-after-summary.json', core);
    const beforeSamples = readSamples('benchmark-before-samples.json', core);
    const afterSamples = readSamples('benchmark-after-samples.json', core);
    const baseRef = markdownText(process.env.BENCHMARK_BASE_REF || 'base');
    const headRef = markdownText(process.env.BENCHMARK_HEAD_REF || 'head');
    const rows = benchmarkRows(before, after, beforeSamples, afterSamples);
    const total = rows[0];
    const topWaits = topSamples(afterSamples, 'appwrite_api_waiting', 5);
    const lines = [
        marker,
        '## :sparkles: Benchmark results',
        '',
        `Comparing \`${baseRef}\` (before) → \`${headRef}\` (after).`,
        '',
    ];

    if (before === null) {
        lines.push('> :warning: Before benchmark did not complete — change column unavailable.', '');
    }
    if (after === null) {
        lines.push('> :warning: Current branch benchmark did not complete — showing available metrics only.', '');
    }

    lines.push(
        ...headlineTable(total),
        '',
        '<details>',
        '<summary><strong>Per-scenario breakdown & investigation details</strong></summary>',
        '',
        '<br>',
        '',
        '_Metrics below reflect the current branch (after). Δ P95 compares against the base._',
        '',
        detailTable(rows),
        '',
        '**Top API waits (after)**',
        '',
        '| API request | Max wait (ms) |',
        '| --- | ---: |',
        ...topWaitRows(topWaits),
        '',
        '</details>',
    );

    return `${lines.join('\n')}\n`;
}

function headlineTable(total) {
    const { before, after } = total;
    return [
        '| Metric | Before | After | Change |',
        '| --- | ---: | ---: | :--- |',
        `| :rocket: Requests/sec | ${formatRate(before?.rps)} | ${formatRate(after?.rps)} | ${changeCell(before?.rps, after?.rps, false)} |`,
        `| :stopwatch: Latency P50 | ${formatMsUnit(before?.p50)} | ${formatMsUnit(after?.p50)} | ${changeCell(before?.p50, after?.p50, true)} |`,
        `| :stopwatch: Latency P95 | ${formatMsUnit(before?.p95)} | ${formatMsUnit(after?.p95)} | ${changeCell(before?.p95, after?.p95, true)} |`,
    ];
}

function changeCell(before, after, lowerIsBetter) {
    if (before === null || before === undefined || after === null || after === undefined || Number.isNaN(before) || Number.isNaN(after) || before === 0) {
        return 'n/a';
    }

    const percent = Number((((after - before) / before) * 100).toFixed(1));
    const label = code(`${percent > 0 ? '+' : ''}${trimNumber(percent)}%`);

    if (Math.abs(percent) <= 5) {
        return `:white_circle: ${label}`;
    }

    const improved = lowerIsBetter ? percent < 0 : percent > 0;
    return `${improved ? ':green_circle:' : ':red_circle:'} ${label}`;
}

function detailTable(rows) {
    return [
        '| Scenario | P50 (ms) | P95 (ms) | Requests | RPS | Δ P95 (ms) |',
        '| --- | ---: | ---: | ---: | ---: | ---: |',
        ...rows.map(detailRow),
    ].join('\n');
}

function detailRow(row) {
    const values = row.after;
    return `| ${row.label} | ${formatMs(values?.p50)} | ${formatMs(values?.p95)} | ${formatCount(values?.iterations)} | ${formatRate(values?.rps)} | ${formatDelta(row.before?.p95, row.after?.p95)} |`;
}

function readSummary(path, core) {
    if (!fs.existsSync(path)) {
        return null;
    }

    try {
        return JSON.parse(fs.readFileSync(path, 'utf8'));
    } catch (error) {
        core?.warning(`Invalid benchmark summary ${path}: ${error.message}`);
        return null;
    }
}

function readSamples(path, core) {
    if (!fs.existsSync(path)) {
        return [];
    }

    const contents = fs.readFileSync(path, 'utf8').trim();
    if (contents === '') {
        return [];
    }

    return contents
        .split('\n')
        .filter(Boolean)
        .flatMap((line) => {
            try {
                return [JSON.parse(line)];
            } catch (error) {
                core?.warning(`Invalid benchmark sample in ${path}: ${error.message}`);
                return [];
            }
        });
}

function benchmarkRows(before, after, beforeSamples, afterSamples) {
    const beforeServices = serviceStats(beforeSamples);
    const afterServices = serviceStats(afterSamples);
    return [
        {
            label: 'API total',
            before: apiSampleStats(beforeSamples) || summaryStats(before, 'appwrite_api_duration'),
            after: apiSampleStats(afterSamples) || summaryStats(after, 'appwrite_api_duration'),
        },
        ...serviceLabels.map((label) => ({
            label,
            before: beforeServices.get(label) || null,
            after: afterServices.get(label) || null,
        })),
    ];
}

function summaryStats(summary, durationMetric, iterationsMetric = null, rpsMetric = null) {
    const values = metricValues(summary, durationMetric);
    if (!values) {
        return null;
    }

    return {
        p50: values.med ?? null,
        p95: values['p(95)'] ?? null,
        iterations: iterationsMetric ? metricValue(summary, iterationsMetric, 'count') : values.count ?? null,
        rps: rpsMetric ? metricValue(summary, rpsMetric, 'rate') : null,
    };
}

function serviceStats(samples) {
    const apiSamples = samples.filter((sample) => {
        return sample.metric === 'appwrite_api_duration' && typeof sample.data?.value === 'number';
    });
    const groups = new Map();

    for (const sample of apiSamples) {
        const service = serviceFromName(sample.data.tags?.name || '');
        if (!service) {
            continue;
        }

        const serviceSamples = groups.get(service) || [];
        serviceSamples.push(sample);
        groups.set(service, serviceSamples);
    }

    return new Map([...groups.entries()].map(([service, serviceSamples]) => {
        const values = serviceSamples.map((sample) => sample.data.value);
        const durationSeconds = sampleWindowSeconds(serviceSamples);
        return [service, {
            p50: percentile(values, 50),
            p95: percentile(values, 95),
            iterations: values.length,
            rps: durationSeconds ? values.length / durationSeconds : null,
        }];
    }));
}

function apiSampleStats(samples) {
    const apiSamples = samples.filter((sample) => {
        return sample.metric === 'appwrite_api_duration' && typeof sample.data?.value === 'number';
    });
    const values = apiSamples.map((sample) => sample.data.value);
    if (values.length === 0) {
        return null;
    }

    const durationSeconds = sampleWindowSeconds(apiSamples);
    return {
        p50: percentile(values, 50),
        p95: percentile(values, 95),
        iterations: values.length,
        rps: durationSeconds ? values.length / durationSeconds : null,
    };
}

function serviceFromName(name) {
    if (name.startsWith('account.')) {
        return 'Account';
    }
    if (name.startsWith('tablesdb.')) {
        return 'TablesDB';
    }
    if (name.startsWith('storage.') || name.startsWith('tokens.')) {
        return 'Storage';
    }
    if (name.startsWith('functions.')) {
        return 'Functions';
    }
    return null;
}

function sampleWindowSeconds(samples) {
    const times = samples
        .map((sample) => Date.parse(sample.data?.time))
        .filter((value) => !Number.isNaN(value));
    if (times.length < 2) {
        return null;
    }

    return Math.max((Math.max(...times) - Math.min(...times)) / 1000, 1);
}

function percentile(values, percentileValue) {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((left, right) => left - right);
    const index = Math.ceil((percentileValue / 100) * sorted.length) - 1;
    return sorted[Math.max(0, Math.min(index, sorted.length - 1))];
}

function metricValues(data, metric) {
    return data?.metrics?.[metric]?.values ?? null;
}

function metricValue(data, metric, stat) {
    return metricValues(data, metric)?.[stat] ?? null;
}

function topSamples(samples, metric, limit) {
    const byName = samples.reduce((result, sample) => {
        if (sample.metric !== metric || typeof sample.data?.value !== 'number') {
            return result;
        }

        const name = sample.data.tags?.name || 'unknown';
        const current = result.get(name);
        if (!current || sample.data.value > current.value) {
            result.set(name, { name, value: sample.data.value });
        }

        return result;
    }, new Map());

    return [...byName.values()]
        .sort((left, right) => right.value - left.value)
        .slice(0, limit);
}

function topWaitRows(samples) {
    if (samples.length === 0) {
        return ['| n/a | n/a |'];
    }

    return samples.map((sample) => {
        return `| ${markdownText(sample.name).replace(/\|/g, '\\|')} | ${formatMs(sample.value)} |`;
    });
}

function markdownText(value) {
    return String(value || '').replace(/[\r\n]/g, ' ').replace(/[&<>"']/g, (char) => {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
}

function formatMs(value) {
    return code(formatNumber(value, 2));
}

function formatMsUnit(value) {
    const formatted = formatNumber(value, 2);
    return formatted === 'n/a' ? formatted : code(`${formatted} ms`);
}

function formatRate(value) {
    return code(formatNumber(value, 2));
}

function formatCount(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return code(Math.round(value).toLocaleString('en-US'));
}

function formatDelta(before, after) {
    if (before === null || before === undefined || after === null || after === undefined || Number.isNaN(before) || Number.isNaN(after)) {
        return 'n/a';
    }

    const difference = Number((after - before).toFixed(2));
    const sign = difference > 0 ? '+' : difference < 0 ? '-' : '';
    return code(`${sign}${formatNumber(Math.abs(difference), 2)}`);
}

function formatNumber(value, decimals) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return trimNumber(Number(value).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }));
}

function code(text) {
    return text === 'n/a' ? text : `\`${text}\``;
}

function trimNumber(value) {
    const text = String(value);
    const trimmed = text.includes('.') ? text.replace(/\.?0+$/, '') : text;
    return trimmed === '' ? '0' : trimmed;
}
