import semver from 'semver';
import { ensure, isDefined, isString, Predicate, TinyType } from 'tiny-types';

/**
 * A tiny type describing a version number, like `1.2.3`
 */
export class Version extends TinyType {

    /**
     * @param {string} version
     * @returns {Version}
     */
    static fromJSON(version: string): Version {
        return new Version(version);
    }

    /**
     * @param {string} version
     */
    constructor(private readonly version: string) {
        super();
        ensure('version', version, isDefined(), isString(), isValid());
    }

    /**
     * @param {Version} other
     * @returns {boolean}
     */
    isAtLeast(other: Version): boolean {
        return semver.gte(this.version, other.version);
    }

    /**
     * @returns {number}
     *  Major version number of a given package version, i.e. `1` in `1.2.3`
     */
    major(): number {
        return Number(this.version.split('.')[0]);
    }

    /**
     * @returns {string}
     */
    toString(): string {
        return `${ this.version }`;
    }
}

/**
 * @package
 */
function isValid(): Predicate<string> {
    return Predicate.to(`be a valid version number`, (version: string) =>
        !! semver.valid(version),
    );
}
