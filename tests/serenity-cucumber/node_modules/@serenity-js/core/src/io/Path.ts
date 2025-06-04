import filenamify from 'filenamify';
import { ensure, isDefined, isGreaterThan, property, TinyType } from 'tiny-types';
import path, { sep } from 'upath';

export class Path extends TinyType {
    private static readonly Separator = '/';
    public readonly value: string;

    static fromJSON(v: string): Path {
        return new Path(v);
    }

    static fromFileURL(fileUrl: URL): Path {
        // inspired by https://github.com/TooTallNate/file-uri-to-path
        if (fileUrl.protocol !== 'file:') {
            throw new TypeError(
                `A Path can be created only from URLs that start with 'file://'. Received: ${ fileUrl }`
            );
        }

        const url = fileUrl.toString();
        const rest = decodeURI(url.slice(7));
        const firstSlash = rest.indexOf('/');

        let host = rest.slice(0, Math.max(0, firstSlash));

        // 2.  Scheme Definition
        // As a special case, <host> can be the string "localhost" or the empty
        // string; this is interpreted as "the machine from which the URL is being interpreted".
        if (host === 'localhost') {
            host = '';
        }

        if (host) {
            host = sep + sep + host;
        }

        let path = rest.slice(Math.max(0, firstSlash + 1));

        // Drives, drive letters, mount points, file system root
        //
        // Drive letters are mapped into the top of a file URI in various ways, depending on the implementation;
        // some applications substitute vertical bar ("|") for the colon after the drive letter,
        // yielding "file:///c|/tmp/test.txt".
        // In some cases, the colon is left unchanged, as in "file:///c:/tmp/test.txt".
        // In other cases, the colon is simply omitted, as in "file:///c/tmp/test.txt".
        path = path.replace(/^(.+)\|/, '$1:');

        // for Windows, we need to invert the path separators from what a URI uses
        if (sep === '\\') {
            path = path.replaceAll('/', '\\');
        }

        if (! (/^.+:/.test(path))) {
            // unix path, because there's no Windows drive at the beginning
            path = sep + path;
        }

        return new Path(host + path);
    }

    static from(...segments: string[]): Path {
        if (segments.length === 1 && segments[0].startsWith('file://')) {
            return Path.fromFileURL(new URL(segments[0]));
        }
        return new Path(path.joinSafe(...segments));
    }

    static fromSanitisedString(value: string): Path {
        const
            normalised = path.normalize(value).replaceAll(/["'/:\\]/gi, ''),
            extension  = path.extname(normalised),
            basename   = path.basename(normalised, extension),
            filename   = filenamify(basename, { replacement: '-', maxLength: 250 })
                .trim()
                .replaceAll(/[\s-]+/g, '-');

        return new Path(path.join(
            path.dirname(normalised),
            `${ filename }${ extension }`,
        ));
    }

    constructor(value: string) {
        super();
        ensure(Path.name, value, isDefined(), property('length', isGreaterThan(0)));

        this.value = path.normalize(value);
    }

    join(another: Path): Path {
        return new Path(path.join(this.value, another.value));
    }

    split(): string[] {
        return this.value
            .split(Path.Separator)
            .filter(segment => !! segment); // so that we ignore the trailing path separator in absolute paths
    }

    resolve(another: Path): Path {
        return new Path(path.resolve(this.value, another.value));
    }

    relative(another: Path): Path {
        return new Path(path.relative(this.value, another.value) || '.');
    }

    directory(): Path {
        return new Path(path.dirname(this.value));
    }

    basename(): string {
        return path.basename(this.value);
    }

    isAbsolute(): boolean {
        return path.isAbsolute(this.value);
    }

    root(): Path {
        return new Path(path.parse(this.value).root);
    }

    toFileURL(): URL {
        return new URL(
            encodeURI(`file://${this.value}`).replaceAll(/[#?]/g, encodeURIComponent)
        );
    }
}