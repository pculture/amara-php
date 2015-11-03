#!/usr/bin/php
<?php
require 'utils.php';

$arg = getOpts($argv);
date_default_timezone_set('UTC');
$videos = array();
$language_stats = array();
$runtime_stats = array();

define('VIDEO_STATS', strpos($arg['stats'], 'v') !== false);
define('LANG_STATS', strpos($arg['stats'], 'l') !== false);
define('RUNTIME_STATS', strpos($arg['stats'], 'r') !== false);
define('PROXY_STATS', strpos($arg['stats'], 'p') !== false);
define('COMPLETION_STATS', strpos($arg['stats'], 'c') !== false);

if (!(VIDEO_STATS || LANG_STATS || RUNTIME_STATS || PROXY_STATS || COMPLETION_STATS)) { echo "Missing -stats\n"; exit(); }
if (!isset($arg['apikey']) || !isset($arg['username'])) { echo "Missing apikey or username argument.\n"; exit(); }

$now = date('Y-m-d_H:i:s');
$tmpDir = tmpDir('Amara.org_' . $arg['team'] . '_' . $arg['project'] . '_stat-sheets_' . $now);
if (VIDEO_STATS) {
    $subs_file = $tmpDir . '/Amara.org_' . $arg['team'] . '_stats_' . $now . '.csv';
    $csv_subs = fopen($subs_file, 'a');
    fputcsv($csv_subs, array(
	    "Team",
	    "Project",
	    "Complete",
	    "Published",
	    "Approved",
	    "Original Title",
	    "AmaraID",
	    "Video URL",
	    "Video format",
	    "Video duration",
	    "Video FPS",
	    "Language",
	    "Original Language",
	    "Contributors",
	    "Total versions",
	    "Language created on:",
	    "Language URL",
	    "Title",
	    "Description",
	    "Thumbnail"
	   ));
}
if (LANG_STATS) {
    $langs_file = $tmpDir . '/Amara.org_' . $arg['team'] . '_top-contributors_' . $now . '.csv';
    $csv_languages = fopen($langs_file, 'a');
    $language_stats = array();
    fputcsv($csv_languages, array(
	    "Team(s)",
	    "Language",
	    "Videos in that language",
	    "Contributor",
	    "Subtitles in that language contributed to",
	    "Video IDs contributed to"
	   ));
}
if (COMPLETION_STATS) {
    $completion_file = $tmpDir . '/Amara.org_' . $arg['team'] . '_completion_' . $now . '.csv';
    $csv_completion = fopen($completion_file, 'a');
    $completion_stats = array();
    fputcsv($csv_completion, array(
	    "Team(s)",
	    "Project",
	    "Total videos",
	    "Language",
	    "Videos in this language"
	   ));
}
if (RUNTIME_STATS) {
    $runtimes_file = $tmpDir . '/Amara.org_' . $arg['team'] . '_runtimes_' . $now . '.csv';
    $csv_runtimes = fopen($runtimes_file, 'a');
    fputcsv($csv_runtimes, array(
	    "Team(s)",
	    "Project",
	    "Total runtime",
	    "Number of videos",
	    "Video IDs"
	   ));
}

if (PROXY_STATS) {
    $proxy_file = $tmpDir . '/Amara.org_' . $arg['team'] . '_proxy-stats_' . $now . '.csv';
    $csv_proxy = fopen($proxy_file, 'a');
    $proxy_stats = array();
    fputcsv($csv_proxy, array(
	    "Team(s)",
	    "Project",
	    "Video",
	    "Video URL",
	    "Video format",
	    "Runtime",
	    "Created on",
	    "Total bitrate",
	    "Video codec",
	    "Dimensions",
	    "Video bitrate",
	    "FPS",
	    "Audio codec",
	    "Sample rate",
	    "Channels",
	    "Audio bitrate",
	    "URL"
   ));
}

$teams = explode(',', $arg['team']);
$projects = $arg['project'] ? explode(',', $arg['project']) : array('');
$languages = explode(',', $arg['language']);
$apikey = $arg['apikey'];
$username = $arg['username'];
$total_videos = array();
foreach ($teams as $team) {
    foreach ($projects as $project) {
	    $videos = getVideos($apikey, $username, $team, $project, 0);
        $count = 0; //$total_videos = count($videos);
	    foreach($videos as $video) {
	        if (!$project) { $project = '(none)'; } else { $project = $video->project; }
	        if (!$project) { $project = '(none)'; }
            if (!isset($total_videos[$project])) { $total_videos[$project] = 0; }
            $total_videos[$project]++;
            
            if (COMPLETION_STATS) {
                if (!isset($completion_stats[$team])) { $completion_stats[$team] = array(); }
                if (!isset($completion_stats[$team][$project])) { $completion_stats[$team][$project] = array(); }
                if (!isset($completion_stats[$team][$project]['total'])) { $completion_stats[$team][$project]['total'] = 0; }
                $completion_stats[$team][$project]['total'] = $total_videos[$project];
                if (!isset($completion_stats[$team][$project]['languages'])) {$completion_stats[$team][$project]['languages'] = array();}
                echo $project, "-", $total_videos[$project], "\n";
            }

	        $filtered_language = false;
	        $video_properties = array();
            if (PROXY_STATS || (RUNTIME_STATS && $video->duration === null)) {
                $video_properties = getVideoProperties($apikey, $username, $video->all_urls[0]);
            } else {
	            $video_properties['duration'] = $video->duration;
            }
            if (PROXY_STATS) {
                $proxy_fields = array(
		                $team,
		                $project,
		                $video->id,
		                $video->all_urls[0],
		                $video_properties['format'],
		                seconds_to_timecode($video->duration ?: $video_properties['duration']),
		                $video_properties['creation_time'],
		                $video_properties['bitrate'],
                        $video_properties['video_codec'],
                        $video_properties['dimensions'],
                        $video_properties['video_bitrate'],
                        $video_properties['fps'],
                        $video_properties['audio_codec'],
                        $video_properties['sample_rate'],
                        $video_properties['channels'],
                        $video_properties['audio_bitrate'],
		                $video->all_urls[0]
                   );
                fputcsv($csv_proxy, $proxy_fields);
            }
            if (LANG_STATS || VIDEO_STATS || COMPLETION_STATS) {
		        foreach($video->languages as $language) {
                    if ((!$arg['language'] || in_array($language->code, $languages))) {
                        if (LANG_STATS) {
			                $language_stats[$language->code]['videos'][$video->id] = true;

			                $language_info = getLanguageInfoByID($apikey, $username, $video->id, $language->code);
			                if (in_array($language->code, $languages) && $language_info->subtitles_complete && isPublished($apikey, $username, $video->id, $language->code, $language_info)) { $filtered_language = $language_info; }
			                $contributors = array();
			                foreach($language_info->versions as $version) {
				                if (isset($contributors[$version->author])) { $contributors[$version->author]++; } else { $contributors[$version->author] = 1; }
			                }
			                asort($contributors);
			                $contrib_list = '';
			                foreach($contributors as $author=>$versions) {
				                if ($contrib_list) { $contrib_list .= ', '; }
				                $contrib_list .= "$author ($versions)";

				                $language_stats[$language->code]['contributors'][$author][$video->id] = true;
			                }
                        }
                        if (COMPLETION_STATS) {
                            if ($language_info->subtitles_complete && isPublished($apikey, $username, $video->id, $language->code, $language_info)) {
                            
                                if (!isset($completion_stats[$team][$project]['languages'][$language->code])) { 
                                    $completion_stats[$team][$project]['languages'][$language->code] = 0;
                                }
                                $completion_stats[$team][$project]['languages'][$language->code]++;
                            }
                        }
                        if (VIDEO_STATS) {
			                $published = false;
			                if (isset($language_info)) { $published = isPublished($apikey, $username, $video->id, $language->code, $language_info); }
                            if ($video->title === '' && PROXY_STATS && $video_properties['title']) {
                                $videotitle = $video_properties['title'];
                            } else {
                                $videotitle = $video->title;
                            }
			                $subs_fields = array(
				                $team,
				                $project,
				                ($language_info->subtitles_complete ? 'Yes' : 'No'),
				                ($published ? 'Yes' : 'No'),
				                ($language_info->subtitles_complete && $published ? 'Yes' : 'No'),
				                $videotitle,
				                $video->id,
            		            $video->all_urls[0],
				                isset($video_properties['format']) ? $video_properties['format'] : 'unknown',
				                isset($video_properties['duration']) ? s2tc($video_properties['duration']) : 'unknown',
				                isset($video_properties['fps']) ? $video_properties['fps'] : 'unknown',
				                $language->code,
				                $video->original_language,
				                $contrib_list,
				                $language_info->num_versions,
				                $language_info->created,
				                isset($language_info->site_url) ?: '',
				                $language_info->title,
				                $language_info->description,
				                $video->thumbnail
			               );
			                fputcsv($csv_subs, $subs_fields);
		                }
                    }
		        }
            }
	        if (RUNTIME_STATS) {
	            if (!$arg['language'] || is_object($filtered_language) && $filtered_language->subtitles_complete && isPublished($apikey, $username, $video->id, $language->code, $filtered_language)) {
	                $rrow = $team . '%%' . $project;
                    $rtrow = $team . '%%_All Projects_';
                    if ($arg['language']) { $rrow .= '-' . $arg['language']; $rtrow .= '-' . $arg['language']; }
	                if (!isset($runtime_stats[$rrow])) { $runtime_stats[$rrow] = array(); }

	                if (!isset($runtime_stats[$rrow]['runtime'])) { $runtime_stats[$rrow]['runtime'] = 0; }
	                $runtime_stats[$rrow]['runtime'] += $video_properties['duration'];
	                if (!$video_properties['duration']) { echo 'VIDEO DURATION MISSING ' . $video->id . "\n"; }

	                if (!isset($runtime_stats[$rrow]['num_videos'])) { $runtime_stats[$rrow]['num_videos'] = 0; }
	                $runtime_stats[$rrow]['num_videos']++;

	                if (!isset($runtime_stats[$rrow]['videos'])) { $runtime_stats[$rrow]['videos'] = ''; }
	                $runtime_stats[$rrow]['videos'] .= $video->id . ' (' . $video_properties['duration'] . ")\n";

                    if(count($teams) > 1) {
                        $ratrow = '_All Teams_%%_All Projects_';
                        if (!isset($runtime_stats[$ratrow]['runtime'])) { $runtime_stats[$ratrow]['runtime'] = 0; }
	                    $runtime_stats[$ratrow]['runtime'] += $video_properties['duration'];
	                    if (!isset($runtime_stats[$ratrow]['num_videos'])) { $runtime_stats[$ratrow]['num_videos'] = 0; }
	                    $runtime_stats[$ratrow]['num_videos']++;
	                    $runtime_stats[$ratrow]['videos'] = '';
                    }
                }
            }
            $count++;
            echo number_format($count / count($videos) * 100, 2), "% complete.\n\n";
	    }
    }
}
if (VIDEO_STATS) { fclose($csv_subs); }
if (PROXY_STATS) { fclose($csv_proxy); }

if (COMPLETION_STATS) {
    foreach ($completion_stats as $teamName=>$projectStats) {
	    foreach ($projectStats as $projectName=>$languageCompletion) {
            foreach ($languageCompletion['languages'] as $languageCode=>$languageCount) {
		        $completion_fields = array(
			        $teamName,
			        $projectName,
			        isset($completion_stats[$teamName][$projectName]['total']) ? $completion_stats[$teamName][$projectName]['total'] : '',
			        getLanguageName($languageCode),
			        $languageCount
                );
		        fputcsv($csv_completion, $completion_fields);
	        }
        }
    }
    fclose($csv_completion);
}
if (LANG_STATS) {
    foreach ($language_stats as $lang=>$stats) {
        if (isset($stats['contributors'])) {
	        foreach($stats['contributors'] as $author=>$videos) {
		        $lang_fields = array(
			        $arg['team'],
			        getLanguageName($lang),
			        count($stats['videos']),
			        $author,
			        count($videos),
			        implode("\n", array_keys($videos))
		       );
		        fputcsv($csv_languages, $lang_fields);
	        }
        }
    }
    fclose($csv_languages);
}
if (RUNTIME_STATS) {
    foreach ($runtime_stats as $slug=>$stats) {
        $slugs = explode('%%', $slug);
	    $runtime_fields = array(
		    $slugs[0],
		    $slugs[1],
		    s2tc($stats['runtime']),
		    $stats['num_videos'],
		    $stats['videos']
	   );
	    fputcsv($csv_runtimes, $runtime_fields);
    }
    fclose($csv_runtimes);
}
