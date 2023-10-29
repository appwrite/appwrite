<?php

// Define the namespace for the Appwrite\Platform class
namespace Appwrite\Platform;

// Import the 'Tasks' and 'Workers' services
use Appwrite\Platform\Services\Tasks;
use Appwrite\Platform\Services\Workers;

// Import the 'Platform' class from the Utopia namespace
use Utopia\Platform\Platform;

// Define the 'Appwrite' class that extends the 'Platform' class
class Appwrite extends Platform
{
    // Constructor for the 'Appwrite' class
    public function __construct()
    {
        // Call the constructor of the parent class 'Platform'
        parent::__construct();

        // Add the 'tasks' service to the 'Appwrite' platform
        $this->addService('tasks', new Tasks());

        // Add the 'workers' service to the 'Appwrite' platform
        $this->addService('workers', new Workers());
    }
}
