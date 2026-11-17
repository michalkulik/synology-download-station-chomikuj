<?php
/**
 * Online test of the file hosting module: logs in to the Chomikuj mobile API
 * and asks for a direct download URL.
 *
 *   CHOMIKUJ_USER=login CHOMIKUJ_PASS=haslo \
 *     php tests/host_test.php 'https://chomikuj.pl/<chomik>/<folder>/<plik>,123456789.mp4'
 *
 * Wymaga PHP z rozszerzeniem cURL. Nie pobiera pliku — tylko go rozwiązuje,
 * więc nie zużywa transferu.
 */

require __DIR__ . '/../src/host/chomikuj.php';

$url = isset($argv[1]) ? $argv[1] : '';
$username = getenv('CHOMIKUJ_USER');
$password = getenv('CHOMIKUJ_PASS');

if ('' === $url) {
    fwrite(STDERR, "Użycie: CHOMIKUJ_USER=... CHOMIKUJ_PASS=... php tests/host_test.php '<url pliku>'\n");
    exit(2);
}
if (false === $username || false === $password || '' === $username || '' === $password) {
    fwrite(STDERR, "Ustaw zmienne środowiskowe CHOMIKUJ_USER i CHOMIKUJ_PASS.\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Brak rozszerzenia cURL w PHP.\n");
    exit(2);
}

$module = new SynoFileHostingChomikuj($url, $username, $password, array('name' => 'Chomikuj'));

$verify = $module->Verify();
printf("Verify: %s\n", var_export($verify, true));
if (USER_IS_PREMIUM !== $verify && USER_IS_FREE !== $verify) {
    fwrite(STDERR, "Logowanie nie powiodło się.\n");
    exit(1);
}

$info = $module->GetDownloadInfo();
echo 'GetDownloadInfo: ' . json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

if (empty($info[DOWNLOAD_URL])) {
    fwrite(STDERR, "Brak adresu pobierania.\n");
    exit(1);
}

echo "OK\n";