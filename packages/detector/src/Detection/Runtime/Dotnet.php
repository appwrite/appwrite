<?php

namespace Utopia\Detector\Detection\Runtime;

use Utopia\Detector\Detection\Runtime;

class Dotnet extends Runtime
{
    public function getName(): string
    {
        return 'dotnet';
    }

    /**
     * @return array<string>
     */
    public function getLanguages(): array
    {
        return ['C#', 'Visual Basic .NET'];
    }

    public function getCommands(): string
    {
        return 'dotnet restore && dotnet build';
    }

    /**
     * @return array<string>
     */
    public function getFileExtensions(): array
    {
        return ['cs', 'vb', 'sln', 'csproj', 'vbproj'];
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['Program.cs', 'Solution.sln', 'Function.csproj', 'Program.vb'];
    }

    public function getEntrypoint(): string
    {
        return 'Program.cs';
    }
}
