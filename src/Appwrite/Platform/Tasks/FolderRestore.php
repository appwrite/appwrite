<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Storage\Device\Local;

class FolderRestore extends Action
{
    public static function getName(): string
    {
        return 'folder-restore';
    }

    public function __construct()
    {
        $this
            ->desc('Folder restore process')
            ->callback(fn() => $this->action());
    }

    public function action(): void
    {

        $folders = [
            'cert' => APP_STORAGE_CERTIFICATES,
            'config' => APP_STORAGE_CONFIG,
        ];

        $folder = \strtolower(Console::confirm('Please enter the folder you wish to restore'));
        $date   = Console::confirm('Please enter the date of the backup file in datetime format(Y-m-d)');

        if (!array_key_exists($folder, $folders)) {
            console::Error('Unknown folder given');
            exit;
        }

        if (!is_a(\DateTime::createFromFormat("Y-m-d", $date), 'DateTime')) {
            console::Error('Unknown date format');
            exit;
        }

        $remote = getDevice('/');
        $local  = new Local();
        $filename = $folder . '-' . $date . '.tar.gz';
        $destination = $folders[$folder] . '/' . $filename;
        $source = '/' . $folder . '/' . $filename;

        try {
            $remote->transfer($source, $destination, $local);

            $stdout = '';
            $stderr = '';
            Console::execute(
                'cd ' . $folders[$folder] . ' &&  tar -xf ' . $filename,
                '',
                $stdout,
                $stderr
            );

            console::info("restoring from {$remote->getName()}  $source to  $destination");
        } catch (\Exception $e) {
            Console::error($e->getMessage());
        }
    }
}
