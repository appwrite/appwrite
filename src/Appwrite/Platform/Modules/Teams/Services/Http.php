<?php

namespace Appwrite\Platform\Modules\Teams\Services;

use Appwrite\Platform\Modules\Teams\Http\Memberships\Create as CreateMembership;
use Appwrite\Platform\Modules\Teams\Http\Memberships\Delete as DeleteMembership;
use Appwrite\Platform\Modules\Teams\Http\Memberships\Get as GetMembership;
use Appwrite\Platform\Modules\Teams\Http\Memberships\Status\Update as UpdateMembershipStatus;
use Appwrite\Platform\Modules\Teams\Http\Memberships\Update as UpdateMembership;
use Appwrite\Platform\Modules\Teams\Http\Memberships\XList as ListMemberships;
use Appwrite\Platform\Modules\Teams\Http\Preferences\Get as GetPreferences;
use Appwrite\Platform\Modules\Teams\Http\Preferences\Update as UpdatePreferences;
use Appwrite\Platform\Modules\Teams\Http\Teams\Create as CreateTeam;
use Appwrite\Platform\Modules\Teams\Http\Teams\Delete as DeleteTeam;
use Appwrite\Platform\Modules\Teams\Http\Teams\Get as GetTeam;
use Appwrite\Platform\Modules\Teams\Http\Teams\Name\Update as UpdateTeamName;
use Appwrite\Platform\Modules\Teams\Http\Teams\XList as ListTeams;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Teams
        $this->addAction('createTeam', CreateTeam::class);
        $this->addAction('getTeam', GetTeam::class);
        $this->addAction('listTeams', ListTeams::class);
        $this->addAction('deleteTeam', DeleteTeam::class);
        $this->addAction('updateTeamName', UpdateTeamName::class);

        // Preferences
        $this->addAction('getTeamPreferences', GetPreferences::class);
        $this->addAction('updateTeamPreferences', UpdatePreferences::class);

        // Memberships
        $this->addAction('createTeamMembership', CreateMembership::class);
        $this->addAction('getTeamMembership', GetMembership::class);
        $this->addAction('listTeamMemberships', ListMemberships::class);
        $this->addAction('updateTeamMembership', UpdateMembership::class);
        $this->addAction('deleteTeamMembership', DeleteMembership::class);
        $this->addAction('updateTeamMembershipStatus', UpdateMembershipStatus::class);
    }
}
