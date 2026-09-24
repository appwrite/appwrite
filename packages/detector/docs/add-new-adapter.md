# Add new Detector and Detection Adapters

To get started with implementing a new detector and detection adapters, start by reviewing the [README](/README.md) to understand the goals of this library.

### Introduction 📝
- A `Detector` is a class that analyzes input files to identify specific characteristics of a project.
- A `Detection` class represents a specific type of detection (like Framework, Runtime, Packager, etc.) and contains the logic for identifying that type.
- To add a new detector, you need to extend the `Detector` parent class and define the required methods.
- To add a new detection class, you need to extend the appropriate `Detection` parent class (Framework, Runtime, Packager, or Rendering).

### File Structure 📂

Below are outlined the most useful files for adding a new detector and detection class:

```bash
.
├── src # Source code
│   ├── Detector.php # Parent class for all detectors
│   ├── Detection.php # Parent class for all detections
│   ├── Detector/ # Where your new detector goes!
│   │   ├── Framework.php # Framework detector
│   │   ├── Packager.php # Packager detector
│   │   ├── Runtime.php # Runtime detector
│   │   └── Rendering.php # Rendering detector
│   └── Detection/ # Where your new detection class goes!
│       ├── Framework/ # Framework detections
│       ├── Packager/ # Packager detections
│       ├── Runtime/ # Runtime detections
│       └── Rendering/ # Rendering detections
└── tests
    └── unit/ # Where tests for your new detector/detection go!
```

### Extend the Detector 💻

Create your new detector file in `src/Detector` and extend the parent class:

```php
<?php

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detector;
use Utopia\Detector\Detection\YourDetection;

class YourDetector extends Detector
{
    /**
     * @var array<YourDetection>
     */
    protected array $options = [];

    /**
     * @param array<string> $inputs
     */
    public function __construct(protected array $inputs)
    {
    }

    public function detect(): ?YourDetection
    {
        foreach ($this->options as $detector) {
            $detectorFiles = $detector->getFiles();
            $matches = array_intersect($detectorFiles, $this->inputs);
            
            if (count($matches) > 0) {
                return $detector;
            }
        }

        return null;
    }
}
```

### Extend the Detection Class 💻

Create your new detection class in the appropriate directory under `src/Detection` and extend the parent class:

```php
<?php

namespace Utopia\Detector\Detection\YourType;

use Utopia\Detector\Detection\YourParentType;

class YourDetection extends YourParentType
{
    public function getName(): string
    {
        return 'your-detection-name';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['file1.ext', 'file2.ext'];
    }

    // Implement other required methods based on parent class
}
```

### Testing 🧪

Add tests for your new detector and detection class in `tests/unit/DetectorTest.php`. Here's an example:

```php
public function testYourDetector(array $files, ?string $expectedName): void
{
    $detector = new YourDetector($files);
    $detector->addOption(new YourDetection());

    $detected = $detector->detect();

    if ($expectedName) {
        $this->assertNotNull($detected);
        $this->assertSame($expectedName, $detected->getName());
    } else {
        $this->assertNull($detected);
    }
}

/**
 * @return array<array{array<string>, string|null}>
 */
public function yourDetectorDataProvider(): array
{
    return [
        [['file1.ext', 'file2.ext'], 'your-detection-name'],
        [['other.ext'], null],
    ];
}
```

### Tips and Tricks 💡

1. **Choose the Right Parent Class**
   - For framework detection: Extend `Detection\Framework`
   - For runtime detection: Extend `Detection\Runtime`
   - For packager detection: Extend `Detection\Packager`
   - For rendering detection: Extend `Detection\Rendering`

2. **Implement Required Methods**
   - Each detection type has specific required methods
   - Framework detections need: `getName()`, `getFiles()`, `getInstallCommand()`, `getBuildCommand()`, `getOutputDirectory()`
   - Runtime detections need: `getName()`, `getLanguages()`, `getFileExtensions()`, `getFiles()`, `getCommands()`, `getEntrypoint()`
   - Packager detections need: `getName()`, `getFiles()`
   - Rendering detections need: `getName()`

3. **File Detection**
   - Use specific file patterns that uniquely identify your detection
   - Consider both common and edge cases
   - Include configuration files, lock files, and other distinctive files

4. **Testing**
   - Test both positive and negative cases
   - Include edge cases in your test data
   - Follow the existing test patterns in the codebase

5. **Performance**
   - Keep detection logic simple and efficient
   - Minimize file system operations
   - Use array operations for file matching

### Example Implementation

Here's a complete example of implementing a new framework detection:

```php
<?php

namespace Utopia\Detector\Detection\Framework;

use Utopia\Detector\Detection\Framework;

class MyFramework extends Framework
{
    public function getName(): string
    {
        return 'my-framework';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return ['my-framework.config.js', 'my-framework.lock'];
    }

    public function getInstallCommand(): string
    {
        return 'pnpm install';
    }

    public function getBuildCommand(): string
    {
        return 'pnpm run build';
    }

    public function getOutputDirectory(): string
    {
        return './dist';
    }
}
```

Only include dependencies strictly necessary for the adapter, preferably official PHP libraries, if available.

### Testing with Docker 🛠️

The existing test suite is helpful when developing a new Detector/ Detection adapter. Use official Docker images from trusted sources. Add new tests for your new Detector/ Detection adapter in `tests/unit/DetectorTest.php` test class. The specific `docker-compose` command for testing can be found in the [README](/README.md#tests).
