<?php
function tmpDir($folder = '') {
	if ($folder) {$folder .= '_';}
	$tmp_dir = getcwd() . '/tmp/' . $folder . time();
	mkdir($tmp_dir);
	echo 'Using ', $tmp_dir, ' as temporal directory.', "\n";
	return $tmp_dir;
}

function getOpts( $argv ) {
    $flags = array(
        '-T' => 'transcribe',
        '-AC' => 'autocorrect',
        '-U' => 'upload',
        '-Y' => 'do_sync',
        '-VB' => 'vimeo_buckets',
        '-V' => 'verbose_curl',
        '-DE' => 'decode_entities',
        '-F' => 'force'
    );
    $params = array(
        '-desc' => 'description',
        '-do' => 'do',
        '-apikey' => 'apikey',
        '-d' => 'directory',
        '-l' => 'language',
        '-format' => 'sub_format',
        '-complete' => 'is_complete',
        '-i' => 'input',
        '-lb' => 'linebreak',
        '-host' => 'host',
        '-m' => 'mode',
        '-id' => 'video_id',
        '-o' => 'output',
        '-newfps' => 'newfps',
        '-fps' => 'fps',
        '-t' => 'team',
        '-tid' => 'teamLookupID',
        '-title' => 'title',
        '-prog' =>'program',
        '-p' => 'project',
        '-tsk' => 'task',
        '-cr' => 'credit',
        '-stats' => 'stats',
        '-sid' => 'sid',
        '-tt' => 'to_team',
        '-tp' => 'to_project',
        '-u' => 'username',
        '-r' => 'role',
        '-csrf' => 'csrf',
        '-csv' => 'csv',
        '-uuid' => 'uuid'
    );
    $defaults = array(
	    'output' => 'output.docx',
	    'transcribe' => false,
	    'input' => null,
	    'directory' => null,
	    'team' => null,
	    'project' => null,
	    'upload' => false,
	    'credit' => 'Amara On Demand Subtitles',
	    'mode' => null,
	    'autocorrect' => false,
	    'language' => null,
	    'linebreak' => 'UNIX',
	    'host' => null,
	    'title' => null,
        'description' => null,
	    'program' => null,
	    'sessionid' => null,
	    'verbose_curl' => false,
	    'vimeo_buckets' => false
    );
    $args = $defaults;
    for ( $i = 1; $i < count( $argv ); $i++ ) {
        if ( isset( $flags[ $argv[ $i ] ] ) ) {
            $args[ $flags[ $argv[ $i ] ] ] = true;
        }
        if ( isset( $params[ $argv[ $i ] ] ) && ( !isset( $argv[ $i + 1 ] ) || ( !isset( $params[ $argv[ $i + 1 ] ] ) && !isset( $flags[ $argv[ $i + 1 ] ] ) ) ) ) {
            $args[ $params[ $argv[ $i ] ] ] = $argv[ ++$i ];
        }
    }
    $opts = $args;
    return $opts;
}

function s2tc($s) {
	$hours = str_pad( floor( $s / ( 60 * 60 ) ), 2, '0', STR_PAD_LEFT );
	$minutes = str_pad( floor( ( $s - $hours * 60 * 60 ) / 60 ), 2, '0', STR_PAD_LEFT );
	$seconds = str_pad( floor( ( $s - ( $hours * 60 * 60 + $minutes * 60 ) ) ), 2, '0', STR_PAD_LEFT );
	$milliseconds = str_pad( floor( ( $s - ( $hours * 60 * 60 + $minutes * 60 + $seconds ) ) * 1000 ), 3, '0', STR_PAD_LEFT );
	return "$hours:$minutes:$seconds,$milliseconds";
}

function tc2ms($tc){
	$tab = explode(':', $tc);
	$durMS = $tab[0]*60*60*1000 + $tab[1]*60*1000 + floatval(str_replace(',','.',$tab[2]))*1000;
	return $durMS;
}

function isPublished( $apikey, $username, $id = false, $language = false, $linfo = false ) {
	if ( !$linfo ) { $linfo = getLanguageInfoByID( $apikey, $username, $id, $language ); }
	if (isset($linfo) && isset($linfo->versions[ 0 ]) && isset($linfo->versions[ 0 ]->published)) {
    	return $linfo->versions[ 0 ]->published;
    } else {
        return false;
    }
}

function getVideos($apikey, $username, $team, $project = '', $offset = 0) {
	$videos = array();
	$chunk = 10;
	do {
		$v = getVideosLoop($apikey, $username, $team, $project, $offset, $chunk);
		if ( !count( $v->objects ) ) { break; }
		$videos = array_merge( $videos, $v->objects );
		$offset = $offset + $chunk;
	} while( count( $v->objects ) );
	return $videos;
}

function getVideosLoop($apikey, $username, $team, $project = '', $offset = 0, $limit = 10) {
	$cr = curl_init();
	$url = "https://www.amara.org/api/videos/?team=$team" .
			( ( $project ) ? "&project=$project" : '' ) .
			"&format=json&limit=$limit&offset=$offset";
	echo $url, "\n";
	curl_setopt( $cr, CURLOPT_URL, $url	);
	curl_setopt( $cr, CURLOPT_VERBOSE, 0 );
	curl_setopt( $cr, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $cr, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $cr, CURLOPT_HTTPHEADER, array(
		"X-api-username: $username",
		"X-apikey: $apikey"
	) );
	$retry = 0;
	do {
		$json_videos = curl_exec( $cr );
		$retry++; if ( $retry == 10 ) { break; }
	} while ( !$json_videos );
	if ( $json_videos === false ) { echo "[FAIL]\n"; echo curl_error( $cr ), "\nAborting..."; curl_close( $cr ); exit; }
	curl_close( $cr );

	# Parse response
	$videos = json_decode( $json_videos );
	return $videos;
}

/**
 *
 *
 * Example of ffmpeg metadata:
 * Metadata:
 *   major_brand     : isom
 *   minor_version   : 1
 *   compatible_brands: isomavc1
 *   creation_time   : 2010-03-15 16:10:44
 * Duration: 00:28:39.41, start: 0.000000, bitrate: 683 kb/s
 *   Stream #0:0(und): Video: h264 (High) (avc1 / 0x31637661), yuv420p, 640x360 [SAR 1:1 DAR 16:9], 570 kb/s, 29.97 fps, 29.97 tbr, 30k tbn, 59.94 tbc (default)
 *   Metadata:
 *     creation_time   : 2010-03-15 16:10:44
 *     handler_name    : GPAC ISO Video Handler
 *   Stream #0:1(und): Audio: aac (mp4a / 0x6134706D), 44100 Hz, stereo, fltp, 109 kb/s (default)
 *   Metadata:
 *     creation_time   : 2010-03-15 16:10:54
 *     handler_name    : GPAC ISO Audio Handler
 */

function getVideoProperties( $url ) {
    $pathinfo = pathinfo( $url );
    if ( strpos( $pathinfo[ 'dirname' ], 'youtube.com' ) !== false ) {
        $YT_info = getYouTubeVideoProperties( array( 'url' => $url ) );
        $proxy_properties = ffprobe( $YT_info[ 'yt:content' ] );
        $FPS_rates = array(
            '1001/30000' => '29.976'
        );
        $result[ 'format' ] = 'YouTube';
        if ( $YT_info[ 'yt:duration' ] !== null ) {
            $result[ 'title' ] = $YT_info[ 'yt:title' ];
            $result[ 'creation_time' ] = $YT_info[ 'yt:published' ];
            $result[ 'bitrate' ] = '--';
            $result[ 'duration' ] = $YT_info[ 'yt:duration' ];
            $result[ 'video_codec' ] = '--';
            $result[ 'dimensions' ] = '--';
            $result[ 'video_bitrate' ] = '--';
            $result[ 'fps' ] = $FPS_rates[ $proxy_properties->streams[ 0 ]->codec_time_base ];
            $result[ 'audio_codec' ] = '--';
            $result[ 'sample_rate' ] = '--';
            $result[ 'channels' ] = '--';
            $result[ 'audio_bitrate' ] = '--';
        }
        return $result;
    } elseif ( strpos( $pathinfo[ 'dirname' ], 'vimeo.com' ) !== false ) {
        $result[ 'format' ] = 'Vimeo';
        return $result;
    } elseif ( isset($pathinfo[ 'extension' ]) && $pathinfo[ 'extension' ] != 'mp4' ) {
        $result[ 'format' ] = $pathinfo[ 'extension' ];
        return $result;
    } elseif ( isset($pathinfo[ 'extension' ]) && $pathinfo[ 'extension' ] == 'mp4' ) {
        $result = array();
        $result[ 'format' ] = 'mp4';
        $output = shell_exec( 'ffmpeg -i "' . str_replace( 'https', 'http', $url ) . '" 2>&1' );
        if ( strpos( 'HTTP error', $output ) !== false ) { return null; }
        $matches = array();
        preg_match( '/Duration: ([0-9][0-9]:[0-9][0-9]:[0-9][0-9]\.[0-9][0-9])/', $output, $matches );
        $result[ 'duration' ] = tc2ms( $matches[ 1 ] . '0' ) / 1000;
        preg_match( '/creation_time   : ([0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9])/', $output, $matches );
        $result[ 'creation_time' ] = $matches[ 1 ];
        preg_match( '/bitrate: ([0-9]*) kb\/s/', $output, $matches );
        $result[ 'bitrate' ] = $matches[ 1 ];
        preg_match( '/Video: ([a-z0-9]*).*, .*, ([0-9]+x[0-9]+).*, ([0-9]+) kb\/s, ([0-9][0-9][0-9]?\.?[0-9]*) fps/', $output, $matches );
        $result[ 'video_codec' ] = $matches[ 1 ];
        $result[ 'dimensions' ] = $matches[ 2 ];
        $result[ 'video_bitrate' ] = $matches[ 3 ];
        $result[ 'fps' ] = $matches[ 4 ];
        preg_match( '/Audio: ([^\s]+).*, ([0-9]+) Hz, ([^\s]+), [^\s]+, ([0-9]+) kb\/s \(default\)/', $output, $matches );
        $result[ 'audio_codec' ] = $matches[ 1 ];
        $result[ 'sample_rate' ] = $matches[ 2 ];
        $result[ 'channels' ] = $matches[ 3 ];
        $result[ 'audio_bitrate' ] = $matches[ 4 ];
        return $result;
    } else {
        return null;
    }
}


function getLanguageName( $iso_code ) {
	$iso_langs = array(
		"aa" => "Afar",
		"ab" => "Abkhazian",
		"ae" => "Avestan",
		"af" => "Afrikaans",
		"aka" => "Akan",
		"amh" => "Amharic",
		"an" => "Aragonese",
		"arc" => "Aramaic",
		"ar" => "Arabic",
		"arq" => "Algerian Arabic",
		"ase" => "American Sign Language",
		"as" => "Assamese",
		"ast" => "Asturian",
		"av" => "Avaric",
		"ay" => "Aymara",
		"az" => "Azerbaijani",
		"bam" => "Bambara",
		"ba" => "Bashkir",
		"be" => "Belarusian",
		"ber" => "Berber",
		"bg" => "Bulgarian",
		"bh" => "Bihari",
		"bi" => "Bislama",
		"bn" => "Bengali",
		"bnt" => "Ibibio",
		"bo" => "Tibetan",
		"br" => "Breton",
		"bs" => "Bosnian",
		"bug" => "Buginese",
		"cak" => "Cakchiquel, Central",
		"ca" => "Catalan; Valencian",
		"ceb" => "Cebuano",
		"ce" => "Chechen",
		"ch" => "Chamorro",
		"cho" => "Choctaw",
		"cku" => "Koasati",
		"co" => "Corsican",
		"cr" => "Cree",
		"cs" => "Czech",
		"ctd" => "Chin, Tedim",
		"ctu" => "Chol, Tumbalá",
		"cu" => "Church Slavic",
		"cu" => "Church Slavic",
		"cv" => "Chuvash",
		"cy" => "Welsh",
		"da" => "Danish",
		"de" => "German",
		"dv" => "Divehi",
		"dz" => "Dzongkha",
		"ee" => "Ewe",
		"efi" => "Efik",
		"el" => "Greek, Modern",
		"en-gb" => "English British",
		"en" => "English",
		"eo" => "Esperanto",
		"es-ar" => "Spanish, Argentinian",
		"es-mx" => "Spanish, Mexican",
		"es" => "Spanish",
		"es-ni" => "Spanish, Nicaraguan",
		"et" => "Estonian",
		"eu" => "Basque",
		"fa" => "Persian",
		"ff" => "Fula",
		"fil" => "Filipino",
		"fi" => "Finnish",
		"fj" => "Fijian",
		"fo" => "Faroese",
		"fr-ca" => "French, Canadian",
		"fr" => "French",
		"fy" => "Western Frisian",
		"fy-nl" => "Frisian",
		"ga" => "Irish",
		"gd" => "Scottish Gaelic",
		"gl" => "Galician",
		"gn" => "Guaran",
		"gu" => "Gujarati",
		"gv" => "Manx",
		"hai" => "Haida",
		"hau" => "Hausa",
		"haw" => "Hawaiian",
		"haz" => "Hazaragi",
		"hus" => "Huastec, Veracruz",
		"hb" => "HamariBoli (Roman Hindi-Urdu)",
		"hch" => "Huichol",
		"he" => "Hebrew (modern)",
		"hi" => "Hindi",
		"ho" => "Hiri Motu",
		"hr" => "Croatian",
		"ht" => "Creole, Haitian",
		"hu" => "Hungarian",
		"hup" => "Hupa",
		"hy" => "Armenian",
		"hz" => "Herero",
		"ia" => "Interlingua",
		"ibo" => "Igbo",
		"id" => "Indonesian",
		"ie" => "Interlingue",
		"ig" => "Igbo",
		"ii" => "Sichuan Yi",
		"ik" => "Inupia",
		"ilo" => "Ilocano",
		"inh" => "Ingush",
		"io" => "Ido",
		"iro" => "Iroquoian languages",
		"is" => "Icelandic",
		"it" => "Italian",
		"iu" => "Inuktitut",
		"ja" => "Japanese",
		"jv" => "Javanese",
		"ka" => "Georgian",
		"kar" => "Karen",
		"kau" => "Kanuri",
		"kg" => "Kongo",
		"kik" => "Gikuyu",
		"ki" => "Kikuyu, Gikuyu",
		"kin" => "Rwandi",
		"kj" => "Kwanyama, Kuanyama",
		"kk" => "Kazakh",
		"kl" => "Greenlandic",
		"km" => "Khmer",
		"kn" => "Kannada",
		"ko" => "Korean",
		"kon" => "Kongo",
		"kr" => "Kanuri",
		"ksh" => "Colognian",
		"ks" => "Kashmiri",
		"ku" => "Kurdish",
		"kv" => "Komi",
		"kw" => "Cornish",
		"ky" => "Kyrgyz",
		"la" => "Latin",
		"lb" => "Luxembourgish",
		"lg" => "Ganda",
		"lg" => "Luganda",
		"li" => "Limburgish",
		"lin" => "Lingala",
		"lkt" => "Lakota",
		"lld" => "Ladin",
		"ln" => "Lingala",
		"lo" => "Lao",
		"lt" => "Lithuanian",
		"ltg" => "Latgalian",
		"lu" => "Luba-Katanga",
		"lua" => "Luba-Kasai",
		"luo" => "Luo",
		"luy" => "Luhya",
		"lv" => "Latvian",
		"mad" => "Madurese",
		"meta-audio" => "Metadata: Audio Description",
		"meta-geo" => "Metadata: Geo",
		"meta-tw" => "Metadata: Twitter",
		"meta-wiki" => "Metadata: Wikipedia",
		"mg" => "Malagasy",
		"mh" => "Marshallese",
		"mi" => "Maori",
		"mk" => "Macedonian",
		"ml" => "Malayalam",
		"mlg" => "Malagasy",
		"mo" => "Moldavian, Moldovan",
		"moh" => "Mohawk",
		"mn" => "Mongolian",
		"mni" => "Manipuri",
		"mnk" => "Mandinka",
		"mos" => "Mossi",
		"mr" => "Marathi",
		"ms" => "Malay",
		"mt" => "Maltese",
		"mus" => "Muscogee",
		"my" => "Burmese",
		"na" => "Nauruan",
		"nan" => "Hokkien",
		"nb" => "Norwegian Bokmal",
		"nci" => "Nahuatl, Classical",
		"nd" => "North Ndebele",
		"ne" => "Nepali",
		"ng" => "Ndonga",
		"nl" => "Dutch",
		"nn" => "Norwegian Nynorsk",
		"no" => "Norwegian",
		"nr" => "South Ndebele",
		"nso" => "Northern Sotho",
		"nv" => "Navajo",
		"ny" => "Chewa",
		"oc" => "Occitan",
		"oji" => "Ojibwe",
		"om" => "Oromo",
		"or" => "Oriya",
		"orm" => "Oromo",
		"os" => "Ossetian, Ossetic",
		"pa" => "Panjabi, Punjabi",
		"pam" => "Kapampangah",
		"pan" => "Eastern Punjabi",
		"pap" => "Papiamento",
		"pi" => "Pali",
		"pl" => "Polish",
		"pnb" => "Western Punjabi",
		"prs" => "Dari",
		"ps" => "Pashto",
		"pt-br" => "Portuguese Brazillian",
		"pt" => "Portuguese",
		"que" => "Quechua",
		"qvi" => "Quichua, Imbabura Highland",
		"raj" => "Rajasthani",
		"rm" => "Romansh",
		"rn" => "Kirundi",
		"ro" => "Romanian",
		"ru" => "Russian",
		"run" => "Rundi",
		"rup" => "Macedo",
		"ry" => "Rusyn",
		"rw" => "Kinyarwanda",
		"sa" => "Sanskrit",
		"sc" => "Sardinian",
		"sco" => "Scots",
		"sd" => "Sindhi",
		"se" => "Northern Sami",
		"sg" => "Sango",
		"sgn" => "Sign Languages",
		"sh" => "Serbo-Croatian",
		"si" => "Sinhala",
		"sk" => "Slovak",
		"skx" => "Seko Padang",
		"sl" => "Slovenian",
		"sm" => "Samoan",
		"sna" => "Shona",
		"sot" => "Sotho",
		"sa" => "Sanskrit",
		"sq" => "Albanian",
		"sr-latn" => "Serbian (Latin)",
		"sr" => "Serbian",
		"srp" => "Montenegrin",
		"ss" => "Swati",
		"st" => "Southern Sotho",
		"su" => "Sundanese",
		"sv" => "Swedish",
		"swa" => "Swahili",
		"szl" => "Silesian",
		"ta" => "Tamil",
		"tar" => "Tarahumara, Central",
		"te" => "Telugu",
		"tet" => "Tetum",
		"tg" => "Tajik",
		"th" => "Thai",
		"tir" => "Tigrinya",
		"tk" => "Turkmen",
		"tl" => "Tagalog",
		"tlh" => "Klingon",
		"tn" => "Tswana",
		"to" => "Tonga",
		"toj" => "Tojolabal",
		"tr" => "Turkish",
		"ts" => "Tsonga",
		"tsn" => "Tswana",
		"tsz" => "Purepecha",
		"tt" => "Tartar",
		"tw" => "Twi",
		"ty" => "Tahitian",
		"tzh" => "Tzeltal, Oxchuc",
		"tzo" => "Tzotzil, Venustiano Carranza",
		"ug" => "Uyghur",
		"uk" => "Ukrainian",
		"umb" => "Umbundu",
		"ur" => "Urdu",
		"uz" => "Uzbek",
		"ve" => "Venda",
		"vi" => "Vietnamese",
		"vls" => "Flemish",
		"vo" => "Volapuk",
		"wa" => "Walloon",
		"wbl" => "Wakhi",
		"wol" => "Wolof",
		"xho" => "Xhosa",
		"yaq" => "Yaqui",
		"yi" => "Yiddish",
		"yor" => "Yoruba",
		"yua" => "Maya, Yucatan",
		"za" => "Zhuang, Chuang",
		"zam" => "Zapotec, Miahuatlan",
		"zh-cn" => "Chinese Simplified",
		"zh-hk" => "Chinese Traditional (Hong Kong)",
		"zh" => "Chinese Yue",
		"zh-sg" => "Chinese Simplified (Singaporean)",
		"zh-tw" => "Chinese, Traditional",
		"zul" => "Zulu"
	);
	if ( !isset( $iso_langs[ $iso_code ] ) ) {
		return $iso_code;
	} else {
		return $iso_langs[ $iso_code ];
	}
}

function getYouTubeIDFromURL( $url ) {
    $found = preg_match( '/v=([a-zA-Z0-9\-\_]{11})/', $url, $matches );
    if ( $found ) { return $matches[ 1 ]; }
    return null;
}

function getYouTubeVideoProperties( $r ) {
    static $cache;
    $id = isset( $r[ 'id' ] ) ? $r[ 'id' ] : getYouTubeIDFromURL( $r[ 'url' ] );
    if ( isset( $cache[ $id ] ) ) { return $cache[ $id ]; }
    $url = "http://gdata.youtube.com/feeds/api/videos/{$id}?v=2";
    $doc = new DOMDocument;
    $doc->load( $url );
    if( !$doc ) { return null; }
    $result = array(
            'yt:published' => $doc->getElementsByTagName( 'published' )->item( 0 )->nodeValue,
            'yt:updated' => $doc->getElementsByTagName( 'updated' )->item( 0 )->nodeValue,
            'yt:description' => $doc->getElementsByTagName( 'description' )->item( 0 )->nodeValue,
            'yt:name' => $doc->getElementsByTagName( 'name' )->item( 0 )->nodeValue,
            'yt:category' => $doc->getElementsByTagName( 'category' )->item( 0 )->nodeValue,
            'yt:thumbnail' => $doc->getElementsByTagName( 'thumbnail' )->item( 0 )->nodeValue,
            'yt:title' => $doc->getElementsByTagName( 'title' )->item( 0 )->nodeValue,
            'yt:duration' => $doc->getElementsByTagName( 'duration' )->item( 0 )->getAttribute( 'seconds' ),
            'yt:content' => $doc->getElementsByTagName( 'content' )->item( 2 )->getAttribute( 'url' )
        );
    $cache[ $id ] = $result;
    if ( count( $cache > 10000 ) ) { unset( $cache[ key( $cache ) ] ); } # fast version of array_shift
    return $result;
}

function ffprobe( $url ) {
    $pathinfo = pathinfo( $url );
    $result = array();
    $result[ 'format' ] = $pathinfo[ 'extension' ];
    $output = shell_exec( 'ffprobe -of json -v quiet -show_format -show_streams "' . str_replace( 'https', 'http', $url ) . '" 2>&1' );
    return json_decode( $output );
}


function getLanguageInfoByID($apikey, $username, $id, $language ) {
	echo $id . ': Requesting info about the ', $language, ' track... ';
	$cr = curl_init();
	curl_setopt( $cr, CURLOPT_URL,
		'https://www.amara.org/api/' .
		'videos/' . $id . '/' .
		'languages/' . $language . '/'
	);
	curl_setopt( $cr, CURLOPT_RETURNTRANSFER, 1 ); # return string
	curl_setopt( $cr, CURLOPT_SSL_VERIFYPEER, false ); # skip SSL verification. no bueno.
	curl_setopt( $cr, CURLOPT_HTTPHEADER, array(
		"X-api-username: $username",
		"X-apikey: $apikey"
	) );
	$retry = 0;
	do {
	    $info = curl_exec( $cr );
		$retry++; if ( $retry > 10 ) { break; }
		if ( !$info ) { sleep( 2 ); }
	} while ( !$info );
	if ( $info !== false ) { echo "[OK]\n"; }
	else { echo "[FAIL]\n"; echo curl_error( $cr ), "\nAborting..."; curl_close( $cr ); return false;}
	curl_close( $cr );
	return json_decode( $info );
}
