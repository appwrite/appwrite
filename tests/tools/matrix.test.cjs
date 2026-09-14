const WORKFLOW = '.github/workflows/ci.yml';
const CHECKER = 'tests/tools/suites.php';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const test = require('node:test');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const workflow = JSON.parse(execFileSync('php', ['-r',
  'require "vendor/autoload.php"; echo json_encode(Spyc::YAMLLoad($argv[1]), JSON_THROW_ON_ERROR);', WORKFLOW,
], { encoding: 'utf8' }));
const suites = config => JSON.parse(execFileSync('php', [CHECKER, config], { encoding: 'utf8' }));

const script = workflow.jobs.matrix.steps.find(step => step.id === 'generate').with.script;
async function matrix(names, context = { eventName: 'push', runId: 1, payload: {} }, github = {}) {
  const output = {};
  await new AsyncFunction('core', 'process', 'context', 'github', script)(
    { setOutput: (key, value) => { output[key] = JSON.parse(value); } },
    { env: { PHPUNIT_SUITES: JSON.stringify(names) } }, context, github,
  );
  return output;
}
test('a new configured suite gets a runnable job without an allowlist change', async () => {
  const result = await matrix([...suites('phpunit.xml'), 'e2e-added']);
  assert(result.services.some(service => service.suite === 'e2e-added'));
  assert(!result.services.some(service => service.suite === 'unit'));
});
test('unknown topology and stale overrides fail instead of dropping tests', async () => {
  const names = suites('phpunit.xml');
  await assert.rejects(() => matrix([...names, 'unassigned']), /Unassigned/);
  await assert.rejects(() => matrix(names.filter(name => name !== 'e2e-databases')), /Stale/);
});
test('a dependency reference change expands the matrix even at the same version', async () => {
  const github = { rest: { repos: { getContent: async ({ ref }) => ({ data: { content: Buffer.from(JSON.stringify({
    packages: [{ name: 'utopia-php/database', version: 'dev-main', source: { reference: ref } }],
  })).toString('base64') } }) } } };
  const result = await matrix(suites('phpunit.xml'), { eventName: 'pull_request', runId: 1, payload: {
    pull_request: { base: { sha: 'before' }, head: { sha: 'after' } },
  }, repo: { owner: 'fixture', repo: 'fixture' } }, github);
  assert.deepEqual(result.modes, ['dedicated', 'shared']);
  assert.equal(result.databases.length, 3);
});

test('ordinary exclusions have a matching grouped execution job', () => {
  const commands = workflow.jobs.e2e_service.steps.map(step => step.run ?? '').join('\n');
  const excluded = [...commands.matchAll(/--exclude-group\s+([\w-]+)/g)].map(match => match[1]);
  const destinations = workflow.jobs.e2e_grouped.strategy.matrix.config.map(row => row.group);
  for (const group of excluded) assert(destinations.includes(group), `No execution job for excluded group ${group}`);
});
