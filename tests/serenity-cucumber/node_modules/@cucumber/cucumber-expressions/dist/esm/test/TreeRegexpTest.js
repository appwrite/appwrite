import assert from 'assert';
import TreeRegexp from '../src/TreeRegexp.js';
describe('TreeRegexp', () => {
    it('exposes group source', () => {
        const tr = new TreeRegexp(/(a(?:b)?)(c)/);
        assert.deepStrictEqual(tr.groupBuilder.children.map((gb) => gb.source), ['a(?:b)?', 'c']);
    });
    it('builds tree', () => {
        const tr = new TreeRegexp(/(a(?:b)?)(c)/);
        const group = tr.match('ac');
        assert.strictEqual(group.value, 'ac');
        assert.strictEqual(group.children[0].value, 'a');
        assert.deepStrictEqual(group.children[0].children, []);
        assert.strictEqual(group.children[1].value, 'c');
    });
    it('ignores `?:` as a non-capturing group', () => {
        const tr = new TreeRegexp(/a(?:b)(c)/);
        const group = tr.match('abc');
        assert.strictEqual(group.value, 'abc');
        assert.strictEqual(group.children.length, 1);
    });
    it('ignores `?!` as a non-capturing group', () => {
        const tr = new TreeRegexp(/a(?!b)(.+)/);
        const group = tr.match('aBc');
        assert.strictEqual(group.value, 'aBc');
        assert.strictEqual(group.children.length, 1);
    });
    it('ignores `?=` as a non-capturing group', () => {
        const tr = new TreeRegexp(/a(?=[b])(.+)/);
        const group = tr.match('abc');
        assert.strictEqual(group.value, 'abc');
        assert.strictEqual(group.children.length, 1);
        assert.strictEqual(group.children[0].value, 'bc');
    });
    it('ignores `?<=` as a non-capturing group', () => {
        const tr = new TreeRegexp(/a(.+)(?<=c)$/);
        const group = tr.match('abc');
        assert.strictEqual(group.value, 'abc');
        assert.strictEqual(group.children.length, 1);
        assert.strictEqual(group.children[0].value, 'bc');
    });
    it('ignores `?<!` as a non-capturing group', () => {
        const tr = new TreeRegexp(/a(.+?)(?<!b)$/);
        const group = tr.match('abc');
        assert.strictEqual(group.value, 'abc');
        assert.strictEqual(group.children.length, 1);
        assert.strictEqual(group.children[0].value, 'bc');
    });
    it('matches named capturing group', () => {
        const tr = new TreeRegexp(/a(?<name>b)c/);
        const group = tr.match('abc');
        assert.strictEqual(group.value, 'abc');
        assert.strictEqual(group.children.length, 1);
        assert.strictEqual(group.children[0].value, 'b');
    });
    it('matches optional group', () => {
        const tr = new TreeRegexp(/^Something( with an optional argument)?/);
        const group = tr.match('Something');
        assert.strictEqual(group.children[0].value, undefined);
    });
    it('matches nested groups', () => {
        const tr = new TreeRegexp(/^A (\d+) thick line from ((\d+),\s*(\d+),\s*(\d+)) to ((\d+),\s*(\d+),\s*(\d+))/);
        const group = tr.match('A 5 thick line from 10,20,30 to 40,50,60');
        assert.strictEqual(group.children[0].value, '5');
        assert.strictEqual(group.children[1].value, '10,20,30');
        assert.strictEqual(group.children[1].children[0].value, '10');
        assert.strictEqual(group.children[1].children[1].value, '20');
        assert.strictEqual(group.children[1].children[2].value, '30');
        assert.strictEqual(group.children[2].value, '40,50,60');
        assert.strictEqual(group.children[2].children[0].value, '40');
        assert.strictEqual(group.children[2].children[1].value, '50');
        assert.strictEqual(group.children[2].children[2].value, '60');
    });
    it('detects multiple non capturing groups', () => {
        const tr = new TreeRegexp(/(?:a)(:b)(\?c)(d)/);
        const group = tr.match('a:b?cd');
        assert.strictEqual(group.children.length, 3);
    });
    it('works with escaped backslash', () => {
        const tr = new TreeRegexp(/foo\\(bar|baz)/);
        const group = tr.match('foo\\bar');
        assert.strictEqual(group.children.length, 1);
    });
    it('works with escaped slash', () => {
        const tr = new TreeRegexp(/^I go to '\/(.+)'$/);
        const group = tr.match("I go to '/hello'");
        assert.strictEqual(group.children.length, 1);
    });
    it('works with digit and word', () => {
        const tr = new TreeRegexp(/^(\d) (\w+)$/);
        const group = tr.match('2 you');
        assert.strictEqual(group.children.length, 2);
    });
    it('captures non capturing groups with capturing groups inside', () => {
        const tr = new TreeRegexp('the stdout(?: from "(.*?)")?');
        const group = tr.match('the stdout');
        assert.strictEqual(group.value, 'the stdout');
        assert.strictEqual(group.children[0].value, undefined);
        assert.strictEqual(group.children.length, 1);
    });
    it('works with case insensitive flag', () => {
        const tr = new TreeRegexp(/HELLO/i);
        const group = tr.match('hello');
        assert.strictEqual(group.value, 'hello');
    });
    it('empty capturing group', () => {
        const tr = new TreeRegexp(/()/);
        const group = tr.match('');
        assert.strictEqual(group.value, '');
        assert.strictEqual(group.children.length, 1);
    });
    it('empty look ahead', () => {
        const tr = new TreeRegexp(/(?<=)/);
        const group = tr.match('');
        assert.strictEqual(group.value, '');
        assert.strictEqual(group.children.length, 0);
    });
    it('does not consider parenthesis in character class as group', () => {
        const tr = new TreeRegexp(/^drawings: ([A-Z, ()]+)$/);
        const group = tr.match('drawings: ONE(TWO)');
        assert.strictEqual(group.value, 'drawings: ONE(TWO)');
        assert.strictEqual(group.children.length, 1);
        assert.strictEqual(group.children[0].value, 'ONE(TWO)');
    });
});
//# sourceMappingURL=TreeRegexpTest.js.map