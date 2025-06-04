import assert from 'assert';
import { removeUserInfoFromUrl } from '../src/detectCiEnvironment.js';
describe('removeUserInfoFromUrl', () => {
    it('returns empty string for empty string', () => {
        assert.strictEqual(removeUserInfoFromUrl(''), '');
    });
    it('leaves the data intact when no sensitive information is detected', () => {
        assert.strictEqual(removeUserInfoFromUrl('pretty safe'), 'pretty safe');
    });
    context('with URLs', () => {
        it('leaves intact when no password is found', () => {
            assert.strictEqual(removeUserInfoFromUrl('https://example.com/git/repo.git'), 'https://example.com/git/repo.git');
        });
        it('removes credentials when found', () => {
            assert.strictEqual(removeUserInfoFromUrl('http://aslak@example.com/git/repo.git'), 'http://example.com/git/repo.git');
        });
        it('removes credentials and passwords when found', () => {
            assert.strictEqual(removeUserInfoFromUrl('ssh://login:password@example.com/git/repo.git'), 'ssh://example.com/git/repo.git');
        });
    });
});
//# sourceMappingURL=removeUserInfoFromUrlTest.js.map