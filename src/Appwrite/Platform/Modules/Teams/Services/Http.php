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
        $this->addAction(CreateTeam::getName(), CreateTeam::class);
        $this->addAction(GetTeam::getName(), GetTeam::class);
        $this->addAction(ListTeams::getName(), ListTeams::class);
        $this->addAction(DeleteTeam::getName(), DeleteTeam::class);
        $this->addAction(UpdateTeamName::getName(), UpdateTeamName::class);

        // Preferences
        $this->addAction(GetPreferences::getName(), GetPreferences::class);
        $this->addAction(UpdatePreferences::getName(), UpdatePreferences::class);

        // Memberships
        $this->addAction(CreateMembership::getName(), CreateMembership::class);
        $this->addAction(GetMembership::getName(), GetMembership::class);
        $this->addAction(ListMemberships::getName(), ListMemberships::class);
        $this->addAction(UpdateMembership::getName(), UpdateMembership::class);
        $this->addAction(DeleteMembership::getName(), DeleteMembership::class);
        $this->addAction(UpdateMembershipStatus::getName(), UpdateMembershipStatus::class);
    }
}
