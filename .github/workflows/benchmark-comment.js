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
    const topWaits = topSamples(afterSamples, 'appwrite_api_waiting', 3);
    const lines = [
        marker,
        '## :sparkles: Benchmark results',
        '',
        `Comparing ${baseRef} (before) to ${headRef} (after).`,
        '',
        headline(rows[0]),
        '',
    ];

    if (before === null) {
        lines.push('> Before benchmark did not complete; showing current branch metrics only.', '');
    }
    if (after === null) {
        lines.push('> Current branch benchmark did not complete; showing available metrics only.', '');
    }

    lines.push(
        '<details>',
        '<summary><strong>Per-scenario breakdown</strong></summary>',
        '',
        '<br>',
        '',
        metricTable(rows.slice(1)),
        '',
        '</details>',
        '',
        '<details>',
        '<summary><strong>Top API waits</strong></summary>',
        '',
        '<br>',
        '',
        '| API request | Max wait (ms) |',
        '| --- | ---: |',
        ...topWaitRows(topWaits),
        '',
        '</details>',
    );

    return `${lines.join('\n')}\n`;
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

function summaryStats(summary, durationMetric) {
    const values = metricValues(summary, durationMetric);
    if (!values) {
        return null;
    }

    return {
        p50: values.med ?? null,
        p95: values['p(95)'] ?? null,
        rps: null,
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

function headline(apiRow) {
    return [
        `**API total** — P50 ${cell(apiRow, 'p50', formatMs, false, ' ms')}`,
        `P95 ${cell(apiRow, 'p95', formatMs, false, ' ms')}`,
        cell(apiRow, 'rps', formatRate, true, ' req/s'),
    ].join(' · ');
}

function metricTable(rows) {
    return [
        '| Scenario | P50 (ms) | P95 (ms) | RPS |',
        '| --- | ---: | ---: | ---: |',
        ...rows.map(metricRow),
    ].join('\n');
}

function metricRow(row) {
    return `| ${row.label} | ${cell(row, 'p50', formatMs, false)} | ${cell(row, 'p95', formatMs, false)} | ${cell(row, 'rps', formatRate, true)} |`;
}

function cell(row, key, format, higherIsBetter, unit = '') {
    const before = row.before?.[key];
    const after = row.after?.[key];
    if (after === null || after === undefined || Number.isNaN(after)) {
        return 'n/a';
    }

    const base = `${format(after)}${unit}`;
    if (before === null || before === undefined || Number.isNaN(before) || before === 0) {
        return base;
    }

    const change = ((after - before) / before) * 100;
    const sign = change >= 0 ? '+' : '−';
    const delta = `(${sign}${trimNumber(Math.abs(change).toFixed(1))}%)`;
    return `${base} ${delta}${indicator(change, higherIsBetter)}`;
}

function indicator(change, higherIsBetter) {
    if (Math.abs(change) < 2) {
        return '';
    }

    const improved = higherIsBetter ? change > 0 : change < 0;
    return improved ? ' 🟢' : ' 🔴';
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
    return formatNumber(value, 2);
}

function formatRate(value) {
    return formatNumber(value, 2);
}

function formatNumber(value, decimals) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return trimNumber(Number(value).toFixed(decimals));
}

function trimNumber(value) {
    const text = String(value);
    const trimmed = text.includes('.') ? text.replace(/\.?0+$/, '') : text;
    return trimmed === '' ? '0' : trimmed;
}
