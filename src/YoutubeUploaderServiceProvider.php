<?php

namespace BahaaAlhagar\YoutubeUploader;

use Illuminate\Support\ServiceProvider;

class YoutubeUploaderServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->app->singleton('youtubeUploader', function($app) {
            return new YoutubeUploader($app, new \Google_Client);
        });
    }
}