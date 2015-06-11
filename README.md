amara-php
================

PHP snippets and scripts for interacting with Amara

These are unsupported samples for your own implementations.

All code except SrtParser is licensed as GPL 3.0
https://www.gnu.org/licenses/gpl-3.0.en.html

SrtParser is licensed as LGPL 2+ by Julien 'delphiki' Villetorte

# API

Provides an object to perform some of the most common interactions with Amara's API

## Example usage
```
requireonce 'API.php';
$API = new AmaraPHP\API(
        'https://www.amara.org/api/',
        'username',
        'apikey'
  );
$videoInfo = $API->getVideoInfo(array(
            'video_id' => $video_id
            ));
$title = basename(str_replace('.mp4', '', $videoInfo->all_urls[0]));
$captions = $API->getSubtitle(array(
        'format' => 'srt',
        'video_id' => $video_id,
        'language_code' => $language
    ));
```

# ShotLog

Exports two sets of parsed subtitles into a side-by-side shot log document

Requires delphiki/subrip-file-parser (a modified copy is included in Formats/SrtParser)

## Example usage
Where parse_subrip is a method to turn a string containing a SRT's content into a subrip-file-parser object

```
$shotLog = new AmaraPHP\Formats\ShotLog();
$videoInfo = $API->getVideoInfo(array(
    'video_id' => $video_id
));
$title = basename(str_replace('.mp4', '', $videoInfo->all_urls[0]));
$captionsTrack = $API->getSubtitle(array(
        'format' => 'srt',
        'video_id' => $video_id,
        'language_code' => $language
    ));
$captions = parse_subrip($captionsTrack);
$descriptionsTrack = $API->getSubtitle(array(
        'format' => 'srt',
        'video_id' => $video_id,
        'language_code' => 'meta-audio'
    ));
$descriptions = parse_subrip($descriptionsTrack);
$shotLog->save($captions->getSubs(), $descriptions->getSubs(), $tmpDir, $title);
```
