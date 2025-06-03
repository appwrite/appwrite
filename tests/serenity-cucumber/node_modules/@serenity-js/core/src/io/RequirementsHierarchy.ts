import { ConfigurationError } from '../errors';
import { CapabilityTag, FeatureTag, Tag, ThemeTag } from '../model';
import type { FileSystem } from './FileSystem';
import { Path } from './Path';

export class RequirementsHierarchy {

    private root: Path;

    private static readonly specDirectoryCandidates = [
        `features`,
        `specs`,
        `spec`,
        `tests`,
        `test`,
        `src`,
    ];

    constructor(
        private readonly fileSystem: FileSystem,
        private readonly userDefinedSpecDirectory?: Path,
    ) {
    }

    requirementTagsFor(pathToSpec: Path, featureName?: string): Tag[] {
        const [ fileBasedFeatureName, capabilityName, ...themeNames ] = this.hierarchyFor(pathToSpec).reverse().filter(segment => !['.', '..'].includes(segment));

        const themeTags = themeNames.reverse().map(themeName => Tag.humanReadable(ThemeTag, themeName));
        const capabilityTag = capabilityName && Tag.humanReadable(CapabilityTag, capabilityName);
        const featureTag = featureName
            ? new FeatureTag(featureName)
            : Tag.humanReadable(FeatureTag, fileBasedFeatureName)

        return [
            ...themeTags,
            capabilityTag,
            featureTag
        ].filter(Boolean);
    }

    hierarchyFor(pathToSpec: Path): string[] {
        const relative = this.rootDirectory().relative(pathToSpec);

        return relative.split().map((segment, i, segments) => {
            // return all the segments as-is, except for the last one
            if (i < segments.length - 1) {
                return segment;
            }

            // Strip the extension, like `.feature` or `.spec.ts`
            const firstDotIndex = segment.indexOf('.');
            return firstDotIndex === -1
                ? segment
                : segment.slice(0, firstDotIndex);
        });
    }

    rootDirectory(): Path {
        if (!this.root) {
            this.root = this.userDefinedSpecDirectory
                ? this.resolve(this.userDefinedSpecDirectory)
                : this.guessRootDirectory();
        }

        return this.root;
    }

    private guessRootDirectory(): Path {
        for (const candidate of RequirementsHierarchy.specDirectoryCandidates) {
            const candidateSpecDirectory = Path.from(candidate);
            if (this.fileSystem.exists(Path.from(candidate))) {
                return this.fileSystem.resolve(candidateSpecDirectory);
            }
        }

        // default to current working directory
        return this.fileSystem.resolve(Path.from('.'));
    }

    private resolve(userDefinedRootDirectory: Path): Path {
        if (!this.fileSystem.exists(userDefinedRootDirectory)) {
            throw new ConfigurationError(`Configured specDirectory \`${ userDefinedRootDirectory }\` does not exist`);
        }

        return this.fileSystem.resolve(userDefinedRootDirectory);
    }
}