<?php
namespace AmaraPHPAccess;

/**
    Amara API component

    This component provides an AmaraAPI object to perform common interactions
    with Amara.org's API.

    It's a refactoring of code originally intended for automating tasks
    from the command line, so it's pretty much alpha code.

    A lot of resources aren't handled yet, no optimizations have been performed,
    and updates will break things. It's provided "as is" -- you must fully
    audit it before using it.

    @author Fran Ontanaya
    @copyright 2014 Fran Ontanaya
    @license GPLv3
    @version 0.1.0
    @uses DummyLogger

    @todo Caching
    @todo Add relevant logging events
    @todo Validate everything.
    @todo Support HTTPS
*/
class API {
    const VERSION = '0.1.0';

    /**
        Credentials

        Each instance of the object is tied to a set of credentials.
        Recycling the object with different credentials is probably
        calling for trouble.

        @since 0.1.0
    */
    protected $host;
    protected $user;
    protected $apikey;

    /**
        External dependencies

        These store supplied dependencies. Protected since they need validation
        e.g. to ensure they are PSR compliant.

        Not implemented currently.

        @since 0.1.0
    */
    protected $logger;
    protected $cache;

    /**
        Settings

        Properties that may be changed on the fly.

        Beware raising $limit (the number of records per request) too much,
        requests that take longer than a minute time out e.g. on videos
        with many languages.

        $total_limit caps how many records you can retrieve from traversable
        resources. The default is already very high, but some resources like
        team activity can have tens of thousands of records. You may want
        to raise the total_limit temporarily for certain actions only,
        so you don't have some request accidentally get caught trying to fetch
        a huge batch of data. Or better, use the $offset argument when calling
        traversable getX methods and aggregate the responses. This may
        eventually be replacied with a Generator that yields the data
        as required.

        @since 0.1.0
    */
    public $retries = 10;
    public $limit = 10;
    public $total_limit = 2000;
    public $verbose_curl = false;

    /**
        Initialization

        For Amara.org, $host should be:
        https://www.amara.org/api2/partners/

        @since 0.1.0
    */
    function __construct( $host, $user, $apikey ) {
        $this->host = $host;
        $this->validateAccount( $host, $user, $apikey );
        $this->user = $user;
        $this->apikey = $apikey;
        $this->logger = new DummyLogger();
    }

    /**
        Change accounts

        The key would be expected to be different on a different host.

        @since 0.1.0
    */
    function setAccount( $host, $user, $apikey ) {
        $this->validateAccount( $host, $user, $apikey );
        if ( $this->host != $host && $this->apikey == $apikey ) {
            throw new \InvalidArgumentException( 'You can\'t use the same API key on different hosts' );
        } elseif ( $this->apikey != $apikey && $this->user == $user ) {
            throw new \InvalidArgumentException( 'You can\'t use the same API key with different users' );
        }
        $this->user = $user;
        $this->apikey = $apikey;
        $this->host = $host;
    }


    /**
        Set a PSR-3 logger

        Rather than bloating the constructor with some optional dependencies, like
        a PSR-3 logger, we construct with a dummy and let the user change the logger
        later.

        User may expect the previous logger to not continue being used
        after changing it, so if this fails, we set it as null.

        @since 0.1
    */
    function setLogger( $logger ) {
        if ( !$this->isValidObject( $logger, array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log' ) ) ) {
            $this->logger = null;
            return false;
        }
        $this->logger = $logger;
        return true;
    }


    // cURL methods

    /**
        Generates headers needed by Amara's API

        Most, but not all, requests and responses from Amara's API are JSON

        @since 0.1.0
    */
    function getHeader( $ct ) {
        $r = array(
            "X-api-username: {$this->user}",
            "X-apikey: {$this->apikey}"
        );
        if ( $ct == 'json' ) { $r = array_merge( $r, array(
            'Content-Type: application/json',
            'Accept: application/json'
        ) ); }
        return $r;
    }

    /**
        Generates the proper API URL from the given parameters

        This method will take a hash table of parameters and values and craft
        the right API URL to call to perform an action. The 'resource' key
        indicates which API resource is the target. $q should be key -> value array
        matching the required query parameters.

        Note that $r[ 'resource' ] doesn't match necessarily the name of
        the resource, e.g. activities -> activity

        @TODO Validate arguments
        @TODO Validate outputs
        @TODO include all resources
    */
    function getResourceUrl( $r, $q = array() ) {
        $url = '';
        switch ( $r[ 'resource' ] ) {
            case 'activities':
                $url = "{$this->host}activity/";
                $q[ 'limit' ] = $this->limit;
                break;
            case 'activity':
                $url = "{$this->host}activity/{$r[ 'activity_id' ]}/";
                break;
            case 'videos':
                $url = "{$this->host}videos/";
                $q[ 'limit' ] = $this->limit;
                break;
            case 'video':
                $url = "{$this->host}videos/{$r[ 'video_id' ]}/";
                break;
            case 'languages':
                $url = "{$this->host}videos/{$r[ 'video_id' ]}/languages/";
                $q[ 'limit' ] = $this->limit;
                break;
            case 'language':
                $url = "{$this->host}videos/{$r[ 'video_id' ]}/languages/{$r[ 'language' ]}/";
                break;
            case 'subtitles':
                $url = "{$this->host}videos/{$r[ 'video_id' ]}/languages/{$r[ 'language' ]}/subtitles/";
                break;
            case 'tasks':
                $url = "{$this->host}teams/{$r[ 'team' ]}/tasks/";
                $q[ 'limit' ] = $this->limit;
                break;
            case 'task':
                $url = "{$this->host}teams/{$r[ 'team' ]}/tasks/{$r[ 'task_id' ]}/";
                break;
            default:
                return null;
        }
        if ( isset( $q ) && !empty( $q ) ) {
            $url .= '?' . http_build_query( $q );
        }
        return $url;
    }

     /**
        cURL request

        Perform all HTTP methods.

        @since 0.1.0
    */
    protected function curl( $mode, $header, $url, $data = '' ) {
        $cr = curl_init();
        curl_setopt( $cr, CURLOPT_URL, $url );
        curl_setopt( $cr, CURLOPT_RETURNTRANSFER, 1 ); # return string
        curl_setopt( $cr, CURLOPT_VERBOSE, $this->verbose_curl );
        curl_setopt( $cr, CURLOPT_SSL_VERIFYPEER, false ); # skip SSL verification. no bueno.
        curl_setopt( $cr, CURLOPT_HTTPHEADER, $header );
        switch ( $mode ) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt( $cr, CURLOPT_POST, 1 ); # post content
                curl_setopt( $cr, CURLOPT_POSTFIELDS, http_build_query( $data ) ); # post content
                break;
            case 'PUT':
            	curl_setopt( $cr, CURLOPT_CUSTOMREQUEST, "PUT" );
            	curl_setopt( $cr, CURLOPT_POSTFIELDS, $data );
            	break;
            case 'DELETE':
            	curl_setopt( $cr, CURLOPT_CUSTOMREQUEST, "DELETE" );
            	break;
            default:
                return null;
        }
        $result = $this->curlTry( $cr );
        curl_close( $cr );
        return $result;
    }

   /**
        cURL retry loop

        Exhaust all retries for HTTP actions.
        This is the method you'd really want to mock for tests.

        @since 0.1.0
        @todo Ensure the way to check for error is correct
    */
    protected function curlTry( $cr ) {
        $retries = 0;
        do {
            $result = curl_exec( $cr );
            $retries++; if ( $retries > $this->retries ) { return null; }
        } while( $result === false );
        return $result;
    }

    /**
        Fetch all required data from a resource

        Some Amara resources can be traversed specifying an offset and limit.
        useResource is meant to handle that, aggregating all the responses.

        All traversable objects (so far) return the data as an array of objects
        in $response->objects.

        If the response is not valid JSON, it's returned as-is (e.g. a subtitle
        track); if it's JSON, but doesn't have an objects array, we won't loop to
        fetch more data.

        Although useResource could be called directly, there's a separate
        method for each HTTP method in case you would want to modify
        the behavior of certain HTTP methods later on, without having to
        duplicate the rest of useResource.
    */
    protected function useResource( $method, $r, $data ) {
        $result = array();
        $header = $this->getHeader( $r[ 'content_type' ] );
        if ( !isset( $data[ 'offset' ] ) ) { $data[ 'offset' ] = 0; }
        do {
            if ( $method == 'PUT' ) {
                $url = $this->getResourceUrl( $r, null );
                if ( $r[ 'content_type' ] == 'json' ) { $data = json_encode( $data ); }
            } else {
                $url = $this->getResourceUrl( $r, $data );
            }
            $response = $this->curl( $method, $header, $url, $data );
            $resource_data = json_decode( $response );
            if ( json_last_error() != JSON_ERROR_NONE ) { return $response; }
            if ( $method != 'GET' || !isset( $resource_data->objects ) ) { return $resource_data; }
            if ( !isset( $resource_data->objects ) ) {
                throw new \UnexpectedValueException( 'Traversable resource didn\'t return an \'objects\' array' );
            } elseif ( !is_array( $resource_data->objects ) ) {
                throw new \UnexpectedValueException( 'Traversable resource\'s \'objects\' property is not an array' );
            }
            $result = array_merge( $result, $resource_data->objects );
            $data[ 'offset' ] += $this->limit;
        } while( $resource_data->meta->next && $data[ 'offset' ] < $resource_data->meta->total_count );
        return $result;
    }

    protected function getResource( $r, $data = null ) {
        return $this->useResource( 'GET', $r, $data );
    }

    protected function createResource( $r, $data = null ) {
        return $this->useResource( 'POST', $r, $data );
    }

    protected function setResource( $r, $data = null ) {
        return $this->useResource( 'PUT', $r, $data );
    }

    protected function deleteResource( $r, $data = null ) {
        return $this->useResource( 'DELETE', $r, $data );
    }

    // VIDEO LANGUAGE RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#video-language-resource

    /**
        Get information about a subtitle track in the specified language

        Notice the elements required in the resource definition:

        * 'resource' - the main type of resource
        * 'content_type' - the type of content expected (usually json)
        * other resource slugs as seen in the resource URL, e.g. language
          not to confuse them with the query parameters themselves

        @since 0.1.0
    */
    function getLanguageInfo( $id, $language ) {
        $r = array(
            'resource' => 'language',
            'content_type' => 'json',
            'video_id' => $id,
            'language' => $language
        );
        return $this->getResource( $r );
    }

    /**
        Get the last version number for a language

        Note that the versions array starts at 0, but
        the version numbering starts at 1.

        Versions are in reverse order, versions[ 0 ]
        is always the latest one. Because versions can be
        deleted, the version_no can't be used in any way
        as index of the versions array.

        @since 0.1.0
    */
    function getLastVersion( $lang_info ) {
        if ( isset( $lang_info->versions[ 0 ]->version_no ) ) {
            return $lang_info->versions[ 0 ]->version_no;
        } else {
            return null;
        }
    }

    // VIDEO RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#video-resource

    /**
        Get information about all videos in a team/project

        Note that this can take a long time on teams/projects
        with many videos. Capped by $this->total_limit.

        Use $params[ 'offset' ] and your own loop
        if you'd rather not wait for this method to finish.

        @since 0.1.0
    */
    function getVideos( $params = array() ) {
        $r = array(
            'resource' => 'videos',
            'content_type' => 'json',
        );
        $q = array(
            'team' => isset( $params[ 'team' ] ) ? $params[ 'team' ] : null,
            'project' => isset( $params[ 'project' ] ) ? $params[ 'project' ] : null,
            'offset' => isset( $params[ 'offset' ] ) ? $params[ 'offset' ] : null
        );
        return $this->getResource( $r, $q );
    }

    /**
        Retrieve metadata info about a video

        The same info can be retrieved by video id or by video url,
        since each video url is associated to a unique video id.

        @since 0.1.0
    */
    function getVideoInfo( $video_id, $params = array() ) {
        if ( $this->isValidVideoID( $video_id ) ) {
            $r = array(
                'resource' => 'video',
                'content_type' => 'json',
                'video_id' => $video_id
            );
            $q = array();
        } elseif ( isset( $params[ 'video_url' ] ) && $params[ 'video_url' ] !== null ) {
            $r = array(
                'resource' => 'videos',
                'content_type' => 'json'
            );
            $q = array(
                'video_url' => isset( $params[ 'video_url' ] ) ? $params[ 'video_url' ] : null
            );
        }
        return $this->getResource( $r, $q );
    }

    /**
        Move a video into a different team/project

        http://amara.readthedocs.org/en/latest/api.html#moving-videos-between-teams-and-projects

        @since 0.1.0
    */
    function moveVideo( $video_id, $params ) {
        $r = array(
            'resource' => 'video',
            'content_type' => 'json',
            'video_id' => $video_id
        );
        $q = array(
            'team' => $params[ 'team ' ],
            'project' => $params[ 'project' ]
        );
        return $this->setResource( $r, $q );
    }

    // ACTIVITY RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#activity-resource

    /**
        Retrieve a set of activity data

        Make sure you specify either $team or $video_id
        otherwise you'll query public activity from the whole site, which is
        a heavy request.
        See http://amara.readthedocs.org/en/latest/api.html#activity-resource

        @since 0.1.0
    */
    function getActivities( $params = array() ) {
        $r = array(
            'resource' => 'activities',
            'content_type' => 'json'
        );
        $q = array(
            'team' => isset( $params[ 'team' ] ) ? $params[ 'team' ] : null,
            'video' => isset( $params[ 'video_id' ] ) ? $params[ 'video_id' ] : null,
            'type' => isset( $params[ 'type' ] ) ? $params[ 'type' ] : null,
            'language' => isset( $params[ 'language' ] ) ? $params[ 'language' ] : null,
            'before' => isset( $params[ 'before' ] ) ? $params[ 'before' ] : null,
            'after' => isset( $params[ 'after' ] ) ? $params[ 'after' ] : null
        );
        return $this->getResource( $r, $q );
    }

    /**
        Retrieve a singe activity record

        @since 0.1.0
    */
    function getActivity( $activity_id ) {
        $r = array(
            'resource' => 'activity',
            'content_type' => 'json',
            'activity_id' => $activity_id
        );
        return $this->getResource( $r );
    }

    // TASK RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#task-resource

    /**
        Retrieve a set of task records

        @since 0.1.0
    */
    function getTasks( $team, $params = array() ) {
        $r = array(
            'resource' => 'tasks',
            'content_type' => 'json',
            'team' => $team,
        );
        $q = array(
            'video_id' => isset( $params[ 'video_id' ] ) ? $params[ 'video_id' ] : null,
            'type' => isset( $params[ 'type' ] ) ? $params[ 'type' ] : null,
            'assignee' => isset( $params[ 'assignee' ] ) ? $params[ 'assignee' ] : null,
            'priority' => isset( $params[ 'priority' ] ) ? $params[ 'priority' ] : null,
            'order_by' => isset( $params[ 'order_by' ] ) ? $params[ 'order_by' ] : null,
            'completed' => isset( $params[ 'completed' ] ) ? $params[ 'completed' ] : null,
            'completed_before' => isset( $params[ 'completed_before' ] ) ? $params[ 'completed_before' ] : null,
            'open' => isset( $params[ 'open' ] ) ? $params[ 'open' ] : null
        );
        return $this->getResource( $r, $q );
    }

    /**
        Retrieve a singe task record

        @since 0.1.0
    */
    function getTaskInfo( $team, $task_id ) {
        $r = array(
            'resource' => 'task',
            'content_type' => 'json',
            'team' => $team,
            'task_id' => $task_id
        );
        return $this->getResource( $r );
    }

    /**
        Create a new task

        You can pass the data from getLanguageInfo if you
        retrieved it earlier, so this doesn't make a new
        request.

        @since 0.1.0
    */
    function createTask( $team, $params, &$lang_info = null ) {
        if ( !in_array( $params[ 'task' ], array( 'Subtitle', 'Translate', 'Review', 'Approve' ) ) ) { return null; }
        if ( !isset( $params[ 'version_no' ] ) && in_array( $params[ 'task' ], array( 'Review', 'Approve' ) ) ) {
            if ( $lang_info == null ) { $lang_info = $this->getLanguageInfo( $id, $language ); }
            $params[ 'version_no' ] = $this->getLastVersion( $lang_info );
        }
        // TODO: It shouldn't assign the task to me
        $r = array(
            'resource' => 'tasks',
            'content_type' => 'json',
            'team' => $team
        );
        $q = array(
            'video_id' => isset( $params[ 'video_id' ] ) ? $params[ 'video_id' ] : null,
            'language' => isset( $params[ 'language' ] ) ? $params[ 'language' ] : null,
            'type' => isset( $params[ 'task' ] ) ? $params[ 'task' ] : null,
            'assignee' => isset( $params[ 'assignee' ] ) ? $params[ 'assignee' ] : null,
            'priority' => isset( $params[ 'priority' ] ) ? $params[ 'priority' ] : null,
            'completed' => isset( $params[ 'completed' ] ) ? $params[ 'completed' ] : null,
            'approved' => isset( $params[ 'approved' ] ) ? $params[ 'approved' ] : null,
            'version_no' => isset( $params[ 'version_no' ] ) ? $params[ 'version_no' ] : null
        );
        return $this->createResource( $r, $q );
    }

    /**
        Delete a task

        @since 0.1.0
    */
    function deleteTask( $team, $task_id ) {
        $r = array(
            'resource' => 'task',
            'content_type' => 'json',
            'team' => $team,
            'task_id' => $task_id
        );
        return $this->deleteResource( $r );
    }

    // SUBTITLES RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#subtitles-resource

    /**
        Fetch the subtitle track

        Specifying the version is needed to retrieve unpublished subtitles
        You may pass $lang_info if you retrieved it previously, or any
        variable to store it afterwards

        If you don't specify the format, you'll get Amara's internal
        subtitle object. You can use it in your code instead of
        passing one of the formats through a parser.

        @since 0.1.0
    */
    function getSubtitle( $video_id, $language, $params, &$lang_info = null ) {
        if ( !isset( $params[ 'version' ] ) ) {
            if ( $lang_info === null ) {
                $lang_info = $this->getLanguageInfo( $video_id, $language );
            }
            $params[ 'version' ] = $this->getLastVersion( $lang_info );
        }
        if ( $params[ 'version' ] === null ) { return null; }
        $r = array(
            'resource' => 'subtitles',
            'video_id' => $video_id,
            'language' => $language,
        );
        $q = array(
            'format' => isset( $params[ 'format' ] ) ? $params[ 'format' ] : 0,
            'version' => isset( $params[ 'version' ] ) ? $params[ 'version' ] : 0
        );
        return $this->getResource( $r, $q );
    }

   /**
        Upload a subtitle track

        In theory this should be a createResource action,
        but currently it works with PUT rather than POST

        You may want to fetch first and preserve here the
        subtitles_complete/is_complete status.

        Note that sub_format defaults to SRT.

        @since 0.1.0
    */
    function uploadSubtitle( $video_id, $language, $params, &$lang_info = null ) {
        // Create the language if it doesn't exist
        if ( !$lang_info && !$lang_info = $this->getLanguageInfo( $video_id, $language ) ) {
            $r = array(
                'resource' => 'languages',
                'content_type' => 'json',
                'video_id' => $video_id
            );
            $q = array(
                'language_code' => $language
            );
            $this->createResource( $r, $q );
            $lang_info = $this->getLanguageInfo( $video_id, $language );
        }
        $r = array(
            'resource' => 'subtitles',
            'content_type' => 'json',
            'video_id' => $video_id,
            'language' => $language,
        );
        $q = array(
            'subtitles' => isset( $params[ 'subtitles' ] ) ? $params[ 'subtitles' ] : null,
            'sub_format' => isset( $params[ 'sub_format' ] ) ? $params[ 'sub_format' ] : null,
            'title' => isset( $params[ 'title' ] ) ? $params[ 'title' ] : $lang_info->title,
            'description' => isset( $params[ 'description' ] ) ? $params[ 'description' ] : $lang_info->description,
            'is_complete' => isset( $params[ 'complete' ] ) ? $params[ 'complete' ] : null
        );
        return $this->setResource( $r, $q );
    }

    // TEAM MEMBER RESOURCE
    // http://amara.readthedocs.org/en/latest/api.html#team-member-resource

    /**
        Add a new member to a team

        @since 0.2.0
    */

    function addMember( $team, $params ) {
        // TODO: It shouldn't assign the task to me
        $r = array(
            'resource' => 'members',
            'content_type' => 'json',
            'team' => $team
        );
        $q = array(
            'username' => $params[ 'username' ],
            'role' => $params[ 'role' ]
        );
        return $this->createResource( $r, $q );
    }


    // Validators

    /**
        Validate API keys

        Note that we wouldn't want to perform requests to validate the account
        until we have some assurance the host is an Amara install,
        otherwise you could leak the credentials to somewhere unexpected.

        @todo Validate URL
        @since 0.1.0
    */
    function validateAccount( $host, $user, $apikey ) {
        if ( strlen( $apikey ) != 40 ) {
            throw new \LengthException( 'The API key is not 40 characters long' );
        } elseif ( preg_match( '/^[0-9a-f]*$/', $apikey ) !== 1 ) {
            throw new \InvalidArgumentException( 'The API key should contain lowercase hexadecimal characters only' );
        }
        return true;
    }

    /**
        Check if an object has the expected methods

        @since 0.1.0
    */
    function isValidObject( $object, $valid_methods ) {
        if ( !is_object( $object ) || !is_array( $valid_methods) ) { return null; }
        $obj_methods = get_class_methods( $object );
        if ( count( array_intersect( $valid_methods, $obj_methods ) ) == count( $valid_methods ) ) {
            return true;
        }
        return false;
    }

    /**
        Check if a string looks like a valid video ID

        @since 0.1.0
    */
    function isValidVideoID( $id ) {
        if ( strlen( $id ) != 12 ) {
            return false;
        } elseif ( preg_match( '/^[A-Za-z0-9]*$/', $id ) !== 1 ) {
            return false;
        }
        return true;
    }

}

/**
    Dummy PSR-3 logger

    Used as dummy in case no valid PSR-3 logger was supplied,
    so we don't get E_FATALs when we try to log something.

    Currently unused -- logging points will be added soon.

    @used-by AmaraAPI
*/
class DummyLogger {
    function emergency( $message, array $context = array() ) {}
    function alert( $message, array $context = array() ) {}
    function critical( $message, array $context = array() ) {}
    function error( $message, array $context = array() ) {}
    function warning( $message, array $context = array() ) {}
    function notice( $message, array $context = array() ) {}
    function info( $message, array $context = array() ) {}
    function debug( $message, array $context = array() ) {}
    function log( $level, $message, array $context = array() ) {}
}
