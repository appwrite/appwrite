<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class Ruby extends Runtime
{
    public function getName(): string
    {
        return 'ruby';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['Ruby'];
    }

    public function getCommands(): string
    {
        return 'bundle install && bundle exec rake build';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['rb'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['Gemfile', 'Gemfile.lock', 'Rakefile', 'Guardfile'];
    }

    public function getEntrypoint(): string
    {
        return 'main.rb';
    }
}
