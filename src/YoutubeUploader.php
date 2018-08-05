<?php

namespace BahaaAlhagar\YoutubeUploader;

use Exception;
use Carbon\Carbon;
use Google_Client;
use Google_Service_YouTube;
use Illuminate\Support\Facades\DB;
use BahaaAlhagar\YoutubeUploader\Exceptions\NotDefinedPlaylistException;

class YoutubeUploader
{
    /**
     * Application Container
     *
     * @var Application
     */
    private $app;

    /**
     * Google Client
     *
     * @var \Google_Client
     */
    protected $client;

    /**
     * Google YouTube Service
     *
     * @var \Google_Service_YouTube
     */
    protected $youtube;

    /**
     * Video ID
     *
     * @var string
     */
    private $videoId;

    private $playlistId;

    /**
     * Video Snippet
     *
     * @var array
     */
    private $snippet;

    /**
     * Thumbnail URL
     *
     * @var string
     */
    private $thumbnailUrl;

    /**
     * Constructor
     *
     * @param \Google_Client $client
     */
    public function __construct($app, Google_Client $client)
    {
        $this->app = $app;

        $this->client = $this->setup($client);

        $this->youtube = new \Google_Service_YouTube($this->client);

        if ($accessToken = $this->getLatestAccessTokenFromDB()) {
            $this->client->setAccessToken($accessToken);
        }
    }

    /**
     * Upload the video to YouTube
     *
     * @param  string $path
     * @param  array $data
     * @param  string $privacyStatus
     * @return self
     * @throws Exception
     */
    public function upload($path, array $data = [], $privacyStatus = 'public')
    {
        if(!file_exists($path)) {
            throw new Exception('Video file does not exist at path: "'. $path .'". Provide a full path to the file before attempting to upload.');
        }

        $this->handleAccessToken();

        try {
            $video = $this->getVideo($data, $privacyStatus);

            // Set the Chunk Size
            $chunkSize = 1 * 1024 * 1024;

            // Set the defer to true
            $this->client->setDefer(true);

            // Build the request
            $insert = $this->youtube->videos->insert('status,snippet', $video);

            // Upload
            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $insert,
                'video/*',
                null,
                true,
                $chunkSize
            );

            // Set the Filesize
            $media->setFileSize(filesize($path));

            // Read the file and upload in chunks
            $status = false;
            $handle = fopen($path, "rb");

            while (!$status && !feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);

            // Set ID of the Uploaded Video
            $this->videoId = $status['id'];

            // Set the Snippet from Uploaded Video
            $this->snippet = $status['snippet'];

        }  catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Update the video on YouTube
     *
     * @param  string $id
     * @param  array $data
     * @param  string $privacyStatus
     * @return self
     * @throws Exception
     */
    public function update($id, array $data = [], $privacyStatus = 'public')
    {
        $this->handleAccessToken();

        if (!$this->exists($id)) {
            throw new Exception('A video matching id "'. $id .'" could not be found.');
        }

        try {
            $video = $this->getVideo($data, $privacyStatus, $id);

            $status = $this->youtube->videos->update('status,snippet', $video);

            // Set ID of the Updated Video
            $this->videoId = $status['id'];

            // Set the Snippet from Updated Video
            $this->snippet = $status['snippet'];
        }  catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Set a Custom Thumbnail for the Upload
     *
     * @param  string $imagePath
     * @return self
     * @throws Exception
     */
    public function withThumbnail($imagePath)
    {
        try {
            $videoId = $this->getVideoId();

            $chunkSizeBytes = 1 * 1024 * 1024;

            $this->client->setDefer(true);

            $setRequest = $this->youtube->thumbnails->set($videoId);

            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $setRequest,
                'image/png',
                null,
                true,
                $chunkSizeBytes
            );
            $media->setFileSize(filesize($imagePath));

            $status = false;
            $handle = fopen($imagePath, "rb");

            while (!$status && !feof($handle)) {
                $chunk  = fread($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);
            $this->thumbnailUrl = $status['items'][0]['default']['url'];

        } catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Delete a YouTube video by it's ID.
     *
     * @param  int $id
     * @return bool
     * @throws Exception
     */
    public function delete($id)
    {
        $this->handleAccessToken();

        if (!$this->exists($id)) {
            throw new Exception('A video matching id "'. $id .'" could not be found.');
        }

        return $this->youtube->videos->delete($id);
    }

    /**
     * [createPlaylist create youtube playlist]
     * @param  string $title         
     * @param  string $description   
     * @param  string $privacyStatus 
     * @return string                
     */
    public function createPlaylist($title, $description, $privacyStatus = 'public')
    {
        $this->handleAccessToken();

        try {
            // 1. Create the snippet for the playlist. Set its title and description.
            $playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
            $playlistSnippet->setTitle($title));
            $playlistSnippet->setDescription($description);


            // 2. Define the playlist's status.
            $playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
            $playlistStatus->setPrivacyStatus($privacyStatus);


            // 3. Define a playlist resource and associate the snippet and status
            // defined above with that resource.
            $youTubePlaylist = new \Google_Service_YouTube_Playlist();
            $youTubePlaylist->setSnippet($playlistSnippet);
            $youTubePlaylist->setStatus($playlistStatus);

            // 4. Call the playlists.insert method to create the playlist. The API
            // response will contain information about the new playlist.
            $playlistResponse = $this->youtube->playlists->insert('snippet,status',
                $youTubePlaylist, array());
            $this->playlistId = $playlistResponse['id'];

        } catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }
        
        return $this;
    }

    /**
     * [addVideoToPlaylist add Video to youtube playlist]
     * @param [string] $playlistId 
     * @param [string] $videoId    
     * @param [string] $title      
     */
    public function addVideoToPlaylist($playlistId = null, $videoId, $title = null)
    {
        $playlistId = $playlistId ? $playlistId : $this->$playlistId;

        if(!$playlistId){
            throw new NotDefinedPlaylistException("please provide playlist ID");
        }

        try {
            // Add a video to the playlist. First, define the resource being added
            // to the playlist by setting its video ID and kind.
            $resourceId = new \Google_Service_YouTube_ResourceId();
            $resourceId->setVideoId($playlistId);
            $resourceId->setKind('youtube#video');

            // Then define a snippet for the playlist item. Set the playlist item's
            // title if you want to display a different value than the title of the
            // video being added. Add the resource ID and the playlist ID retrieved
            $playlistItemSnippet = new \Google_Service_YouTube_PlaylistItemSnippet();
            $title ?: $playlistItemSnippet->setTitle($title);
            $playlistItemSnippet->setPlaylistId($playlistId);
            $playlistItemSnippet->setResourceId($resourceId);

            // Finally, create a playlistItem resource and add the snippet to the
            // resource, then call the playlistItems.insert method to add the playlist
            // item.
            $playlistItem = new \Google_Service_YouTube_PlaylistItem();
            $playlistItem->setSnippet($playlistItemSnippet);
            $playlistItemResponse = $this->youtube->playlistItems->insert(
                'snippet,contentDetails', $playlistItem, array());

        } catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        } 

        return $playlistItemResponse;
    }
    
    
    /**
     * @param $data
     * @param $privacyStatus
     * @param null $id
     * @return \Google_Service_YouTube_Video
     */
    private function getVideo($data, $privacyStatus, $id = null)
    {
        // Setup the Snippet
        $snippet = new \Google_Service_YouTube_VideoSnippet();

        if (array_key_exists('title', $data))       $snippet->setTitle($data['title']);
        if (array_key_exists('description', $data)) $snippet->setDescription($data['description']);
        if (array_key_exists('tags', $data))        $snippet->setTags($data['tags']);
        if (array_key_exists('category_id', $data)) $snippet->setCategoryId($data['category_id']);

        // Set the Privacy Status
        $status = new \Google_Service_YouTube_VideoStatus();
        $status->privacyStatus = $privacyStatus;

        // Set the Snippet & Status
        $video = new \Google_Service_YouTube_Video();
        if ($id)
        {
            $video->setId($id);
        }

        $video->setSnippet($snippet);
        $video->setStatus($status);

        return $video;
    }

    /**
     * Check if a YouTube video exists by it's ID.
     *
     * @param  int  $id
     *
     * @return bool
     */
    public function exists($id)
    {
        $this->handleAccessToken();

        $response = $this->youtube->videos->listVideos('status', ['id' => $id]);

        if (empty($response->items)) return false;

        return true;
    }

    /**
     * Return the Video ID
     *
     * @return string
     */
    public function getVideoId()
    {
        return $this->videoId;
    }

    /**
     * Return the snippet of the uploaded Video
     *
     * @return array
     */
    public function getSnippet()
    {
        return $this->snippet;
    }

    /**
     * Return the URL for the Custom Thumbnail
     *
     * @return string
     */
    public function getThumbnailUrl()
    {
        return $this->thumbnailUrl;
    }

    /**
     * Return the playlist ID
     *
     * @return string
     */
    public function getPlaylistId()
    {
        return $this->playlistId;
    }


    /**
     * Setup the Google Client
     *
     * @param Google_Client $client
     * @return Google_Client $client
     * @throws Exception
     */
    private function setup(Google_Client $client)
    {
        if(
            !$this->app->config->get('youtubeUploader.client_id') ||
            !$this->app->config->get('youtubeUploader.client_secret')
        ) {
            throw new Exception('A Google "client_id" and "client_secret" must be configured.');
        }

        $client->setClientId($this->app->config->get('youtubeUploader.client_id'));
        $client->setClientSecret($this->app->config->get('youtubeUploader.client_secret'));
        $client->setScopes($this->app->config->get('youtubeUploader.scopes'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $client->setRedirectUri(url(
            $this->app->config->get('youtubeUploader.routes.prefix')
            . '/' .
            $this->app->config->get('youtubeUploader.routes.redirect_uri')
        ));

        return $this->client = $client;
    }

    /**
     * Saves the access token to the database.
     *
     * @param  string  $accessToken
     */
    public function saveAccessTokenToDB($accessToken)
    {
        return DB::table('youtube_access_tokens')->insert([
            'access_token' => json_encode($accessToken),
            'created_at'   => Carbon::createFromTimestamp($accessToken['created'])
        ]);
    }

    /**
     * Get the latest access token from the database.
     *
     * @return string
     */
    public function getLatestAccessTokenFromDB()
    {
        $latest = DB::table('youtube_access_tokens')
                    ->latest('created_at')
                    ->first();

        return $latest ? (is_array($latest) ? $latest['access_token'] : $latest->access_token ) : null;
    }

    /**
     * Handle the Access Token
     *
     * @return void
     */
    public function handleAccessToken()
    {
        if (is_null($accessToken = $this->client->getAccessToken())) {
            throw new \Exception('An access token is required.');
        }

        if($this->client->isAccessTokenExpired())
        {
            // If we have a "refresh_token"
            if (array_key_exists('refresh_token', $accessToken))
            {
                // Refresh the access token
                $this->client->refreshToken($accessToken['refresh_token']);

                // Save the access token
                $this->saveAccessTokenToDB($this->client->getAccessToken());
            }
        }
    }

    /**
     * Pass method calls to the Google Client.
     *
     * @param  string  $method
     * @param  array   $args
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->client, $method], $args);
    }
}
