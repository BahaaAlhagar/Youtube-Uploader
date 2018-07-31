<?php

/**
 * Route URI's
 */
Route::group(['prefix' => config('youtube.routes.prefix')], function() {

    /**
     * Authentication
     */
    Route::get(config('youtube.routes.authentication_uri'), function()
    {
        return redirect()->to(YoutubeUploader::createAuthUrl());
    });

    /**
     * Redirect
     */
    Route::get(config('youtube.routes.redirect_uri'), function(Illuminate\Http\Request $request)
    {
        if(!$request->has('code')) {
            throw new Exception('$_GET[\'code\'] is not set. Please re-authenticate.');
        }

        $token = YoutubeUploader::authenticate($request->get('code'));

        YoutubeUploader::saveAccessTokenToDB($token);

        return redirect(config('youtube.routes.redirect_back_uri', '/'));
    });

});
