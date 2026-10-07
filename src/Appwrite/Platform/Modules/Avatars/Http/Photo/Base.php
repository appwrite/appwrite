<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\Utopia\Database\Documents\User;
use Psr\Http\Message\StreamInterface;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

abstract class Base extends Action
{
    use HTTP;

    private const MAX_UPDATE_ATTEMPTS = 5;

    /**
     * Store $photo as the user's photo in place of whatever they had.
     *
     * The file goes up before the attributes, so a failure at either step
     * leaves the user's photo as it was.
     *
     * @return Document The user, with the new photo
     */
    protected function replacePhoto(User $user, StreamInterface $photo, int $size, string $mimeType, Database $dbForProject, Device $deviceForFiles): Document
    {
        $userId = $user->getId();
        $photoId = ID::unique();
        $path = $deviceForFiles->getPath(APP_STORAGE_PHOTOS . '/' . $userId . '/' . $photoId);

        $deviceForFiles->upload($photo, $path, $mimeType);

        // A concurrent upload may have replaced the photo since it was read, so the replaced photo is re-read until the update wins
        $current = $user;
        $attempts = 0;

        while (true) {
            $previous = $current->getAttribute('photoId', '');

            try {
                if ($current->isEmpty()) {
                    throw new Exception(Exception::USER_NOT_FOUND);
                }

                $user = $dbForProject->withRequestTimestamp(
                    new \DateTime($current->getUpdatedAt()),
                    fn () => $dbForProject->updateDocument('users', $userId, new Document([
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

                $current = $dbForProject->getDocument('users', $userId);
            } catch (\Throwable $th) {
                $deviceForFiles->delete($path);

                throw $th;
            }
        }

        // The new photo is live, so a file left behind here only waits for user deletion to remove the user's photo folder
        if ($previous !== '') {
            try {
                $previousPath = $deviceForFiles->getPath(APP_STORAGE_PHOTOS . '/' . $userId . '/' . $previous);

                if ($deviceForFiles->exists($previousPath) && !$deviceForFiles->delete($previousPath)) {
                    Console::warning('Failed to remove previous photo ' . $previous);
                }
            } catch (\Throwable $th) {
                Console::warning('Failed to remove previous photo ' . $previous . ': ' . $th->getMessage());
            }
        }

        return $user;
    }
}
