<?php
namespace AmaraPHP\Formats;
/**
 * Converts parsed SubRip objects to HTML or MS Word 2007 shot logs
 *
 * @author Fran Ontanaya
 * @copyright 2015 Fran Ontanaya
 * @license GPLv3
 */
class ShotLog {
    /**
     * Merge, save and convert a set of captions and descriptions
     *
     * Takes two subtitle objects from delphiki/subrip-file-parser's method getSubs(),
     * merges them, generates a HTML document in the outputDir and converts it to
     * MS Word 2007 using libreoffice in command line mode
     *
     * $credit is unused currently, typically it would be added to brand the document
     * for a specific customer
     */
    function save($captions, $descriptions, $outputDir, $title = "", $credit = "") {
        $shotLog = $this->mergedLog($captions, $descriptions);
        $html = $this->toHTML($title, $shotLog);
        $outputFile = $this->filenameFromTitle($title);
        $htmlOutputPath = $outputDir . '/' . $outputFile . '.html';
        file_put_contents($htmlOutputPath, $html);
        $this->loWriterConvert($htmlOutputPath, $outputDir);
    }

    /**
     * Convert a document to MS Word 2007 using libreoffice
     *
     * Coded for a typical libreoffice install on linux.
     *
     * Note that LibreOffice must not be running already. Conversion fails otherwise.
     *
     * Note also that $file and $outputDir aren't sanitized or validated here
     */
    function loWriterConvert($file, $outputDir) {
        return exec('libreoffice --headless --convert-to docx:"MS Word 2007 XML" ' . $file . ' --outdir ' . $outputDir);
    }

    /**
     * Sanitizes text into a string suitable for filenames
     */
    function filenameFromTitle($title) {
        $fileName = preg_replace('([^\w\s\d\-_~,;:\[\]\(\).])', '', $title);
        $fileName = preg_replace('([\.]{2,})', '', $fileName);
        return $filename;
    }

    /**
     * Produce a time slot key from the full timing
     *
     * This rule is used to fuzzy match captions and descriptions
     * in case the description's timing is not exactly the same as the caption.
     *
     * This could be done more accurately by going through both lists
     * and matching captions with the nearest description under ~750 ms.
     *
     * If Amara adds the option to lock synchronization slots from the captions
     * so they can't be accidentally modified in the audio description,
     * fuzzy matching won't be then necessary.
     */
    function timeHash($timecode) {
        return $timecode / 750;
    }

    /**
     * Merge captions and descriptions in a sorted array
     *
     * Takes two subtitle objects from delphiki/subrip-file-parser's method getSubs()
     * and merges them taking into account that:
     *
     * - Descriptions and subtitles that are less than ~800 ms apart should be paired.
     *   This tries to account for captioners altering the synchronization
     *   of the corresponding description for whatever reason, since currently this
     *   can't be locked down
     * - Rounding timings to match descriptions and captions can lead to multiple captions
     *   and descriptions having the same key, so we only do it when the slot is still free
     * - HTML tags are requested to be stripped from the captions
     * - To avoid overwriting a slot in case of unforeseen bugs, we intialize and append
     *   the caption and description texts for a given time slot, rather than simply assigning
     */
    function mergedLog($captions, $descriptions) {
        $shotLog = array();
        foreach ($captions as $caption) {
            $timeSlot = $this->timeHash($caption->getStart());
            if (!isset($shotLog[round($timeSlot)]['caption'])) {
                $timeSlot = round($timeSlot);
            }
            if (!isset($shotLog[$timeSlot]['caption'])) {
                $shotLog[$timeSlot]['caption'] = '';
            }
            if (!isset($shotLog[$timeSlot]['description'])) {
                $shotLog[$timeSlot]['description'] = '';
            }
            $shotLog[$timeSlot]['caption'] .= strip_tags($caption->getText());
            $shotLog[$timeSlot]['start'] = $caption->getStartTC();
            $shotLog[$timeSlot]['stop'] = $caption->getStopTC();
        }
        unset($timeSlot);
        foreach ($descriptions as $description) {
            $timeSlot = $this->timeHash($description->getStart());
            if (empty($shotLog[$timeSlot]['description'])) {
                $timeSlot = round($timeSlot);
            }
            if (!isset($shotLog[$timeSlot]['caption'])) {
                $shotLog[$timeSlot]['caption'] = '';
            }
            if (!isset($shotLog[$timeSlot]['description'])) {
                $shotLog[$timeSlot]['description'] = '';
            }
            $shotLog[$timeSlot]['description'] .= $description->getText();
            if (!isset($shotLog[$timeSlot]['start'])) {
                $shotLog[$timeSlot]['start'] = $description->getStartTC();
                $shotLog[$timeSlot]['stop'] = $description->getStopTC();
            }
        }
        ksort($shotLog);
        return $shotLog;
    }

    /**
     * Output the shot log as a HTML table
     *
     * Note that LibreOffice ignores the vertical align property when converting to MS Word
     */
    function toHTMLTable($shotLog) {
	    $table = '
        <table width="700" cellpadding="6" style="border:1px solid #000; border-collapse:collapse;">
            <tr>
	            <td width="50%" style="border:1px solid #000;vertical-align:top;text-align:left;">Captions</td>
	            <td width="50%" style="border:1px solid #000;vertical-align:top;text-align:left;">Shotlog</td>
            </tr>';
        foreach ($shotLog as $index=>$entry) {
            $table .= "
            <tr>
                <td colspan='2' style='border:1px solid #000;vertical-align:top;text-align:left;'>{$entry['start']} --> {$entry['stop']}</td>
            </tr>
            <tr>
                <td style='border:1px solid #000;vertical-align:top;text-align:left;'>{$entry['caption']}</td>
                <td style='border:1px solid #000;vertical-align:top;text-align:left;'>{$entry['description']}</td>
            </tr>";
        }
    	$table .= '
    	</table>';
	    return $table;
    }

    /**
     * Basic HTML wrapper for the shot log table
     */
    function toHTML($title, $shotLog) {
        $html = "<html><head><meta charset='UTF-8'></head><body>\n";
        $html .= "<p>{$title}</p>\n";
        $html .= $this->toHTMLTable($shotLog);
        $html .= "\n</body></html>";
        return $html;
    }
}
