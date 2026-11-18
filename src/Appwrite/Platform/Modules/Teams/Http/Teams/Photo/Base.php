<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams\Photo;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Psr\Http\Message\StreamInterface;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Id;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

abstract class Base extends Action
{
    use HTTP;

    private const MAX_UPDATE_ATTEMPTS = 5;

    protected function replacePhoto(Document $team, StreamInterface $photo, int $size, string $mimeType, Database $dbForProject, Device $deviceForFiles): Document
    {
        $teamId = $team->getId();
        $photoId = Id::unique();
        $path = $deviceForFiles->getPath(APP_STORAGE_TEAM_PHOTOS . '/' . $teamId . '/' . $photoId);

        $deviceForFiles->upload($photo, $path, $mimeType);

        // A concurrent upload may have replaced the photo since it was read, so the replaced photo is re-read until the update wins
        $current = $team;
        $attempts = 0;

        while (true) {
            $previous = $current->getAttribute('photoId', '');

            try {
                if ($current->isEmpty()) {
                    throw new Exception(Exception::TEAM_NOT_FOUND);
                }

                $team = $dbForProject->withRequestTimestamp(
                    new \DateTime($current->getUpdatedAt()),
                    fn () => $dbForProject->updateDocument('teams', $teamId, new Document([
                        'photoId' => $photoId,
                        'photoSize' => $size,
                    ]))
                );

                break;
            } catch (ConflictException) {
                if (++$attempts >= self::MAX_UPDATE_ATTEMPTS) {
                    $deviceForFiles->delete($path);

                    throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT, 'Photo was changed by another request, please try again');
                }

                try {
                    $current = $dbForProject->getDocument('teams', $teamId);
                } catch (\Throwable $th) {
                    $deviceForFiles->delete($path);

                    throw $th;
                }
            } catch (\Throwable $th) {
                $deviceForFiles->delete($path);

                throw $th;
            }
        }

        // The new photo is live, so a file left behind here only waits for team deletion to remove the team's photo folder
        if ($previous !== '') {
            try {
                $previousPath = $deviceForFiles->getPath(APP_STORAGE_TEAM_PHOTOS . '/' . $teamId . '/' . $previous);

                if ($deviceForFiles->exists($previousPath) && !$deviceForFiles->delete($previousPath)) {
                    Console::warning('Failed to remove previous team photo ' . $previous);
                }
            } catch (\Throwable $th) {
                Console::warning('Failed to remove previous team photo ' . $previous . ': ' . $th->getMessage());
            }
        }

        return $team;
    }
}
