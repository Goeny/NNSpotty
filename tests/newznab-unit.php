<?php

declare(strict_types=1);

use NnSpotty\Newznab\Categories;
use NnSpotty\Usenet\SpotNzbService;

$categoryCases = [
    ['Beeld', ['1a09', '1z00'], [2000, 2040]],
    ['Beeld', ['1a09', '1z00', '1d13'], [3000, 3020]],
    ['Beeld', ['1a15', '1z01'], [5000, 5045]],
    ['Beeld', ['1a06', '1z00'], [2000, 2040, 2050]],
    ['Beeld', ['1a03', '1z01'], [5000, 5030]],
    ['Beeld', ['1a09', '1d29', '1z01'], [5000, 5040, 5070]],
    ['Beeld', ['1a09', '1d18', '1z01'], [5000, 5040, 5060]],
    ['Beeld', ['1a09', '1d75'], [6000, 6040]],
    ['Beeld', ['1a05', '1z02'], [7000, 7020]],
    ['Beeld', ['1a11', '1z02', '1d31'], [7000, 7030]],
    ['Geluid', ['2a00'], [3000, 3010]],
    ['Geluid', ['2a08'], [3000, 3040]],
    ['Geluid', ['2a00', '2z03'], [3000, 3030]],
    ['Spellen', ['3a10'], [1000, 1010]],
    ['Spellen', ['3a17'], [1000, 1180]],
    ['Spellen', ['3a18'], [1000, 1140]],
    ['Spellen', ['3a00', '3b00'], [4000, 4050, 4020]],
    ['Spellen', ['3a01'], [4000, 4050, 4030]],
    ['Spellen', ['3a14'], [4000, 4050, 4060]],
    ['Spellen', ['3a15'], [4000, 4050, 4070]],
    ['Applicaties', ['4a01'], [4000, 4030]],
    ['Applicaties', ['4a07'], [4000, 4070]],
    ['Beeld', ['1a12', '1z04'], [8000, 8010]],
];
foreach ($categoryCases as [$head, $codes, $expected]) {
    $actual = Categories::forSpot($head, $codes);
    sort($actual);
    sort($expected);
    check($actual === $expected, 'Standaard Newznab-mapping: ' . $head . ' ' . implode(',', $codes));
    check(count(array_diff($actual, array_keys(Categories::definitions()))) === 0, 'Iedere toegewezen categorie staat in caps');
}
$nzb = Fixtures::nzb();
$compressed = gzdeflate($nzb);
$escaped = strtr($compressed, ["\0" => '=A', "\r" => '=B', "\n" => '=C', '=' => '=D']);
check(SpotNzbService::decode(chunk_split($escaped, 16, "\r\n")) === $nzb, 'NZB binaire escaping en raw-DEFLATE worden in juiste volgorde gedecodeerd');
$withDtd = str_replace('<nzb ', '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd"><nzb ', $nzb);
check(SpotNzbService::validate($withDtd) === $nzb, 'Conventionele NZB DTD wordt verwijderd zonder externe resource te laden');
$invalidNzbs = [
    '',
    '<nzb>',
    '<nzb/>',
    str_replace('<nzb ', '<!DOCTYPE nzb SYSTEM "file:///private"><nzb ', $nzb),
    str_replace('<nzb ', '<!DOCTYPE nzb [<!ENTITY x SYSTEM "https://example.invalid">]><nzb ', $nzb),
    str_replace('http://www.newzbin.com/DTD/2003/nzb', 'https://example.invalid/nzb', $nzb),
    str_replace(['<nzb xmlns=', '</nzb>'], ['<bad:nzb xmlns:bad=', '</bad:nzb>'], str_replace('http://www.newzbin.com/DTD/2003/nzb', 'https://example.invalid/nzb', $nzb)),
    str_replace('<segment ', '<segment xmlns="https://example.invalid/nzb" ', $nzb),
    str_replace('number="1"', 'number="0"', $nzb),
    str_replace('bytes="1234"', 'bytes="-1"', $nzb),
    str_replace('binary-part@example.invalid', 'invalid', $nzb),
    str_replace('<groups><group>alt.binaries.test</group></groups>', '', $nzb),
];
foreach ($invalidNzbs as $index => $invalidNzb) {
    try {
        SpotNzbService::validate($invalidNzb);
        check(false, 'Ongeldige NZB moet falen: ' . $index);
    } catch (RuntimeException $exception) {
        check(true, 'Onvolledige of onveilige NZB faalt expliciet: ' . $index);
    }
}
foreach (['not-deflate', gzdeflate(str_repeat('a', 16 * 1024 * 1024))] as $invalidCompressed) {
    try {
        SpotNzbService::decode($invalidCompressed);
        check(false, 'Ongeldige of te grote decompressie moet falen');
    } catch (RuntimeException $exception) {
        check(true, 'Defecte of te grote decompressie geeft expliciete fout');
    }
}
check(str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($spot, false), '/spots/nzb?key=' . $spot['id']), 'Detail bevat een werkende NZB-downloadroute');
$withoutNzb = $spot;
$withoutNzb['nzb_segments'] = [];
check(!str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($withoutNzb, true), '/spots/nzb?'), 'Geen NZB-knop zonder segmentverwijzing');
