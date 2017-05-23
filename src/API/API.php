<?php
namespace AmaraPHP;

/**
 * Amara API component
 *
 * This component provides an object to perform common interactions
 * with Amara.org's API.
 *
 * @author Fran Ontanaya
 * @copyright 2017 Fran Ontanaya
 * @license GPLv3
 * @version 0.12.1
 *
 */
class API {
    const VERSION = '0.12.1';

    /**
     * Credentials
     *
     * APIVersion: key - value pair to add to the GET request, e.g. apifuture=>20161201
     *
     * @since 0.1.0
     */
    protected $host;
    protected $user;
    protected $APIKey;
    protected $APIVersion;

    /**
     * External dependencies
     *
     * @since 0.1.0
     */
    protected $logger;
    protected $cache;

    /**
     * Settings
     *
     * $limit: number of records per request. Keep low to avoid timeouts.
     *
     * @since 0.1.0
    */
    public $retries = 10;
    public $limit = 10;
    public $verboseCurl = false;

    /**
     * Initialization
     *
     * @since 0.1.0
     * @param $host
     * @param $user
     * @param $APIKey
     */
    function __construct($host, $user, $APIKey, $APIVersion = null, $logger = null) {
        $this->setAccount($host, $user, $APIKey);
        $this->setAPIVersion($APIVersion);
        $this->setLogger($logger);
    }

    /**
     * Change accounts
     *
     * @since 0.1.0
     * @param $host
     * @param $user
     * @param $APIKey
     * @throws \InvalidAPIAccount
     */
    function setAccount($host, $user, $APIKey, array $APIVersion = []) {
        $this->validateAccount($host, $user, $APIKey);
        if ($this->host !== $host && $this->APIKey === $APIKey) {
            $this->throwException(
                'InvalidAPISettings',
                __METHOD__,
                'Different API key when changing hosts',
                'API key should be the same when changing hosts'
            );
        } elseif ($this->APIKey !== $APIKey && $this->user === $user) {
            $this->throwException(
                'InvalidAPISettings',
                __METHOD__,
                'Different API key when changing usernames',
                'API key should be the same when changing usernames'
            );
        }
        $this->setAPIVersion($APIVersion);
        $this->user = $user;
        $this->APIKey = $APIKey;
        $this->host = $host;
    }


    /**
     * Set the API version as a key-value parameter for the resource URL
     *
     * @param array $APIVersion
     * @return bool
     */
    function setAPIVersion($APIVersion) {
        if (empty($APIVersion)) { return false; }
        if (count($APIVersion) > 1) {
            $this->throwException(
                'InvalidAPISettings',
                __METHOD__,
                'Too many elements passed for the API Version',
                'API Version should be one key-value pair or nothing'
            );
        }
        if (!is_string(array_keys($APIVersion))) {
            $this->throwException(
                'InvalidAPISettings',
                __METHOD__,
                'API Version key is not a string',
                'API Version key should be a string'
            );
        }
        $this->APIVersion = $APIVersion;
        return true;
    }

    /**
     * Set a valid PSR-3 logger
     *
     * @since 0.1.0
     * @param $logger
     * @return bool
     */
    function setLogger($logger) {
        if ($logger === null) { $this->logger = null; return false; }
        if (!$this->isValidObject($logger, array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'))) {
            $this->throwException(
                'InvalidLogger',
                __METHOD__,
                'Provided logger lacks PSR-3 methods',
                'Logger should be PSR-3'
            );
        }
        $this->logger = $logger;
        return true;
    }

    // cURL methods
    
    /**
     * Generates headers needed by Amara's API
     *
     * @since 0.1.0
     * @param null $ct
     * @return array
     * @throws \InvalidAPIAccount
     */
    function getHeader($ct = null) {
        assert($this->validateAccount($this->host, $this->user, $this->APIKey));
        if (!is_string($ct) && !is_null($ct)) {
            $this->throwException(
                'InvalidAPIAccount',
                __METHOD__,
                'string or null',
                gettype($ct)
            );
        }
        $r = array(
            "X-api-username: {$this->user}",
            "X-APIKey: {$this->APIKey}"
        );
        if ($ct === 'json') { $r = array_merge($r, array(
            'Content-Type: application/json',
            'Accept: application/json'
        )); }
        return $r;
    }

    /**
     * Route to the right API URL to perform an action.
     *
     * 'resource' indicates which API resource is the target.
     * $q should be an associative array matching the required query parameters.
     *
     * @since 0.1.0
     * @param array $r
     * @param array $q
     * @return null|string
     */
    function getResourceUrl(array $r, $q = array()) {
        foreach($r as $key=>$value) {
            $r[$key] = urlencode($value);
        }
        $url = '';
        switch ($r['resource']) {
            case 'activities':
                $url = "{$this->host}activity/";
                break;
            case 'activity':
                $url = "{$this->host}activity/{$r['activity_id']}/";
                break;
            case 'videos':
                $url = "{$this->host}videos/";
                break;
            case 'video':
                $url = "{$this->host}videos/{$r['video_id']}/";
                break;
            case 'video_urls':
                $url = "{$this->host}videos/{$r['video_id']}/urls/";
                break;
            case 'video_url':
                $url = "{$this->host}videos/{$r['video_id']}/urls/{$r['url_id']}/";
                break;
            case 'languages':
                $url = "{$this->host}videos/{$r['video_id']}/languages/";
                break;
            case 'language':
                $url = "{$this->host}videos/{$r['video_id']}/languages/{$r['language']}/";
                break;
            case 'subtitles':
                $url = "{$this->host}videos/{$r['video_id']}/languages/{$r['language']}/subtitles/";
                break;
            case 'tasks':
                $url = "{$this->host}teams/{$r['team']}/tasks/";
                break;
            case 'task':
                $url = "{$this->host}teams/{$r['team']}/tasks/{$r['task_id']}/";
                break;
            case 'members':
                $url = "{$this->host}teams/{$r['team']}/members/";
                break;
            case 'safe_members':
                $url = "{$this->host}teams/{$r['team']}/safe-members/";
                break;
            case 'member':
                $url = "{$this->host}teams/{$r['team']}/members/{$r['user']}/";
                break;
            case 'users':
                $url = "{$this->host}users/{$r['user']}/";
                break;
            case 'applications':
                $url = "{$this->host}teams/{$r['team']}/applications/";
                break;
            case 'projects':
                $url = "{$this->host}teams/{$r['team']}/projects/";
                break;
            case 'project':
                $url = "{$this->host}teams/{$r['team']}/projects/{$r['project']}/";
                break;
            case 'subtitle_requests':
                $url = "{$this->host}teams/{$r['team']}/subtitle-requests/";
                break;
            case 'subtitle_request':
                $url = "{$this->host}teams/{$r['team']}/subtitle-requests/{$r['job_id']}/";
                break;
            default:
                return null;
        }
        if (isset($q) && !empty($q)) {
            $url .= '?' . http_build_query($q);
        }
        return $url;
    }

    /**
     * cURL request
     *
     * Perform all HTTP methods.
     *
     * @since 0.1.0
     * @param $mode
     * @param $header
     * @param $url
     * @param string $data
     * @return mixed|null
     */
    protected function curl($mode, $header, $url, $data = '') {
        $cr = curl_init();
        curl_setopt($cr, CURLOPT_URL, $url);
        curl_setopt($cr, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cr, CURLOPT_VERBOSE, $this->verboseCurl);
        curl_setopt($cr, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cr, CURLOPT_HTTPHEADER, $header);
        switch ($mode) {
            case 'GET':
                break;
            case 'POST':
        		curl_setopt( $cr, CURLOPT_POST, 1 ); // Needed when creating a new language
                //curl_setopt($cr, CURLOPT_CUSTOMREQUEST, $mode);
                curl_setopt($cr, CURLOPT_POSTFIELDS, $data);
                break;
            case 'PUT':
                curl_setopt($cr, CURLOPT_CUSTOMREQUEST, $mode);
                curl_setopt($cr, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
            	curl_setopt($cr, CURLOPT_CUSTOMREQUEST, "DELETE");
            	break;
            default:
                return null;
        }
        $result = $this->curlTry($cr);
        curl_close($cr);
        return $result;
    }

    /**
     * cURL retry loop
     *
     * Exhaust all retries for HTTP actions.
     *
     * @since 0.1.0
     * @param $cr
     * @return mixed|null
     */
    protected function curlTry($cr) {
        $retries = 0;
        do {
            $result = curl_exec($cr);
            $retries++; if ($retries > $this->retries) { return null; }
        } while($result === false);
        return $result;
    }

    /**
     * Fetch all required data from a resource
     *
     * Some Amara resources are paginated with an offset and limit.
     * They return data as an array of objects in $response->objects.
     *
     * If the response is not valid JSON, it's returned as-is (e.g. a subtitle track);
     * if it's JSON, but doesn't have an objects array, it's decoded and returned immediately.
     *
     * @param $method
     * @param array $r
     * @param null $q
     * @param null $data
     * @return array|mixed
     */
    protected function useResource($method, array $r, $q = null, $data = null, callable $filter = null) {
        $result = array();
        $limit = (isset($q['limit']) ? $q['limit'] : $this->limit);
        $header = $this->getHeader(isset($r['content_type']) ? $r['content_type'] : null);
        if (isset($data) && $r['content_type'] === 'json') { $data = json_encode($data); }
        do {
            $url = $this->getResourceUrl($r, $q);
            $response = $this->curl($method, $header, $url, $data);
            $resultChunk = json_decode($response);
            if (json_last_error() != JSON_ERROR_NONE) {
                // It's not JSON, just deliver as-is.
                return $response;
            }
            if ($method !== 'GET' || !isset($resultChunk->objects)) {
                // Nothing to loop, deliver JSON or error.
                return $resultChunk;
            }
            if (!is_array($resultChunk->objects)) {
                throw new \UnexpectedValueException('\'objects\' property is not an array');
            }
            // Use a callable function to filter the results
            // The callable may set ->meta->next to null if the fetching loop
            // should end early
            if ($filter !== null) {
                $resultChunk = call_user_func($filter, $resultChunk);
            }
            $result = array_merge($result, $resultChunk->objects);
            if ($resultChunk->meta->next === null) {
                break;
            }
            if (!isset($q['offset'])) {
                $q['offset'] = 0;
            }
            $q['offset'] += $limit;
        } while($resultChunk->meta->offset + $limit < $resultChunk->meta->total_count);
        return $result;
    }

    /**
     * Call an API point that returns a resource
     * 
     * @param array $r
     * @param null $q
     * @param null $data
     * @return array|mixed
     */
    protected function getResource(array $r, $q = null, $data = null, callable $filter = null) {
        return $this->useResource('GET', $r, $q, $data, $filter);
    }

    /**
     * Call an API point that creates a resource
     * 
     * @param array $r
     * @param null $q
     * @param null $data
     * @return array|mixed
     */
    public function createResource(array $r, $q = null, $data = null) {
        return $this->useResource('POST', $r, $q, $data);
    }

    /**
     * Call an API point that updates a resource     
     * 
     * @param array $r
     * @param null $q
     * @param null $data
     * @return array|mixed
     */
    protected function setResource(array $r, $q = null, $data = null) {
        return $this->useResource('PUT', $r, $q, $data);
    }

    /**
     * Call an API point that deletes a resource
     * 
     * @param array $r
     * @param null $q
     * @param null $data
     * @return array|mixed
     */
    protected function deleteResource(array $r, $q = null, $data = null) {
        return $this->useResource('DELETE', $r, $q, $data);
    }

    // SUBTITLE LANGUAGE RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#subtitle-language-resource
    
    /**
     * Listing video languages
     *
     * @since 0.4.0
     * @param array $r
     * @return array|mixed|null
     */
    function getVideoLanguages(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'languages',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
       );
        return $this->getResource($res);
    }

    /**
     * Get information about a subtitle track in the specified language
     *
     * Notice the elements required in the resource definition:
     *
     * 'resource' - the main type of resource
     * 'content_type' - the type of content expected (usually json)
     * other resource slugs as seen in the resource URL, e.g. language
     * not to confuse them with the query parameters themselves
     *
     * @since 0.4.0
     * @param array $r
     * @return array|mixed|null
     */
    function getVideoLanguage(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'language',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'language' => $r['language_code']
       );
        return $this->getResource($res);
    }

    /**
     * Get the last version number for a language
     *
     * Note that the versions array starts at 0, but
     * the version numbering starts at 1.
     *
     * Versions are in reverse order, versions[0] is always the latest one.
     * Because versions can be deleted, the version_no can't be used
     * as index of the versions array.
     *
     * @since 0.1.0
     * @param $lang_info
     * @return null
     * @throws \UnknownException
     */
    function getLastVersion($lang_info) {
        if (!is_object($lang_info)) {
            $this->throwException(
                'InvalidArgumentType',
                __METHOD__,
                'object',
                gettype($lang_info)
            );
        }
        if (isset($lang_info->versions[0]->version_no)) {
            return $lang_info->versions[0]->version_no;
        } else {
            return null;
        }
    }

    // VIDEO RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#video-resource
    
    /**
     * Get information about all videos in a team/project
     *
     * Note that this can take a long time on teams/projects
     * with many videos.
     *
     * You can pass a callable function as $r['filter'] to perform an operation
     * during the loop. For example, set ->meta->next to null if a certain creation
     * date has been reached.
     *
     * Use $params['offset'] and your own loop
     * if you'd rather not wait for this method to finish.
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed
     */
    function getVideos(array $r) {
        $res = array(
            'resource' => 'videos',
            'content_type' => 'json',
        );
        $query = array(
            'team' => isset($r['team']) ? $r['team'] : null,
            'project' => isset($r['project']) ? $r['project'] : null,
            'order_by' => isset($r['order_by']) ? $r['order_by'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
        );
        $filter = null;
        if (isset($r['filter'])) {
            if (!is_callable($r['filter'])) {
                throw new \UnexpectedValueException('The \'filter\' argument is not a callable function.');
            }
            $filter = $r['filter'];
        };
        return $this->getResource($res, $query, null, $filter);
    }

    /**
     * Retrieve metadata info about a video
     *
     * The same info can be retrieved by video id or by video url,
     * since each video url is associated to a unique video id.
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed|null
     */
    function getVideoInfo(array $r) {
        $query = array();
        if (isset($r['video_id'])) {
            if (!$this->isValidVideoID($r['video_id'])) { return null; }
            $res = array(
                'resource' => 'video',
                'content_type' => 'json',
                'video_id' => $r['video_id']
           );
        } elseif (isset($r['video_url']) && $r['video_url'] !== null) {
            $res = array(
                'resource' => 'videos',
                'content_type' => 'json'
           );
            $query = array(
                'video_url' => isset($r['video_url']) ? $r['video_url'] : null
           );
        }
        return $this->getResource($res, $query);
    }

    /**
     * Adds a new video with a given URL
     *
     * Requires to specify a team since this component is mostly used
     * for team videos that shouldn't get posted publicly.
     *
     * @since 0.5.0
     * @param array $r
     * @return array|mixed
     */
    function createVideo(array $r) {
        $query = array();
        $data = array();
        if (!isset($r['video_url'], $r['primary_audio_language_code'], $r['team'])) {
            throw new \InvalidArgumentException("Missing arguments");
        }
        $res = array(
                'resource' => 'videos',
                'content_type' => 'json'
            );
        $query = array();
        $data = array(
                'team' => $r['team'],
                'video_url' => $r['video_url'],
        );
        if (isset($r['title'])) { $data['title'] = $r['title']; }
        if (isset($r['description'])) { $data['description'] = $r['description']; }
        if (isset($r['duration'])) { $data['duration'] = $r['duration']; }
        if (isset($r['primary_audio_language_code'])) { $data['primary_audio_language_code'] = $r['primary_audio_language_code']; }
        if (isset($r['metadata'])) { $data['metadata'] = $r['metadata']; }
        if (isset($r['project'])) { $data['project'] = $r['project']; }
        return $this->createResource($res, $query, $data);
    }

    /**
     * Update an existing video 
     * 
     * @param array $r
     * @return array|mixed|null
     * @since 0.11.0
     */
    function updateVideo(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'video',
            'content_type' => 'json',
            'video_id' => $r['video_id']
        );
        $query = array();
        $data = array();
        if (isset($r['project']) && !isset($r['team'])) {
            throw new \InvalidArgumentException("Can't specify project without specifying team");
        }
        if (isset($r['team'])) { $data['team'] = $r['team']; }
        if (isset($r['project'])) { $data['project'] = $r['project']; }
        if (isset($r['video_url'])) { $data['video_url'] = $r['video_url']; }
        if (isset($r['title'])) { $data['title'] = $r['title']; }
        if (isset($r['description'])) { $data['description'] = $r['description']; }
        if (isset($r['duration'])) { $data['duration'] = $r['duration']; }
        if (isset($r['primary_audio_language_code'])) { $data['primary_audio_language_code'] = $r['primary_audio_language_code']; }
        if (isset($r['metadata'])) { $data['metadata'] = $r['metadata']; }
        return $this->setResource($res, $query, $data);
    }

    /**
     * Move a video into a different team/project
     * Deprecated - use updateVideo
     *
     * https://amara.readthedocs.io/en/latest/api.html#moving-videos-between-teams-and-projects
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed|null
     * @deprecated
     */
    function moveVideo(array $r) {
        $res = array(
            'team' => $r['team'],
            'video_id' => $r['video_id'],
        );
        if (isset($r['project'])) { $res['project'] = $r['project']; }
        return $this->updateVideo($res);
    }

    /**
     * Change the video's main title
     *
     * https://amara.readthedocs.io/en/latest/api.html#put--api2-partners-videos-[video-id]-
     * Deprecated - use updateVideo
     *
     * @since 0.4.2
     * @param array $r
     * @return array|mixed|null
     * @deprecated
     */
    function renameVideo(array $r) {
        $res = array();
        if (isset($r['title'])) { $res['title'] = $r['title']; }
        if (isset($r['description'])) { $res['description'] = $r['description']; }
        return $this->updateVideo($res);
    }

    /**
     * Delete an existing video
     *
     * @param array $r
     * @return array|mixed|null
     * @since 0.12.0
     */
    function deleteVideo(array $r) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        $res = array(
            'resource' => 'video',
            'content_type' => 'json',
            'team' => $r['team'],
            'video_id' => $r['video_id'],
        );
        $query = array();
        $data = array();
        if (isset($r['project']) && !isset($r['team'])) {
            throw new \InvalidArgumentException("Can't specify project without specifying team");
        }
        return $this->deleteResource($res, $query, $data);
    }

    // VIDEO URL RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#video-url-resource

    /**
     * Get all URLs for a given video ID
     *
     * @since 0.10.0
     * @param array $r
     * @return array|mixed
     */
    function getVideoURLs(array $r = array()) {
        $res = array(
            'resource' => 'video_urls',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
        );
        $query = array();
        return $this->getResource($res, $query);
    }

    /**
     * Get details about a specific URL
     *
     * url_id is provided by resource_uri from getVideoURLs
     *
     * @since 0.10.0
     * @param array $r
     * @return array|mixed
     */
    function getVideoURL(array $r = array()) {
        $res = array(
            'resource' => 'video_url',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'url_id' => $r['url_id']
        );
        $query = array();
        return $this->getResource($res, $query);
    }

    /**
     * Adds a new URL for a given video ID
     *
     * @since 0.10.0
     * @param array $r
     * @return array|mixed
     */
    function addVideoURL(array $r = array()) {
        $res = array(
            'resource' => 'video_urls',
            'content_type' => 'json',
            'video_id' => $r['video_id']
        );
        $query = array();
        $data = array(
            'url' => $r['url'],
            'primary' => $r['primary'],
            'original' => $r['original']
        );
        return $this->createResource($res, $query, $data);
    }

    /**
     * Modify the Primary flag for a given video URL
     *
     * url_id is provided by resource_uri from getVideoURLs
     *
     * @since 0.10.0
     * @param array $r
     * @return array|mixed
     */
    function setVideoURL(array $r = array()) {
        $res = array(
            'resource' => 'video_url',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'url_id' => $r['url_id']
        );
        $query = array();
        $data = array(
            'primary' => $r['primary']
        );
        return $this->setResource($res, $query, $data);
    }

    /**
     * Delete a given video URL
     *
     * url_id is provided by resource_uri from getVideoURLs
     *
     * @since 0.10.0
     * @param array $r
     * @return array|mixed
     */
    function deleteVideoURL(array $r = array()) {
        $res = array(
            'resource' => 'video_url',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'url_id' => $r['url_id']
        );
        $query = array();
        $data = array();
        return $this->deleteResource($res, $query, $data);
    }

    // PROJECTS RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#projects-resource
    
    /**
     * Get all the projects in a team
     *
     * @param array $r
     * @since 0.7.0
     */
    function getProjects(array $r = array()) {
        $res = array(
            'resource' => 'projects',
            'team' => $r['team'],
            'content_type' => 'json'
        );
        $query = array();
        return $this->getResource($res, $query);
    }

    /**
     * Get details on a project
     *
     * @param array $r
     * @since 0.7.0
     */
    function getProject(array $r = array()) {
        $res = array(
            'resource' => 'projects',
            'team' => $r['team'],
            'project' => $r['project'],
            'content_type' => 'json'
        );
        $query = array();
        return $this->getResource($res, $query);
    }

    /**
     * Create a new project
     *
     * @param array $r
     * @since 0.7.0
     */
    function createProject(array $r = array()) {
        $res = array(
            'resource' => 'projects',
            'team' => $r['team'],
            'content_type' => 'json'
        );
        $query = array();
        $data = array(
            'name' => $r['name'],
            'slug' => $r['slug'],
            'description' => isset($r['description']) ?: null,
            'guidelines' => isset($r['guidelines']) ?: null
        );
        return $this->createResource($res, $query, $data);
    }

    // ACTIVITY RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#activity-resource

    /**
     * Retrieve a set of activity data
     *
     * Make sure you specify either $team or $video_id
     * otherwise you'll query public activity from the whole site, which is
     * a heavy request.
     * See https://amara.readthedocs.io/en/latest/api.html#activity-resource
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed
     */
    function getActivities(array $r = array()) {
        $res = array(
            'resource' => 'activities',
            'content_type' => 'json'
       );
        $query = array(
            'team' => isset($r['team']) ? $r['team'] : null,
            'video' => isset($r['video_id']) ? $r['video_id'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
            'language' => isset($r['language']) ? $r['language'] : null,
            'before' => isset($r['before']) ? $r['before'] : null,
            'after' => isset($r['after']) ? $r['after'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Retrieve a singe activity record
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed
     */
    function getActivity(array $r) {
        $res = array(
            'resource' => 'activity',
            'content_type' => 'json',
            'activity_id' => $r['activity_id']
       );
        return $this->getResource($res);
    }

    // TASK RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#task-resource

    /**
     * Retrieve a set of task records
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed
     */
    function getTasks(array $r) {
        $res = array(
            'resource' => 'tasks',
            'content_type' => 'json',
            'team' => $r['team'],
       );
        $query = array(
            'video_id' => isset($r['video_id']) ? $r['video_id'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
            'assignee' => isset($r['assignee']) ? $r['assignee'] : null,
            'priority' => isset($r['priority']) ? $r['priority'] : null,
            'order_by' => isset($r['order_by']) ? $r['order_by'] : null,
            'completed' => isset($r['completed']) ? $r['completed'] : null,
            'completed_before' => isset($r['completed_before']) ? $r['completed_before'] : null,
            'open' => isset($r['open']) ? $r['open'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Retrieve a singe task record
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed
     */
    function getTaskInfo(array $r) {
        $res = array(
            'resource' => 'task',
            'content_type' => 'json',
            'team' => $r['team'],
            'task_id' => $r['task_id']
       );
        return $this->getResource($res);
    }

    /**
     * Create a new task
     *
     * You can pass the data from getVideoLanguage if you
     * retrieved it earlier, so this doesn't make a new
     * request.
     *
     * @since 0.1.0
     * @param array $r
     * @param null $lang_info
     * @return array|mixed|null
     */
    function createTask(array $r, &$lang_info = null) {
        if (!is_object($lang_info)) { $lang_info = null; }
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        if (!in_array($r['type'], array('Subtitle', 'Translate', 'Review', 'Approve'))) { return null; }
        if (!isset($r['version_no']) && in_array($r['type'], array('Review', 'Approve'))) {
            if ($lang_info === null) { $lang_info = $this->getVideoLanguage(array('video_id' => $r['video_id'], 'language_code' => $r['language_code'])); }
            $r['version_no'] = $this->getLastVersion($lang_info);
        }
        $res = array(
            'resource' => 'tasks',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array(
            'video_id' => isset($r['video_id']) ? $r['video_id'] : null,
            'language' => isset($r['language_code']) ? $r['language_code'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
            'assignee' => isset($r['assignee']) ? $r['assignee'] : null,
            'priority' => isset($r['priority']) ? $r['priority'] : null,
            'completed' => isset($r['completed']) ? $r['completed'] : null,
            'approved' => isset($r['approved']) ? $r['approved'] : null,
            'version_no' => isset($r['version_no']) ? $r['version_no'] : null
       );
        return $this->createResource($res, $query);
    }

    /**
     * Delete a task
     *
     * @since 0.1.0
     * @param array $r
     * @return array|mixed
     */
    function deleteTask(array $r) {
        $res = array(
            'resource' => 'task',
            'content_type' => 'json',
            'team' => $r['team'],
            'task_id' => $r['task_id']
       );
        return $this->deleteResource($res);
    }

    // SUBTITLE REQUEST RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#subtitle-request-resource
    
    /**
     * List requests
     *
     * List open collaboration requests. The language filter refers to the collaboration language,
     * as opposed to the video's primary language.
     *
     * Set type to 'outgoing' to fetch requests with work on a different team.
     * They aren't included by default
     *
     * @param array $r
     * @return array|mixed
     * @since 0.8.0
     */
    function getRequests(array $r) {
        $res = array(
            'resource' => 'subtitle_requests',
            'content_type' => 'json',
            'team' => $r['team']
        );
        $query = array(
            'state' => isset($r['state']) ? $r['state'] : null,
            'video' => isset($r['video_id']) ? $r['video_id'] : null,
            'language' => isset($r['language_code']) ? $r['language_code'] : null,
            'video_language' => isset($r['video_language']) ? $r['video_language'] : null,
            'project' => isset($r['project']) ? $r['project'] : null,
            'assignee' => isset($r['assignee']) ? $r['assignee'] : null,
            'type' => isset($r['type']) ? $r['type'] : null,
        );
        return $this->getResource($res, $query);
    }

    /**
     * Get details about a collaboration request
     *
     * @param array $r
     * @return array|mixed
     * @since 0.8.0
     */
    function getRequestInfo(array $r) {
        $res = array(
            'resource' => 'subtitle_request',
            'content_type' => 'json',
            'team' => $r['team'],
            'job_id' => $r['job_id']
        );
        $query = array();
        return $this->getResource($res, $query);
    }

    /**
     * Create a collaboration request
     *
     * @param array $r
     * @return array|mixed
     * @since 0.8.0
     */
    function createRequest(array $r) {
        $res = array(
            'resource' => 'subtitle_requests',
            'content_type' => 'json'
        );
        $query = array();
        $data = array(
            'video' => $r['video_id'],
            'language' => $r['language_code'],
            'team' => $r['team']
        );
        if (isset($r['evaluation_teams'])) { $data['evaluation_teams'] = $r['evaluation_teams']; }
        return $this->createResource($res, $query, $data);
    }

    /**
     * Update a collaboration request
     *
     * Note that if subtitler, reviewer and approver are null they will be unassigned.
     * To skip modifying them, the keys need to be unset in the $data, rather than null
     * hence the conditional assignments.
     *
     * @param array $r
     * @return mixed
     * @since 0.8.0
     */
    function updateRequest(array $r) {
        $res = array(
            'resource' => 'subtitle_request',
            'team' => $r['team'],
            'job_id' => $r['job_id'],
            'content_type' => 'json'
        );
        $query = array();
        $data = array();
        if (isset($r['subtitler'])) { $data['subtitler'] = $r['subtitler']; }
        if (isset($r['reviewer'])) { $data['reviewer'] = $r['reviewer']; }
        if (isset($r['approver'])) { $data['approver'] = $r['approver']; }
        if (isset($r['state'])) { $data['state'] = $r['state']; }
        return $this->setResource($res, $query, $data);
    }

    // SUBTITLES RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#subtitles-resource

    /**
     * Fetch the subtitle track
     *
     * Specifying the version is needed to retrieve unpublished subtitles
     * You may pass $lang_info if you retrieved it previously, or any
     * variable to store it afterwards
     *
     * If you don't specify the format, you'll get Amara's internal
     * subtitle object. You can use it in your code instead of
     * passing one of the formats through a parser.
     *
     * @since 0.1.0
     * @param array $r
     * @param null $lang_info
     * @return array|mixed|null
     */
    function getSubtitle(array $r, &$lang_info = null) {
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        if (!isset($r['version'])) {
            if ($lang_info === null) {
                $lang_info = $this->getVideoLanguage(array(
                    'video_id' => $r['video_id'],
                    'language_code' => $r['language_code']
               ));
            }
            $r['version'] = $this->getLastVersion($lang_info);
        }
        if ($r['version'] === null) { return null; }
        $res = array(
            'resource' => 'subtitles',
            'video_id' => $r['video_id'],
            'language' => $r['language_code'],
       );
        $query = array(
            'format' => isset($r['format']) ? $r['format'] : 0,
            'version' => isset($r['version']) ? $r['version'] : 0
       );
       return $this->getResource($res, $query);
    }

    /**
     * Upload a subtitle track
     *
     * In theory this should be a createResource action,
     * but currently it works with PUT rather than POST
     *
     * You may want to fetch first and preserve here the
     * subtitles_complete/is_complete status.
     *
     * Note that sub_format defaults to SRT.
     *
     * @since 0.1.0
     * @param array $r
     * @param null $lang_info
     * @return array|mixed|null
     */
    function uploadSubtitle(array $r, &$lang_info = null) {
        // Create the language if it doesn't exist
        if (!$this->isValidVideoID($r['video_id'])) { return null; }
        if (!$lang_info) { $lang_info = $this->getVideoLanguage(array('video_id' => $r['video_id'], 'language_code' => $r['language_code'])); }
        if (!is_object($lang_info)) {
            $res = array(
                'resource' => 'languages',
                'content_type' => 'json',
                'video_id' => $r['video_id']
            );
            $query = array();
            $data = array(
                'language_code' => $r['language_code']
            );
            $this->createResource($res, $query, $data);
            $lang_info = $this->getVideoLanguage(array('video_id' => $r['video_id'], 'language_code' => $r['language_code']));
        }
        $res = array(
            'resource' => 'subtitles',
            'content_type' => 'json',
            'video_id' => $r['video_id'],
            'language' => $r['language_code'],
        );
        $query = array();
        $data = array(
            'subtitles' => isset($r['subtitles']) ? $r['subtitles'] : null,
            'sub_format' => isset($r['sub_format']) ? $r['sub_format'] : null,
            'title' => isset($r['title']) ? $r['title'] : $lang_info->title,
            'description' => isset($r['description']) ? $r['description'] : $lang_info->description,
            'is_complete' => isset($r['complete']) ? $r['complete'] : null
        );
        return $this->createResource($res, $query, $data);
    }

    // TEAM MEMBER RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#team-member-resource

    /**
     * Get the list of members in a team
     *
     * @since 0.2.0
     * @param array $r
     * @return array|mixed
     */
    function getMembers(array $r) {
        $res = array(
            'resource' => 'members',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array(
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0
       );
        return $this->getResource($res, $query);
    }

    /**
     * Add a new partner member to a partner team
     *
     * This is the "unsafe" method that allows to transfer
     * users directly between "partner" teams without notifying/inviting them.
     *
     * It won't work if the user is not in a "partner" team,
     * or the destination teams isn't set as a "partner" team.
     * This is configured by Amara's admins.
     *
     * @since 0.2.0
     * @param array $r
     * @return array|mixed
     */
    function addPartnerMember(array $r) {
        $res = array(
            'resource' => 'members',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array(
       );
        $data = array(
            'user' => $r['user'],
            'role' => $r['role']
       );
        return $this->createResource($res, $query, $data);
    }

    /**
     * Invite a user to a team
     *
     * This is the safe method. It will send the user an invitation
     * to join the team, which the user can refuse.
     *
     * @since 0.2.0
     * @param array $r
     * @return array|mixed
     */
    function addMember(array $r) {
        $res = array(
            'resource' => 'safe_members',
            'content_type' => 'json',
            'team' => $r['team']
       );
        $query = array();
        $data = array(
            'user' => $r['user'],
            'role' => $r['role']
       );
        return $this->createResource($res, $query, $data);
    }

    /**
     * Update team member
     *
     * @since 0.11.0
     * @param array $r
     * @return array|mixed
     */
    function updateMember(array $r) {
        $res = array(
            'resource' => 'member',
            'content_type' => 'json',
            'team' => $r['team'],
            'user' => $r['user'],
        );
        $query = array();
        $data = array(
            'role' => $r['role']
        );
        return $this->setResource($res, $query, $data);
    }

    /**
     * Remove a member from a team
     *
     * @since 0.2.0
     * @param array $r
     * @return array|mixed
     */
    function deleteMember(array $r) {
        $res = array(
            'resource' => 'member',
            'content_type' => 'json',
            'team' => $r['team'],
            'user' => $r['user']
       );
        return $this->deleteResource($res);
    }

    // USER RESOURCE
    // https://amara.readthedocs.io/en/latest/api.html#user-resource

    /**
     * Get user detail
     *
     * @since 0.2.0
     * @param array $r
     * @return array|mixed
     */
    function getUser(array $r) {
        $res = array(
            'resource' => 'users',
            'content_type' => 'json',
            'user' => $r['user']
       );
        return $this->getResource($res);
    }

    /**
     * Returns an array of user objects for the given list of users
     *
     * @since 0.3.0
     * @param array $users
     * @return array|null
     */
    function getUsers(array $users) {
        if (empty($users)) { return null; }
        $result = array();
        for ($i = 0; $i < count($users); $i++) {
            $res = array(
                'resource' => 'users',
                'content_type' => 'json',
                'user' => $users[$i]
           );
            $user = $this->getResource($res);
            if (!is_object($user)) {
                continue;
            }
            $result[$i] = $user;
        }
        return $result;
    }

    // TEAM APPLICATIONS
    // http://amara.readthedocs.io/en/old-api-docs/api.html#team-applications-resource

    /**
     * Get information about all applications in a team
     *
     * @since 0.6.0
     * @param array $r
     * @return array|mixed
     */

    function getApplications(array $r) {
        $res = array(
            'resource' => 'applications',
            'content_type' => 'json',
            'team' => $r['team'],
        );
        $query = array(
            'status' => isset($r['status']) ? $r['status'] : null,
            'before' => isset($r['before']) ? $r['before'] : null,
            'after' => isset($r['after']) ? $r['after'] : null,
            'user' => isset($r['user']) ? $r['user'] : null,
            'limit' => isset($r['limit']) ? $r['limit'] : $this->limit,
            'offset' => isset($r['offset']) ? $r['offset'] : 0,
        );
        return $this->getResource($res, $query);
    }

    // VALIDATION

    /**
     * Validate API keys
     *
     * Note that we wouldn't want to perform requests to validate the account
     * until we have some assurance the host is an Amara install,
     * otherwise you could leak the credentials to somewhere unexpected.
     *
     * @since 0.1.0
     * @param $host
     * @param $user
     * @param $APIKey
     * @return bool
     */
    function validateAccount($host, $user, $APIKey) {
        if (strlen($APIKey) !== 40) {
            throw new \LengthException('The API key is not 40 characters long');
        } elseif (preg_match('/^[0-9a-f]*$/', $APIKey) !== 1) {
            throw new \InvalidArgumentException('The API key should contain lowercase hexadecimal characters only');
        }
        return true;
    }

    /**
     * Validate an username
     *
     * @since 0.2.0
     * @param $r
     * @return array|mixed
     */
    function isValidUser($r) {
        return $this->getUser(array('username' => $r));
    }

    /**
     * Check if an object has the expected methods
     *
     * @since 0.1.0
     * @param object $object
     * @param array $valid_methods
     * @return bool
     */
    function isValidObject(object $object, array $validMethods) {
        $objMethods = get_class_methods($object);
        if (count(array_intersect($validMethods, $objMethods)) === count($validMethods)) {
            return true;
        }
        return false;
    }

    /**
     * Check if a string looks like a valid video ID
     *
     * @since 0.1.0
     * @param $video_id
     * @return bool
     */
    function isValidVideoID($videoId) {
        if (strlen($videoId) !== 12) {
            return false;
        } elseif (preg_match('/^[A-Za-z0-9]*$/', $videoId) !== 1) {
            return false;
        }
        return true;
    }

    /**
     * Check if a variable is a valid role string
     *
     * @since 0.2.0
     * @param $role
     * @return bool
     */
    function isValidRole($role) {
        if (!isset($role) || !is_string($role)) { return false; }
        return in_array($role, array('admin', 'manager', 'owner', 'contributor'));
    }

    /**
     * Check if a task name is valid
     *
     * @param $taskName
     * @return bool
     */
    function isValidTaskName($taskName) {
        if (!isset($taskName) || !is_string($taskName)) { return false; }
        return in_array($taskName, array('Subtitle', 'Translate', 'Review', 'Approve'));
    }


    /**
     * Check if a string is a valid language code
     *
     * @since 0.3.0
     * @param $languageCode
     * @return bool
     */
    function isValidLanguageCode($languageCode) {
	    $amaraLanguages = array("aa", "ab", "ae", "af", "aka", "amh", "an", "arc", "ar", "arq", "ase", "as", "ast", "av", "ay", "az", "bam", "ba", "be", "ber", "bg", "bh", "bi", "bn", "bnt", "bo", "br", "bs", "bug", "cak", "ca", "ceb", "ce", "ch", "cho", "cku", "co", "cr", "cs", "ctd", "ctu", "cu", "cu", "cv", "cy", "da", "de", "dv", "dz", "ee", "efi", "el", "en-gb", "en", "eo", "es-ar", "es-mx", "es", "es-ni", "et", "eu", "fa", "ff", "fil", "fi", "fj", "fo", "fr-ca", "fr", "fy", "fy-nl", "ga", "gd", "gl", "gn", "gu", "gv", "hai", "hau", "haw", "haz", "hus", "hb", "hch", "he", "hi", "ho", "hr", "ht", "hu", "hup", "hy", "hz", "ia", "ibo", "id", "ie", "ig", "ii", "ik", "ilo", "inh", "io", "iro", "is", "it", "iu", "ja", "jv", "ka", "kar", "kau", "kg", "kik", "ki", "kin", "kj", "kk", "kl", "km", "kn", "ko", "kon", "kr", "ksh", "ks", "ku", "kv", "kw", "ky", "la", "lb", "lg", "lg", "li", "lin", "lkt", "lld", "ln", "lo", "lt", "ltg", "lu", "lua", "luo", "luy", "lv", "mad", "meta-audio", "meta-geo", "meta-tw", "meta-wiki", "mg", "mh", "mi", "mk", "ml", "mlg", "mo", "moh", "mn", "mni", "mnk", "mos", "mr", "ms", "mt", "mus", "my", "na", "nan", "nb", "nci", "nd", "ne", "ng", "nl", "nn", "no", "nr", "nso", "nv", "ny", "oc", "oji", "om", "or", "orm", "os", "pa", "pam", "pan", "pap", "pi", "pl", "pnb", "prs", "ps", "pt-br", "pt", "que", "qvi", "raj", "rm", "rn", "ro", "ru", "run", "rup", "ry", "rw", "sa", "sc", "sco", "sd", "se", "sg", "sgn", "sh", "si", "sk", "skx", "sl", "sm", "sna", "sot", "sa", "sq", "sr-latn", "sr", "srp", "ss", "st", "su", "sv", "swa", "szl", "ta", "tar", "te", "tet", "tg", "th", "tir", "tk", "tl", "tlh", "tn", "to", "toj", "tr", "ts", "tsn", "tsz", "tt", "tw", "ty", "tzh", "tzo", "ug", "uk", "umb", "ur", "uz", "ve", "vi", "vls", "vo", "wa", "wbl", "wol", "xho", "yaq", "yi", "yor", "yua", "za", "zam", "zh-cn", "zh-hk", "zh", "zh-sg", "zh-tw", "zul");
	    return in_array($languageCode, $amaraLanguages);
    }

    /**
     * Exception messages
     *
     * @since 0.4.1
     * @param $type
     * @param $caller
     * @param $expected
     * @param $got
     */
    protected function throwException($type, $caller, $expected, $got) {
        switch ($type) {
            case "InvalidArgumentType":
                $message = "Argument passed to {$caller} must be of the type {$expected}, " . gettype($got) . ' given.';
                throw new \InvalidArgumentException($message);
                break;
            case "InvalidAPISettings":
                $message = "Invalid API account settings passed to {$caller}, Expected: {$expected}, Got: {$got}";
                throw new \InvalidArgumentException($message);
                break;
            default:
                $message = "Unknown exception. Caller: {$caller}, Expected: {$expected}, Got: {$got}";
                throw new \UnknownException($message);
                break;
        }

    }

    // LOGGING

    /**
     * PSR-3 logger
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @since 0.8.0
     * @return null
     */
    public function log($level, string $message, array $context = array()) {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }
}