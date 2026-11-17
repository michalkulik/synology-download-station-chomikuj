<?php
/**
 * Chomikuj.pl file hosting module for Synology Download Station.
 *
 * Downloads are resolved through the Chomikuj mobile API (the same one the
 * official Android application uses). The API key is obtained by logging in
 * with the account configured in Download Station and cached in /tmp, so the
 * user does not have to log in for every download.
 *
 * @license MIT
 */

/* Constants normally provided by Download Station. The guards keep the file
 * usable from a plain CLI test harness. */
if (!defined('DOWNLOAD_URL')) {
    define('DOWNLOAD_URL', 'downloadurl');
    define('DOWNLOAD_FILENAME', 'filename');
    define('DOWNLOAD_ERROR', 'error');
    define('DOWNLOAD_ISPARALLELDOWNLOAD', 'isparalleldownload');
    define('DOWNLOAD_ISQUERYAGAIN', 'isqueryagain');
    define('LOGIN_FAIL', 4);
    define('USER_IS_FREE', 5);
    define('USER_IS_PREMIUM', 6);
    define('ERR_UNKNOWN', 1);
    define('ERR_BROKEN_LINK', 102);
    define('ERR_FILE_NO_EXIST', 114);
    define('ERR_REQUIRED_PREMIUM', 115);
    define('ERR_NOT_SUPPORT_TYPE', 116);
    define('ERR_REQUIRED_ACCOUNT', 124);
    define('ERR_TRY_IT_LATER', 125);
}

class SynoFileHostingChomikuj
{
    const API_BASE = 'https://mobile.chomikuj.pl';
    const API_USER_AGENT = 'android/3.8.4 (a675e674-0def-4cbc-a955-ac6c6f99707b; Google Pixel 6)';
    const API_SALT = 'wzrwYua$.DSe8suk!`\'2';
    const API_TIMEOUT = 30;

    /* Cache of the mobile API key, shared with nothing else on the box. */
    const CACHE_PATH = '/tmp/chomikuj_synology_apikey.json';
    const LOG_PATH = '/tmp/chomikuj_synology.log';

    private $url;
    private $username;
    private $password;
    private $hostinfo;
    private $apikey = null;

    public function __construct($url, $username, $password, $hostinfo)
    {
        $this->url = (string)$url;
        $this->username = (string)$username;
        $this->password = (string)$password;
        $this->hostinfo = is_array($hostinfo) ? $hostinfo : array();
    }

    /* ------------------------------------------------------------------ */
    /* Public Download Station API                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Called by Download Station when the user saves the account in
     * Settings -> File Hosting.
     */
    public function Verify()
    {
        if ('' === $this->username || '' === $this->password) {
            $this->log('Verify: brak nazwy użytkownika lub hasła');
            return LOGIN_FAIL;
        }

        if (true === $this->login()) {
            $this->log('Verify: zalogowano jako ' . $this->username);
            return USER_IS_PREMIUM;
        }

        return LOGIN_FAIL;
    }

    /**
     * Called by Download Station for every added link. Returns the array
     * understood by Download Station, holding a direct, signed file URL.
     */
    public function GetDownloadInfo()
    {
        $info = array();

        $fileId = $this->extractFileId($this->url);
        if (false === $fileId) {
            $this->log('GetDownloadInfo: nie rozpoznano id pliku w URL: ' . $this->url);
            $info[DOWNLOAD_ERROR] = ERR_BROKEN_LINK;
            return $info;
        }

        if (false === $this->ensureApiKey()) {
            $info[DOWNLOAD_ERROR] = ERR_REQUIRED_ACCOUNT;
            return $info;
        }

        $result = $this->requestFileUrl($fileId);

        /* The API key may have been revoked or expired: log in again once. */
        if ('unauthorized' === $result && true === $this->login()) {
            $result = $this->requestFileUrl($fileId);
        }

        if (is_array($result)) {
            $info[DOWNLOAD_URL] = $result['url'];
            $info[DOWNLOAD_FILENAME] = $result['filename'];
            $info[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
            /* Ask Download Station to re-run the module (and thus generate a
             * fresh signed URL) if the link expires while queued. */
            $info[DOWNLOAD_ISQUERYAGAIN] = 1;
            $this->log('GetDownloadInfo: przygotowano link dla pliku ' . $fileId);
            return $info;
        }

        switch ($result) {
            case 'no_transfer':
                $this->log('GetDownloadInfo: brak transferu na koncie dla pliku ' . $fileId);
                $info[DOWNLOAD_ERROR] = ERR_REQUIRED_PREMIUM;
                break;
            case 'not_found':
                $this->log('GetDownloadInfo: plik ' . $fileId . ' nie istnieje');
                $info[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
                break;
            case 'unauthorized':
                $this->log('GetDownloadInfo: brak autoryzacji (sprawdź login/hasło)');
                $info[DOWNLOAD_ERROR] = ERR_REQUIRED_ACCOUNT;
                break;
            default:
                $this->log('GetDownloadInfo: błąd tymczasowy dla pliku ' . $fileId);
                $info[DOWNLOAD_ERROR] = ERR_TRY_IT_LATER;
                break;
        }

        return $info;
    }

    /* ------------------------------------------------------------------ */
    /* Chomik mobile API                                                  */
    /* ------------------------------------------------------------------ */

    private function login()
    {
        $response = $this->apiRequest(
            'POST',
            '/api/v3/account/login',
            array(),
            array('AccountName' => $this->username, 'Password' => $this->password),
            null
        );

        if (!is_array($response)) {
            $this->log('login: brak odpowiedzi API (' . $response . ')');
            return false;
        }

        if (!empty($response['ApiKey'])) {
            $this->apikey = $response['ApiKey'];
            $this->saveCachedKey($response['ApiKey']);
            return true;
        }

        $code = isset($response['Code']) ? (int)$response['Code'] : -1;
        if (404 === $code) {
            $this->log('login: konto "' . $this->username . '" nie istnieje');
        } elseif (401 === $code) {
            $this->log('login: błędne hasło dla konta "' . $this->username . '"');
        } else {
            $this->log('login: nieudane logowanie, kod ' . $code);
        }

        $this->apikey = null;
        $this->clearCachedKey();
        return false;
    }

    private function ensureApiKey()
    {
        if (!empty($this->apikey)) {
            return true;
        }

        $cached = $this->loadCachedKey();
        if (false !== $cached) {
            $this->apikey = $cached;
            return true;
        }

        return $this->login();
    }

    /**
     * @return array|string array with url+filename on success, or one of the
     *                    strings no_transfer / not_found / unauthorized /
     *                    error
     */
    private function requestFileUrl($fileId)
    {
        $response = $this->apiRequest(
            'GET',
            '/api/v3/files/download',
            array('fileId' => $fileId),
            null,
            $this->apikey
        );

        if (!is_array($response)) {
            return $response;
        }

        $code = isset($response['Code']) ? (int)$response['Code'] : -1;

        if (0 === $code || 605 === $code) {
            if (!empty($response['FileUrl'])) {
                return array(
                    'url' => $response['FileUrl'],
                    'filename' => $this->buildFilename($this->url, $fileId),
                );
            }
            if (605 === $code) {
                return 'no_transfer';
            }
        }

        switch ($code) {
            case 401:
                $this->apikey = null;
                $this->clearCachedKey();
                return 'unauthorized';
            case 404:
            case 604: /* FileId points to a directory */
                return 'not_found';
            case 605:
                return 'no_transfer';
        }

        $this->log('files/download: kod ' . $code . ', komunikat: ' . (isset($response['Message']) ? $response['Message'] : '-'));
        return 'error';
    }

    /**
     * Performs one signed request against the mobile API.
     *
     * @return array|string decoded JSON array on success, error string on
     *                    failure ('unauthorized', 'network', 'cloudflare',
     *                    'invalid_json')
     */
    private function apiRequest($method, $path, $query, $body, $apikey)
    {
        $pathQuery = $path;
        if (!empty($query)) {
            $pathQuery .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $bodyText = '';
        if (null !== $body) {
            $bodyText = json_encode($body);
            if (false === $bodyText) {
                return 'invalid_json';
            }
        }

        /* The API signs "<path?query><body><salt>" with the leading slash cut
         * off. */
        $token = md5(substr($pathQuery . $bodyText . self::API_SALT, 1));

        $headers = array(
            'Accept: application/json',
            'Token: ' . $token,
        );
        if (!empty($apikey)) {
            $headers[] = 'Api-Key: ' . $apikey;
        }
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        $curl = curl_init(self::API_BASE . $pathQuery);
        if (false === $curl) {
            return 'network';
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::API_TIMEOUT);
        curl_setopt($curl, CURLOPT_USERAGENT, self::API_USER_AGENT);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        if ('POST' === strtoupper($method)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $bodyText);
        }

        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (false === $raw) {
            $this->log('apiRequest: błąd sieci: ' . $curlError);
            return 'network';
        }

        /* Cloudflare challenge is a HTML page instead of JSON. */
        if (false !== stripos($raw, '<html') || false !== stripos($raw, 'Just a moment')) {
            if (404 === $status) {
                return array('Code' => 404, 'Message' => 'Not Found');
            }
            $this->log('apiRequest: odpowiedź nie jest JSON-em (Cloudflare?)');
            return 'cloudflare';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if (404 === $status) {
                return array('Code' => 404, 'Message' => 'Not Found');
            }
            $this->log('apiRequest: nieprawidłowy JSON (HTTP ' . $status . ')');
            return 'invalid_json';
        }

        if (401 === $status) {
            return 'unauthorized';
        }

        return $decoded;
    }

    /* ------------------------------------------------------------------ */
    /* API key cache                                                      */
    /* ------------------------------------------------------------------ */

    private function loadCachedKey()
    {
        if (!is_file(self::CACHE_PATH)) {
            return false;
        }

        $raw = @file_get_contents(self::CACHE_PATH);
        if (false === $raw) {
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['apikey'])) {
            return false;
        }

        /* A different account was configured in the meantime. */
        if (!isset($data['account']) || 0 !== strcasecmp($data['account'], $this->username)) {
            return false;
        }

        return $data['apikey'];
    }

    private function saveCachedKey($apikey)
    {
        $payload = json_encode(array(
            'account' => $this->username,
            'apikey' => $apikey,
            'time' => time(),
        ));

        @file_put_contents(self::CACHE_PATH, $payload, LOCK_EX);
        @chmod(self::CACHE_PATH, 0600);
    }

    private function clearCachedKey()
    {
        if (is_file(self::CACHE_PATH)) {
            @unlink(self::CACHE_PATH);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Extracts the Chomikuj file id from a regular file page URL
     * (https://chomikuj.pl/user/folder/name,123456.mp4) or from the short
     * form used by the mobile API (/file/123456).
     */
    private function extractFileId($url)
    {
        if (preg_match('/\/file\/(\d{3,})/', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(?:[?&]fileId=)(\d{3,})/i', $url, $matches)) {
            return $matches[1];
        }
        if (preg_match('/,(\d{3,})(?:\.[A-Za-z0-9]{1,8})?(?:\([a-z]+\))?(?:[?#].*)?$/', $url, $matches)) {
            return $matches[1];
        }

        return false;
    }

    private function buildFilename($url, $fileId)
    {
        $basename = '';
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && '' !== $path) {
            $basename = urldecode(basename($path));
        }

        $name = '';
        if (preg_match('/^(.*),\d+\.([A-Za-z0-9]{1,8})(?:\([a-z]+\))?$/', $basename, $matches)) {
            $name = $matches[1] . '.' . $matches[2];
        }

        if ('' === $name) {
            $name = 'chomikuj_' . $fileId;
        }

        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]/', '', $name);
        $name = trim($name);
        if ('' === $name || '.' === $name || '..' === $name) {
            $name = 'chomikuj_' . $fileId;
        }

        return $name;
    }

    private function log($message)
    {
        $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        @file_put_contents(self::LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    }
}