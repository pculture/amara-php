#!/usr/bin/php
<?php
//todo: delete old videos when they are gone from box.com

require('../vendor/autoload.php');

use Rclonewrapper\Rclonewrapper;
use FranOntanaya\Amara\API;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;

$home = getenv("HOME");

/**
 * Logger setup
 *
 * Log file is rotated every day, max 365 days
 */
if (!file_exists("{$home}/.config/v2bimport")) {mkdir("{$home}/.config/v2bimport"); }
if (!file_exists("{$home}/.config/v2bimport/logs")) { mkdir("{$home}/.config/v2bimport/logs"); }
$log = new Logger('v2bimport');
$handler = new RotatingFileHandler("{$home}/.config/v2bimport/logs/v2bimport.log", 365, Logger::INFO);
// So it doesn't log empty arrays for context and extra:
$formatter = new LineFormatter(null, null, false, true);
$handler->setFormatter($formatter);
$log->pushHandler($handler);

$psCheck = shell_exec("ps -e | grep v2bimport.php");
$instances = preg_match_all('/[0-9]+ .{8} [0-9][0-9]:[0-9][0-9]:[0-9][0-9] v2bimport\.php/', $psCheck, $matches);
if (count($matches) > 1) {
    $log->error('Tried to start new import while another instance still running.');
    exit();
}

$log->info('=== Import started ===');

/**
 * Rclone config
 */
$rclone = new Rclonewrapper('rclone', "{$home}/.config/rclone/rclone.conf");
$log->info("Loaded rclone wrapper");

/**
 * Script config
 */
$configPath = $_SERVER['HOME'] . '/.config/v2bimport/config.json';
$config = json_decode(file_get_contents($configPath));
if (!isset($config->v2bImport)) {
    $log->error("v2bImport setting not found in config.json");
    exit(0);
}
if (!isset($config->v2bImport->videosFolder)) {
    $log->error("videosFolder setting not found in config.json");
    exit(0);
}
if (!file_exists($config->v2bImport->videosFolder)) {
    $log->error("videosFolder folder in config.json doesn't exist");
    exit(0);
}

/**
 * Amara API config
 *
 * Assumes ~/.v2bimport/config.json has the credentials for an username
 * that can post videos on the v2b teams
 *
 */
$API = new API(
    $config->AmaraAPI->root,
    $config->AmaraAPI->username,
    $config->AmaraAPI->key,
    $config->AmaraAPI->version
);
$log->info("Loaded Amara API wrapper");

/**
 * Language folders to look up on box.com and their team slugs on Amara
 */
/*
$languages = [
    'de' => 'ondemand408ct',
    'es' => 'ondemand501ct',
    'zh' => 'ondemand822ct',
    'jp' => 'ondemand384ct',
    'fr' => 'ondemand502ct',
];
*/
$languages = [
    'de' => 'v2b-intake',
    'es' => 'v2b-intake',
    'zh' => 'v2b-intake',
    'jp' => 'v2b-intake',
    'fr' => 'v2b-intake',
    'de_DE' => 'v2b-intake',
    'es_ES' => 'v2b-intake',
    'zh_CN' => 'v2b-intake',
    'ja_JP' => 'v2b-intake',
    'fr_FR' => 'v2b-intake',
];

$amaraProjects = $API->getProjects([
    'team' => 'v2b-intake',
]);

/**
 * Get the list of videos on box to avoid duplicates
 */
$log->info("Reading list of videos from box");
$currentBoxVideos = [];
$boxVideos = [];
$amaraVideos = [];
$amaraTitles = [];
foreach ($languages as $language => $teamSlug) {
    $boxVideos = $rclone->ls("--include '*.mp4' v2b-box:amara/{$language}/");
    $currentBoxVideos[$language] = $boxVideos;
}

/**
 * Identify which videos on box.com aren't on Amara yet
 */
$log->info("Checking for new videos");
$boxNewProjects = [];
$boxProjectsToSync = [];
$videosToPost = [];
$nothingToImport = true;
$log->info("Loading known videos log");
// Reversing the list, recent videos are at the end:
$knownVideos = array_reverse(loadCSV("{$home}/.config/v2bimport/known_videos.csv"));
if ($knownVideos === null) { $knownVideos = []; }
$knownVideosLog = fopen("{$home}/.config/v2bimport/known_videos.csv", 'a');
foreach ($languages as $language => $teamSlug) {
    if (!isset($currentBoxVideos[$language]['/'])) { continue; } // Skip empty box folders
    $log->info("Checking {$language}");
    $videosToPost[$language] = [];
    foreach ($currentBoxVideos[$language]['/'] as $boxProject => $boxVideos) {
        $boxProjectName = str_replace('/', '', $boxProject);
        // Check if we need to create a new project
        $foundProject = false;
        foreach ($amaraProjects as $amaraProject) {
            if (strpos($amaraProject->name, $boxProjectName) === 0) {
                $log->info("Project {$boxProjectName} already exists");
                $foundProject = true;
                break;
            }
        }
        if (!$foundProject) {
            $log->info("{$boxProjectName} is a new project");
            $boxNewProjects[] = $boxProjectName;
        }
        // Search Amara by video URL to check if any of the videos on box doesn't exist on Amara
        $log->info("Looking for new videos in {$boxProject}");
        $videosToPost[$language][$boxProjectName] = [];
        foreach ($boxVideos as $boxVideo) {
            if (!preg_match('/\.mp4$/', $boxVideo['name'])) {
                $log->error("Invalid video URL: https://storage.googleapis.com/v2b-intake/amara/{$language}/{$boxProjectName}/{$boxVideo['name']}");
                continue;
            }
            $videoURL = "https://storage.googleapis.com/v2b-intake/amara/{$language}/{$boxProjectName}/{$boxVideo['name']}";
            $isKnownVideo = false;
            foreach ($knownVideos as $knownVideo) {
                if (in_array($videoURL, $knownVideo)) { $isKnownVideo = true; }
            }
            if ($isKnownVideo) { continue; }
            usleep(1000000);  // Amara API class does handle 429 too many requests, but still we see duplicate attempts at the end
            $result = $API->getVideoInfo([
                    'video_url' => $videoURL,
            ]);
            if (!isset($result[0]->id)) {
                $log->info("{$boxVideo['name']} is a new video");
                $nothingToImport = false;
                // Ensure we'll download this project from box
                $boxProjectsToSync[$boxProject] = "amara/{$language}/$boxProject";
                $videosToPost[$language][$boxProjectName][] = [
                    'title' => $boxVideo['name'],
                    'url' => htmlentities($videoURL),
                ];
            } else {
                fputcsv($knownVideosLog, [$videoURL]);
            }
        }
    }
}
fclose($knownVideosLog);

if ($nothingToImport) {
    $log->info("Nothing to import");
    exit(1);
}

// todo: test rclone connections and alert on failure

/**
 * Download all new projects
 *
 * rclone will create the download directory tree if it doesn't exist
 * todo: consider syncing box to gcs directly, so when v2b deletes a file in their box it's deleted on our gcs
 */
foreach ($boxProjectsToSync as $boxProject => $boxFolder) {
    // todo: sanitize variables
    // todo: check for rclone errors
    $log->info("Downloading {$boxFolder} with rclone");
    shell_exec("rclone sync v2b-box:{$boxFolder} {$config->v2bImport->videosFolder}/{$boxFolder}");
}

// todo: make proxies of larger videos
/**
 * Upload videos to GCS
 *
 * Uploads without deleting any videos in destination (sync makes source and destination identical,
 * including deleting files in destination that don't exist in source)
 */
// todo: check for success
$log->info("Uploading {$config->v2bImport->videosFolder} to GCS");
shell_exec("rclone copy {$config->v2bImport->videosFolder} v2b-gcs:v2b-intake/");

/**
 * Upload transcripts to Google Drive
 *
 * @todo: exclude
 */
$log->info("Uploading transcripts to Google Drive");
shell_exec("rclone copy -v --filter '- deliverables/' --filter '+ *.docx' --filter '+ *.xlsx' --filter '- *' {$config->v2bImport->videosFolder} v2b-transcripts:v2b-intake/transcripts");

/**
 * Create new projects
 */
foreach ($boxNewProjects as $boxNewProject) {
    $log->info("Creating project {$boxNewProject}");
    usleep(250000); // Amara API class does handle 429 too many requests, but still
    $response = $API->createProject([
        'team' => 'v2b-intake',
        'name' => $boxNewProject,
        'slug' => strtolower($boxNewProject),
    ]);
}

/**
 * Post video links to Amara
 */
$log->info("Posting videos to Amara");
$videosPosted = 0;
$amaraLanguagesMap = [
    'jp' => 'ja',
    'zh' => 'zh-cn',
    'ja_JP' => 'ja',
    'de_DE' => 'de',
    'fr_FR' => 'fr',
    'zh_CN' => 'zh-cn',
    'es_ES' => 'es',
];
// teamSlug currently unused, this was in case we wanted to upload videos directly to the team
// but we send them to v2b-intake instead so PMs can sort them out and check for issues
foreach ($languages as $language => $teamSlug) {
    if (!isset($amaraLanguagesMap[$language])) { $amaraLanguagesMap[$language] = $language; }
    if (!isset($videosToPost[$language])) { continue; }
    foreach ($videosToPost[$language] as $boxProjectName => $videos) {
        if (count($videos) === 0) { continue; }
        foreach ($videos as $video) {
            $retryCount = 0;
            do {
                $retryCount++;
                $response = $API->createVideo([
                    'team' => 'v2b-intake',
                    'project' => strtolower($boxProjectName),
                    'primary_audio_language_code' => $amaraLanguagesMap[$language],
                    'title' => $video['title'],
                    'video_url' => $video['url'],
                ]);
                usleep(250000); // Amara API class does handle 429 too many requests, but still
                if ($retryCount === 10) { break; }
            } while(!isset($response->id));
            // Log it right away so we don't have to try to check later
            if (isset($response->id)) {
                $knownVideosLog = fopen("{$home}/.config/v2bimport/known_videos.csv", 'a');
                fputcsv($knownVideosLog, [$response->all_urls[0]]);
                fclose($knownVideosLog);
            }
            $videosPosted++;
        }
    }
}

$log->info("Imported {$videosPosted} videos");
exit(1);

/**
 * CSV loader
 *
 * @param $file
 * @param string $delimiter
 * @param string $enclosure
 * @return array|null
 */
function loadCSV($file, $delimiter = ',', $enclosure = '"') {
    $result = array();
    if (($h = fopen($file, "r")) === false) {
        fclose($h);
        return null;
    }
    if ($enclosure !== '') {
        while (($line = fgetcsv($h, 0, $delimiter, $enclosure)) !== false) {
            array_push($result, $line);
        }
    } else { // When the CSV doesn't have enclosures at all
        while (($line = fgets($h)) !== false) {
            array_push($result, explode($delimiter, $line));
        }
    }
    fclose($h);
    return $result;
}
