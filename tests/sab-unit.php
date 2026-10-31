<?php

declare(strict_types=1);

use NnSpotty\Downloader\Endpoint;
use NnSpotty\Downloader\SabRepository;

foreach (['127.0.0.1', '127.1.2.3', '0.0.0.0', '10.1.1.1', '172.16.0.1', '192.168.0.1', '169.254.169.254',
    '100.64.0.1', '100.127.255.255', '198.18.0.1', '192.0.0.8', '192.0.2.1', '198.51.100.1', '203.0.113.1',
    '224.0.0.1', '255.255.255.255', '::1', '::', 'fd00::1', 'fe80::1', 'ff02::1', '::ffff:8.8.8.8', '::ffff:127.0.0.1',
    '2001:db8::1', '2002:0808:0808::1', '2001::1', '2001:20::1', '3fff::1', '192.88.99.1', '64:ff9b::a00:1', 'invalid'] as $address) {
    check(!Endpoint::publicIp($address), 'Niet-publiek/transition adres geblokkeerd: ' . $address);
    rejects(static fn () => Endpoint::checkAddresses([$address], false), 'Gebruiker kan intern adres niet benaderen: ' . $address);
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $address) {
    check(Endpoint::publicIp($address), 'Publiek SABnzbd-adres toegestaan: ' . $address);
}
rejects(static fn () => Endpoint::checkAddresses(['8.8.8.8', '127.0.0.1'], false), 'Gemengd publiek/privaat DNS-resultaat afgewezen');
Endpoint::checkAddresses(['127.0.0.1', '::1', '192.168.1.2'], true);
check(true, 'Admin mag interne hosts gebruiken');
foreach (['file:///etc/passwd', 'ftp://example.com', 'http://name:key@example.com', 'http://example.com/?apikey=secret',
    'http://example.com/#fragment', "http://example.com/\r\nX:a", 'http://example.com/a/../b',
    'http://example.com/%2e%2e', 'http://example.com/api', 'http://example.com\\@127.0.0.1'] as $invalidUrl) {
    rejects(static fn () => Endpoint::normalize($invalidUrl), 'Ongeldig SABnzbd-adres afgewezen');
}
check(Endpoint::normalize(' https://sab.example.com:8080/sabnzbd/ ') === 'https://sab.example.com:8080/sabnzbd',
    'SABnzbd reverse-proxy-prefix behouden, afsluitende slash verwijderd');
check(Endpoint::resolve('http://127.0.0.1:8080/sabnzbd', true)['url'] === 'http://127.0.0.1:8080/sabnzbd/api',
    'Transport voegt /api alleen aan gevalideerd basisadres toe');
rejects(static fn () => Endpoint::resolve('http://127.0.0.1:8080', false), 'Gebruiker wordt voor HTTP-transport geblokkeerd');
rejects(static fn () => SabRepository::scope('reader', 'central', false), 'Centrale scope is alleen voor admins');
rejects(static fn () => SabRepository::scope('reader', 'user:other', true), 'Ook admin kan niet willekeurig een ander persoonlijk account selecteren');
check(SabRepository::scope('reader', 'personal', false) === 'user:reader'
    && SabRepository::scope('admin', 'central', true) === 'central', 'Scopes komen uitsluitend uit ingelogde username en adminrol');
$sabForm = \NnSpotty\UI\SabSettings::render(['url' => 'https://example.com', 'api_key' => 'never-render-this-key',
    'enabled' => true, 'mappings' => ['Films' => 'movies']], '', true, true);
check(!str_contains($sabForm, 'never-render-this-key') && str_contains($sabForm, 'name="api_key" value=""')
    && str_contains($sabForm, 'value="movies"'), 'Instellingen tonen host/mapping maar nooit opgeslagen SABnzbd-key');
check(str_contains($emoticonPage, 'data-download-send=') && str_contains($emoticonPage, 'download-drawer')
    && !str_contains($emoticonPage, '<option value="central:'), 'Gewone detailgebruiker krijgt SABnzbd-knop en paneel zonder centrale optie');
