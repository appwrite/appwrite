import * as nodeOS from 'node:os';

import { createId } from '@paralleldrive/cuid2';
import type * as NodeFS from 'fs';
import * as gracefulFS from 'graceful-fs';

import { Path } from './Path';

export class FileSystem {

    constructor(
        private readonly root: Path,
        private readonly fs: typeof NodeFS = gracefulFS,
        private readonly os: typeof nodeOS = nodeOS,
        private readonly directoryMode = Number.parseInt('0777', 8) & (~process.umask()),
    ) {
    }

    public resolve(relativeOrAbsolutePath: Path): Path {
        return this.root.resolve(relativeOrAbsolutePath);
    }

    public async store(relativeOrAbsolutePathToFile: Path, data: string | NodeJS.ArrayBufferView, encoding?: NodeFS.WriteFileOptions): Promise<Path> {
        await this.ensureDirectoryExistsAt(relativeOrAbsolutePathToFile.directory());
        return this.writeFile(relativeOrAbsolutePathToFile, data, encoding);
    }

    public readFile(relativeOrAbsolutePathToFile: Path, options?: { encoding?: null | undefined; flag?: string | undefined; }): Promise<Buffer>
    public readFile(relativeOrAbsolutePathToFile: Path, options: { encoding: BufferEncoding; flag?: string | undefined; } | NodeJS.BufferEncoding): Promise<string>
    public readFile(relativeOrAbsolutePathToFile: Path, options?: (NodeFS.ObjectEncodingOptions & { flag?: string | undefined; }) | NodeJS.BufferEncoding): Promise<string | Buffer> {
        return this.fs.promises.readFile(this.resolve(relativeOrAbsolutePathToFile).value, options);
    }

    public readFileSync(relativeOrAbsolutePathToFile: Path, options?: { encoding?: null | undefined; flag?: string | undefined; }): Buffer
    public readFileSync(relativeOrAbsolutePathToFile: Path, options: { encoding: BufferEncoding; flag?: string | undefined; } | NodeJS.BufferEncoding): string
    public readFileSync(relativeOrAbsolutePathToFile: Path, options?: (NodeFS.ObjectEncodingOptions & { flag?: string | undefined; }) | NodeJS.BufferEncoding): string | Buffer {
        return this.fs.readFileSync(this.resolve(relativeOrAbsolutePathToFile).value, options);
    }

    public async writeFile(relativeOrAbsolutePathToFile: Path, data: string | NodeJS.ArrayBufferView, options?: NodeFS.WriteFileOptions): Promise<Path> {
        const resolvedPath = this.resolve(relativeOrAbsolutePathToFile);
        await this.fs.promises.writeFile(resolvedPath.value, data, options);

        return resolvedPath;
    }

    public writeFileSync(relativeOrAbsolutePathToFile: Path, data: string | NodeJS.ArrayBufferView, options?: NodeFS.WriteFileOptions): Path {
        const resolvedPath = this.resolve(relativeOrAbsolutePathToFile);
        this.fs.writeFileSync(resolvedPath.value, data, options);

        return resolvedPath;
    }

    public createReadStream(relativeOrAbsolutePathToFile: Path): NodeFS.ReadStream {
        return this.fs.createReadStream(this.resolve(relativeOrAbsolutePathToFile).value);
    }

    public createWriteStreamTo(relativeOrAbsolutePathToFile: Path): NodeFS.WriteStream {
        return this.fs.createWriteStream(this.resolve(relativeOrAbsolutePathToFile).value);
    }

    public stat(relativeOrAbsolutePathToFile: Path): Promise<NodeFS.Stats> {
        return this.fs.promises.stat(this.resolve(relativeOrAbsolutePathToFile).value);
    }

    public exists(relativeOrAbsolutePathToFile: Path): boolean {
        return this.fs.existsSync(this.resolve(relativeOrAbsolutePathToFile).value);
    }

    public async remove(relativeOrAbsolutePathToFileOrDirectory: Path): Promise<void> {
        try {
            const absolutePath = this.resolve(relativeOrAbsolutePathToFileOrDirectory);

            const stat = await this.stat(relativeOrAbsolutePathToFileOrDirectory);

            if (stat.isFile()) {
                await this.fs.promises.unlink(absolutePath.value);
            }
            else {
                const entries = await this.fs.promises.readdir(absolutePath.value);
                for (const entry of entries) {
                    await this.remove(absolutePath.join(new Path(entry)));
                }

                await this.fs.promises.rmdir(absolutePath.value);
            }
        }
        catch (error) {
            if (error?.code === 'ENOENT') {
                return void 0;
            }
            throw error;
        }
    }

    public async ensureDirectoryExistsAt(relativeOrAbsolutePathToDirectory: Path): Promise<Path> {

        const absolutePath = this.resolve(relativeOrAbsolutePathToDirectory);

        await this.fs.promises.mkdir(absolutePath.value, { recursive: true, mode: this.directoryMode });

        return absolutePath;
    }

    public rename(source: Path, destination: Path): Promise<void> {
        return this.fs.promises.rename(source.value, destination.value);
    }

    public tempFilePath(prefix = '', suffix = '.tmp'): Path {
        return Path.from(this.fs.realpathSync(this.os.tmpdir()), `${ prefix }${ createId() }${ suffix }`);
    }
}
