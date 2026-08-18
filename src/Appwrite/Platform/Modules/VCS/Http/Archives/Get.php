<?php

namespace Appwrite\Platform\Modules\VCS\Http\Archives;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\SourceArchive;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getVCSArchive';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/archives')
            ->desc('Download repository archive')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->param('provider', '', new Text(64, 0), 'VCS provider the repository lives on.')
            ->param('installation', '', new Text(256, 0), 'Provider installation ID the credentials belong to.')
            ->param('owner', '', new Text(256, 0), 'Owner of the repository.')
            ->param('repository', '', new Text(256, 0), 'Name of the repository.')
            ->param('ref', '', new Text(256, 0), 'Branch, tag or commit to archive.')
            ->param('expires', 0, new Integer(loose: true, bits: 64), 'Unix timestamp the link stops working at.')
            ->param('signature', '', new Text(128, 0), 'HMAC binding every other parameter.')
            ->inject('response')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    /**
     * Packages a repository the provider cannot archive itself. The link is
     * minted by Appwrite\Vcs\SourceArchive for the jobs-service source fetch,
     * and the HMAC is the whole authorization: it commits to every parameter
     * and an expiry, and only Appwrite can produce it.
     */
    public function action(
        string $provider,
        string $installation,
        string $owner,
        string $repository,
        string $ref,
        int $expires,
        string $signature,
        Response $response,
        VcsFactory $vcsFactory,
    ) {
        $expected = SourceArchive::signature($provider, $installation, $owner, $repository, $ref, $expires);

        if (empty($signature) || !\hash_equals($expected, $signature)) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid archive signature.');
        }

        if ($expires < \time()) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'The archive link has expired.');
        }

        $vcs = $vcsFactory->fromInstallation(new Document([
            'provider' => $provider,
            'providerInstallationId' => $installation,
        ]));

        // Git-forge archives wrap the tree in a "{repo}-{ref}/" root the
        // source fetch strips, so this one has to match that shape.
        $directory = '/tmp/vcs-archives/' . \uniqid();
        $wrapper = $repository . '-' . \substr(\preg_replace('/[^A-Za-z0-9._-]/', '-', $ref) ?? '', 0, 64);
        $archive = $directory . '/archive.tar.gz';

        $cloneType = \preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/i', $ref) ? Git::CLONE_TYPE_COMMIT : Git::CLONE_TYPE_BRANCH;
        $cloneCommand = $vcs->generateCloneCommand($owner, $repository, $ref, $cloneType, $directory . '/' . $wrapper, '*');

        try {
            $stdout = '';
            $stderr = '';

            if (Console::execute($cloneCommand, '', $stdout, $stderr) !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to download the repository.');
            }

            $packCommand = 'tar --exclude=' . \escapeshellarg($wrapper . '/.git') . ' -czf ' . \escapeshellarg($archive) . ' -C ' . \escapeshellarg($directory) . ' ' . \escapeshellarg($wrapper);
            if (Console::execute($packCommand, '', $stdout, $stderr) !== 0) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to package the repository.');
            }

            $contents = \file_get_contents($archive);
            if ($contents === false) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to read the repository archive.');
            }

            $response
                ->setContentType('application/gzip')
                ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->addHeader('Content-Disposition', 'attachment; filename="' . $wrapper . '.tar.gz"')
                ->send($contents);
        } finally {
            Console::execute('rm -rf ' . \escapeshellarg($directory), '', $stdout, $stderr);
        }
    }
}
