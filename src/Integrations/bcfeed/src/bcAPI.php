<?php
/**
 * Class bcAPI
 *
 * Performs API requests for a given BrightCove account
 * Currently only for getting a list of videos.
 *
 * Reference doc:
 * https://support.brightcove.com/cms-api-video-fields-reference
 */


namespace FranOntanaya;


class bcAPI {
    public $verbose = false;
    protected $bcProxy;
    protected $bcAccount;

    function __construct(bcAccount $bcAccount, bcProxy $bcProxy) {
        $this->bcProxy = $bcProxy;
        $this->bcAccount = $bcAccount;
    }

    function getHeader() {
        $header = [
            "Accept: */*",
            "Referer: {$this->bcProxy->getReferer()}",
            "Content-Type: text/plain;charset=UTF-8",
            "Connection: keep-alive"
        ];
        return $header;
    }

    /**
     * Validates the request options and handles pagination
     */
    function getVideos(array $options) {
        $options['sort'] = $options['sort'] ?? '-published_at';
        $options['limit'] = $options['limit'] ?? '100';
        $optionParams = implode('&', $options);

        $header = $this->getHeader();
        $data = [
            'proxyURL' => $this->bcProxy->getProxy(),
            'account_id' => $this->bcAccount->getAccountId(),
            'client_id' => $this->bcAccount->getClientId(),
            'client_secret' => $this->bcAccount->getClientSecret(),
            'url' => "https://cms.api.brightcove.com/v1/accounts/{$this->bcAccount->getAccountId()}/videos?{$optionParams}",
        ];
        $result = $this->useResource(
            'GET',
            $this->bcProxy->getProxy(),
            [],
            $data
        );
    }

    /**
     * Performs the actual requests
     */
    function request($method, $header, $url, $data = '') {
        $cr = curl_init();
        curl_setopt($cr, CURLOPT_URL, $url);
        curl_setopt($cr, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cr, CURLOPT_VERBOSE, $this->verbose);
        curl_setopt($cr, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cr, CURLOPT_HTTPHEADER, $header);
        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($cr, CURLOPT_POST, 1);
                curl_setopt($cr, CURLOPT_POSTFIELDS, $data);
                break;
            case 'PUT':
                curl_setopt($cr, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($cr, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
                curl_setopt($cr, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            default:
                return null;
        }
        $result = curl_exec($cr);
        curl_close($cr);
        return $result;
    }

    /**
     * Generic paginated call to BC API
     */
    function useResource(string $method, string $url, array $params, array $data, callable $filter = null) {
        $result = [];
        $resultOffset = 0;
        $limit = $data['limit'] ?? 100;
        $header = $this->getHeader();
        // Todo:
        //     reenable when routing for other BC API resources is added
        //     and $url is replaced with a resource name parameter
        //if (isset($data) && $resource['content_type'] === 'json') {
        //    $data = json_encode($data);
        //}
        $debugIterator = 0;
        do {
            $data['offset'] = $resultOffset;
            $response = $this->request('GET', $header, $url, $data);
            $resultPage = json_decode($response);
            if ($method !== 'GET' || json_last_error() !== JSON_ERROR_NONE) {
                // Nothing to loop or it's not JSON, deliver as-is
                return $response;
            }
            // BC API doesn't have a "next" field for paginating
            // so you have to ask for more until exhausting the results
            var_dump($resultPage);
            $result = array_merge($result, $resultPage);
            $resultOffset += $limit;
            $debugIterator++;
        } while ($debugIterator < 20);
        return $result;
    }

}