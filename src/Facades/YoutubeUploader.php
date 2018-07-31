<?php

namespace BahaaAlhagar\YoutubeUploader\Facades;

use Illuminate\Support\Facades\Facade;

class YoutubeUploader extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'youtubeUploader';
    }
}