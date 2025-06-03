import semver from 'semver';
import { ensure, isDefined, isString, Predicate, TinyType } from 'tiny-types';

/**
 * A tiny type describing a version number, like `1.2.3`
 */
export class Version extends TinyType {

    static fromJSON(version: string): Version {
        return new Version(version);
    }

    constructor(private readonly version: string) {
        super();
        ensure('version', version, isDefined(), isString(), isValid());
    }

    isLowerThan(other: Version): boolean {
        return semver.lt(this.version, other.version, { loose: false });
    }

    isAtMost(other: Version): boolean {
        return semver.lte(this.version, other.version, { loose: false });
    }

    /**
     * @param other
     */
    isAtLeast(other: Version): boolean {
        return semver.gte(this.version, other.version, { loose: false });
    }

    isHigherThan(other: Version): boolean {
        return semver.gt(this.version, other.version, { loose: false });
    }

    /**
     * @returns
     *  Major version number of a given package version, i.e. `1` in `1.2.3`
     */
    major(): number {
        return Number(this.version.split('.')[0]);
    }

    satisfies(range: string): boolean {
        return semver.satisfies(this.version, range, { loose: false });
    }

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
