<?php

// ruleid: php.appwrite.shell-exec-in-handler
exec('git clone ' . $repository, $output);

// ruleid: php.appwrite.shell-exec-in-handler
$out = shell_exec($command);

// ruleid: php.appwrite.shell-exec-in-handler
system($command);

// ruleid: php.appwrite.shell-exec-in-handler
\exec($command);

// ruleid: php.appwrite.shell-exec-in-handler
passthru($command);

// ruleid: php.appwrite.shell-exec-in-handler
$handle = popen($command, 'r');

// ruleid: php.appwrite.shell-exec-in-handler
$process = proc_open($command, $descriptors, $pipes);

// ruleid: php.appwrite.shell-exec-in-handler
$out = `ls {$path}`;

// ok: php.appwrite.shell-exec-in-handler
$result = $executor->createExecution(projectId: $projectId, deploymentId: $deploymentId);

// ok: php.appwrite.shell-exec-in-handler
$statement->execute();
