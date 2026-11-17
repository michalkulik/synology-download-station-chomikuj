<?php
/**
 * Chomikuj.pl search module for Synology Download Station.
 *
 * Uses the public file search of chomikuj.pl (the same one the website uses),
 * so searching works without an account. Downloading the results requires the
 * companion file hosting module (chomikuj.host) with a configured account.
 *
 * @license MIT
 */

class SynoDLMSearchChomikuj
{
    const SEARCH_URL = 'https://chomikuj.pl/action/SearchFiles';
    const SITE_URL = 'https://chomikuj.pl';
    const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /**
     * Called by Download Station: configure the cURL handle for the search.
     */
    public function prepare($curl, $keyword)
    {
        $fields = array(
            'FileName' => $keyword,
            'FileType' => 'all',
            'IsGallery' => 'False',
            'submitSearchFiles' => '',
        );

        curl_setopt($curl, CURLOPT_URL, self::SEARCH_URL);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'X-Requested-With: XMLHttpRequest',
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pl-PL,pl;q=0.9,en;q=0.8',
        ));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    }

    /**
     * Called by Download Station with the raw response body.
     *
     * @param object $plugin Download Station plugin object (addResult)
     * @param string $response HTML of the search results page
     * @return int number of results added
     */
    public function parse($plugin, $response)
    {
        if (!is_string($response) || '' === trim($response)) {
            return 0;
        }

        if (false !== stripos($response, 'Just a moment') || false === stripos($response, 'fileItemContainer')) {
            /* Empty search or a Cloudflare interstitial. */
            return 0;
        }

        $added = 0;
        $seen = array();
        $chunks = explode('fileItemContainer', $response);
        array_shift($chunks);

        foreach ($chunks as $chunk) {
            if (!preg_match('/class="[^"]*fileIdContainer[^"]*"[^>]*rel="(\d+)"/', $chunk, $idMatch)) {
                continue;
            }
            $fileId = $idMatch[1];
            if (isset($seen[$fileId])) {
                continue;
            }

            if (!preg_match('/href="(\/[^"]*,' . $fileId . '\.[A-Za-z0-9]{1,8}(?:\([a-z]+\))?)"/', $chunk, $linkMatch)) {
                continue;
            }

            $href = html_entity_decode($linkMatch[1], ENT_QUOTES, 'UTF-8');
            $href = preg_replace('/\([a-z]+\)$/', '', $href);
            $downloadUrl = self::SITE_URL . $href;

            $title = $this->extractTitle($chunk, $href, $fileId);
            $size = $this->extractSize($chunk);
            $category = $this->guessCategory($href);

            $plugin->addResult(
                $title,
                $downloadUrl,
                $size,
                date('Y-m-d'),
                self::SITE_URL . '/',
                '',
                0,
                0,
                $category
            );

            $seen[$fileId] = true;
            $added++;
        }

        return $added;
    }

    private function extractTitle($chunk, $href, $fileId)
    {
        $title = '';

        if (preg_match('/<a[^>]*class="[^"]*expanderHeader[^"]*"[^>]*>/i', $chunk, $anchor)) {
            if (preg_match('/title="([^"]*)"/i', $anchor[0], $m)) {
                $title = $m[1];
            }
        }

        if ('' === $title && preg_match('/<span class="bold">(.*?)<\/span>/is', $chunk, $m)) {
            $title = $m[1];
        }

        if ('' === $title) {
            $path = parse_url($href, PHP_URL_PATH);
            $title = urldecode(basename(is_string($path) ? $path : ''));
        }

        $title = html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8');
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        /* Make sure the extension is visible in the result title. */
        $extension = $this->extension($href);
        if ('' !== $extension && '' !== $title) {
            $length = strlen($extension) + 1;
            if (0 !== strcasecmp(substr($title, -$length), '.' . $extension)) {
                $title .= '.' . $extension;
            }
        }

        if ('' === $title) {
            $title = 'chomikuj_' . $fileId;
        }

        return $title;
    }

    private function extractSize($chunk)
    {
        $raw = '';

        if (preg_match('/class="fileinfo tab".*?<li>\s*<span>([^<]+)<\/span>/is', $chunk, $m)) {
            $raw = $m[1];
        } elseif (preg_match('/<li>\s*<span>([0-9.,]+\s*(?:B|KB|MB|GB|TB))<\/span>/i', $chunk, $m)) {
            $raw = $m[1];
        }

        if ('' === $raw) {
            return 0;
        }

        if (!preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*(B|KB|MB|GB|TB)/i', $raw, $m)) {
            return 0;
        }

        $value = (float)str_replace(',', '.', $m[1]);
        $unit = strtoupper($m[2]);

        $multipliers = array(
            'B' => 1,
            'KB' => 1024,
            'MB' => 1024 * 1024,
            'GB' => 1024 * 1024 * 1024,
            'TB' => 1024 * 1024 * 1024 * 1024,
        );

        return (int)round($value * $multipliers[$unit]);
    }

    private function extension($href)
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }
        if (!preg_match('/\.([A-Za-z0-9]{1,8})$/', $path, $m)) {
            return '';
        }

        return strtolower($m[1]);
    }

    private function guessCategory($href)
    {
        $extension = $this->extension($href);

        $video = array('avi', 'mkv', 'mp4', 'm4v', 'mov', 'wmv', 'flv', 'mpg', 'mpeg', 'ts', 'm2ts', 'webm', 'rmvb', 'rm', '3gp', 'vob');
        $audio = array('mp3', 'flac', 'ogg', 'wav', 'm4a', 'aac', 'wma', 'ape', 'opus');
        $image = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tif', 'tiff');

        if (in_array($extension, $video, true)) {
            return 'video';
        }
        if (in_array($extension, $audio, true)) {
            return 'audio';
        }
        if (in_array($extension, $image, true)) {
            return 'image';
        }

        return 'other';
    }
}