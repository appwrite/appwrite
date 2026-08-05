<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Projects;

use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Tests\Create as SMTPTestCreate;
use Appwrite\Platform\Modules\Projects\Http\Projects\Team\Update as TeamUpdate;
use PHPUnit\Framework\TestCase;

final class ProjectsAuditLabelsTest extends TestCase
{
    public function test_project_team_update_audit_labels(): void
    {
        $action = new TeamUpdate;
        $labels = $action->getLabels();

        $this->assertEquals('projects.team.update', $labels['audits.event'] ?? null);
        $this->assertEquals('project/{request.projectId}', $labels['audits.resource'] ?? null);
    }

    public function test_smtp_test_create_audit_labels(): void
    {
        $action = new SMTPTestCreate;
        $labels = $action->getLabels();

        $this->assertEquals('project.smtp.test', $labels['audits.event'] ?? null);
        $this->assertEquals('project/{request.projectId}', $labels['audits.resource'] ?? null);
    }
}
