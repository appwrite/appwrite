<?php

namespace Appwrite\Platform\Modules\Videos\Services;

use Appwrite\Platform\Modules\Videos\Http\Videos\Create as CreateVideo;
use Appwrite\Platform\Modules\Videos\Http\Videos\Delete as DeleteVideo;
use Appwrite\Platform\Modules\Videos\Http\Videos\Get as GetVideo;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\DASH\Manifest\Get as GetDashManifest;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\HLS\Manifest\Get as GetHlsManifest;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Renditions\Segments\Get as GetSegment;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Renditions\Streams\Manifest\Get as GetStreamManifest;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Subtitles\Manifest\Get as GetSubtitleManifest;
use Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Subtitles\Segments\Get as GetSubtitleSegment;
use Appwrite\Platform\Modules\Videos\Http\Videos\Previews\Get as GetPreview;
use Appwrite\Platform\Modules\Videos\Http\Videos\Profiles\Create as CreateProfile;
use Appwrite\Platform\Modules\Videos\Http\Videos\Profiles\Delete as DeleteProfile;
use Appwrite\Platform\Modules\Videos\Http\Videos\Profiles\Get as GetProfile;
use Appwrite\Platform\Modules\Videos\Http\Videos\Profiles\Update as UpdateProfile;
use Appwrite\Platform\Modules\Videos\Http\Videos\Profiles\XList as ListProfiles;
use Appwrite\Platform\Modules\Videos\Http\Videos\Renditions\Create as CreateRendition;
use Appwrite\Platform\Modules\Videos\Http\Videos\Renditions\Delete as DeleteRendition;
use Appwrite\Platform\Modules\Videos\Http\Videos\Renditions\Get as GetRendition;
use Appwrite\Platform\Modules\Videos\Http\Videos\Renditions\XList as ListRenditions;
use Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles\Create as CreateSubtitle;
use Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles\Delete as DeleteSubtitle;
use Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles\Update as UpdateSubtitle;
use Appwrite\Platform\Modules\Videos\Http\Videos\Subtitles\XList as ListSubtitles;
use Appwrite\Platform\Modules\Videos\Http\Videos\Timeline\Get as GetTimeline;
use Appwrite\Platform\Modules\Videos\Http\Videos\Update as UpdateVideo;
use Appwrite\Platform\Modules\Videos\Http\Videos\XList as ListVideos;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Videos
        $this->addAction(CreateVideo::getName(), new CreateVideo());
        $this->addAction(GetVideo::getName(), new GetVideo());
        $this->addAction(ListVideos::getName(), new ListVideos());
        $this->addAction(UpdateVideo::getName(), new UpdateVideo());
        $this->addAction(DeleteVideo::getName(), new DeleteVideo());

        // Timeline and previews
        $this->addAction(GetTimeline::getName(), new GetTimeline());
        $this->addAction(GetPreview::getName(), new GetPreview());

        // Subtitles
        $this->addAction(CreateSubtitle::getName(), new CreateSubtitle());
        $this->addAction(ListSubtitles::getName(), new ListSubtitles());
        $this->addAction(UpdateSubtitle::getName(), new UpdateSubtitle());
        $this->addAction(DeleteSubtitle::getName(), new DeleteSubtitle());

        // Renditions
        $this->addAction(CreateRendition::getName(), new CreateRendition());
        $this->addAction(GetRendition::getName(), new GetRendition());
        $this->addAction(ListRenditions::getName(), new ListRenditions());
        $this->addAction(DeleteRendition::getName(), new DeleteRendition());

        // Playback. HLS and DASH master manifests get separate routes so the URL
        // keeps its .m3u8/.mpd extension for players that infer the container from
        // it (ExoPlayer, AVURLAsset).
        $this->addAction(GetHlsManifest::getName(), new GetHlsManifest());
        $this->addAction(GetDashManifest::getName(), new GetDashManifest());
        $this->addAction(GetStreamManifest::getName(), new GetStreamManifest());
        $this->addAction(GetSegment::getName(), new GetSegment());
        $this->addAction(GetSubtitleManifest::getName(), new GetSubtitleManifest());
        $this->addAction(GetSubtitleSegment::getName(), new GetSubtitleSegment());

        // Profiles
        $this->addAction(CreateProfile::getName(), new CreateProfile());
        $this->addAction(GetProfile::getName(), new GetProfile());
        $this->addAction(ListProfiles::getName(), new ListProfiles());
        $this->addAction(UpdateProfile::getName(), new UpdateProfile());
        $this->addAction(DeleteProfile::getName(), new DeleteProfile());
    }
}
