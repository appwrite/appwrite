#!/usr/bin/env node
// Known findings for the security rules. CI fails on, and comments, only findings not listed here.
//
//   node .semgrep/baseline.js check semgrep.json    # summary; exits 1 on any new ERROR
//   node .semgrep/baseline.js update semgrep.json   # rewrite .semgrep/baseline.json from a full scan

const fs = require('fs');
const nodePath = require('path');

const BASELINE = nodePath.join(__dirname, 'baseline.json');
const SCOPE = ['src/Appwrite', 'app/controllers', 'app/init'];
// Short first lines such as `$this` borrow the following lines so the key names the route.
const TEXT_MIN = 40;
const TEXT_LINES = 3;

function ruleOf(result) {
    return String(result.check_id || '').split('.').pop();
}

function textOf(result, root) {
    let lines;
    try {
        lines = fs.readFileSync(nodePath.resolve(root, result.path), 'utf8').split('\n');
    } catch {
        return String(result.extra?.message || '').replace(/\s+/g, ' ').trim();
    }
    const start = result.start?.line || 1;
    const end = Math.max(start, result.end?.line || start);
    let text = (lines[start - 1] || '').slice(Math.max(0, (result.start?.col || 1) - 1));
    for (let line = start + 1; line <= Math.min(end, start + TEXT_LINES - 1) && text.trim().length < TEXT_MIN; line++) {
        text += ' ' + (lines[line - 1] || '');
    }
    return text.replace(/\s+/g, ' ').trim();
}

function keyOf(entry) {
    return `${entry.rule}\u0000${entry.path}\u0000${entry.text}`;
}

function entriesOf(results, root) {
    return results.map((result) => ({
        rule: ruleOf(result),
        severity: String(result.extra?.severity || '').toUpperCase(),
        path: result.path,
        line: result.start?.line,
        text: textOf(result, root),
    }));
}

function load(path = BASELINE) {
    if (!fs.existsSync(path)) {
        return [];
    }
    return JSON.parse(fs.readFileSync(path, 'utf8')).findings || [];
}

// Matches as a multiset: two identical lines in one file need two baseline entries.
function partition(results, baseline = load(), root = process.cwd()) {
    const remaining = new Map();
    for (const entry of baseline) {
        const key = keyOf(entry);
        remaining.set(key, [...(remaining.get(key) || []), entry]);
    }

    const fresh = [];
    const known = [];
    const entries = entriesOf(results, root);
    results.forEach((result, index) => {
        const bucket = remaining.get(keyOf(entries[index]));
        if (bucket && bucket.length > 0) {
            bucket.shift();
            known.push(result);
        } else {
            fresh.push(result);
        }
    });

    const stale = [...remaining.values()].flat();
    return { fresh, known, stale };
}

function readResults(path) {
    return JSON.parse(fs.readFileSync(path, 'utf8')).results || [];
}

function update(path, root = process.cwd()) {
    const findings = entriesOf(readResults(path), root).sort((a, b) =>
        a.path.localeCompare(b.path) || (a.line || 0) - (b.line || 0) || a.rule.localeCompare(b.rule));
    const data = {
        version: 1,
        scope: SCOPE,
        findings,
    };
    fs.writeFileSync(BASELINE, JSON.stringify(data, null, 4) + '\n');
    console.log(`Wrote ${findings.length} findings to ${nodePath.relative(root, BASELINE)}`);
}

function count(results, severity) {
    return results.filter((result) => String(result.extra?.severity || '').toUpperCase() === severity).length;
}

function check(path, root = process.cwd()) {
    const results = readResults(path);
    const { fresh, known, stale } = partition(results, load(), root);
    const errors = count(fresh, 'ERROR');
    const warnings = count(fresh, 'WARNING');

    console.log(`Security rules: ${results.length} findings, ${known.length} in baseline, ${errors} new ERROR, ${warnings} new WARNING.`);
    for (const result of fresh) {
        const severity = String(result.extra?.severity || '').toUpperCase();
        console.log(`  ${severity} ${ruleOf(result)} ${result.path}:${result.start?.line}`);
    }
    if (stale.length > 0) {
        console.log(`${stale.length} baseline entries no longer match; regenerate with \`node .semgrep/baseline.js update semgrep.json\`.`);
        for (const entry of stale) {
            console.log(`  ${entry.rule} ${entry.path}:${entry.line}`);
        }
    }
    return errors > 0 ? 1 : 0;
}

module.exports = { load, partition, ruleOf, BASELINE };

if (require.main === module) {
    const [command, path = 'semgrep.json'] = process.argv.slice(2);
    if (command === 'check') {
        process.exitCode = check(path);
    } else if (command === 'update') {
        update(path);
    } else {
        console.error('Usage: node .semgrep/baseline.js <check|update> [semgrep.json]');
        process.exitCode = 2;
    }
}
