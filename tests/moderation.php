<?php

declare(strict_types=1);

use NnSpotty\Moderation\SpotterIdentity;
use NnSpotty\Moderation\SpotterLists;
use NnSpotty\Usenet\SpotnetParser;

$identityKey = Fixtures::signingKey();
$identityDetails = openssl_pkey_get_details($identityKey);
$identityHash = hash('sha256', $identityDetails['rsa']['n']);
$identityHeaders = Fixtures::signedHeaders('signed@example.invalid', $identityKey);
$identitySpot = SpotnetParser::spot('signed@example.invalid', $identityHeaders);
check($identitySpot['signature_state'] === 'verified' && $identitySpot['spotter_key'] === $identityHash,
    'RSA/SHA1 Spotnet-message-ID met -s/-p en ontbrekende base64padding levert geverifieerde sleutelfingerprint');
$identitySpoof = str_replace('signed@example.invalid', 'copied@example.invalid', $identityHeaders);
check(SpotnetParser::spot('copied@example.invalid', $identitySpoof)['signature_state'] === 'invalid',
    'Gekopieerde handtekening voor ander message-ID levert geen identiteit');
check(SpotnetParser::spot('unsigned@example.invalid', Fixtures::headers('unsigned@example.invalid'))['signature_state'] === 'missing',
    'Legacy zonder sleutel/handtekening blijft bruikbaar zonder bevestigde identiteit');
$badExponent = str_replace('<Exponent>AQAB</Exponent>', '<Exponent>AQ==</Exponent>', $identityHeaders);
check(SpotnetParser::spot('signed@example.invalid', $badExponent)['spotter_key'] === null,
    'Aanvaller kan een modulus op de lijst niet met eigen exponent als identiteit gebruiken');
foreach ([
    $identityHeaders . "\r\nX-User-Key: <RSAKeyValue/>",
    str_replace('<RSAKeyValue>', '<!DOCTYPE RSAKeyValue [<!ENTITY attack SYSTEM "file:///no">]><RSAKeyValue>', $identityHeaders),
    str_replace('<Modulus>', '<Modulus>invalid!', $identityHeaders),
    str_replace('X-User-Signature: ', 'X-User-Signature: invalid!', $identityHeaders),
] as $invalidIdentity) {
    check(SpotnetParser::spot('signed@example.invalid', $invalidIdentity)['signature_state'] === 'invalid',
        'Ongeldige of ambigue sleutelheader wordt behouden maar nooit geverifieerd');
}
$identityList = '<Keys><Key Name="Niet de posternaam">' . base64_encode($identityDetails['rsa']['n']) . '</Key></Keys>';
check(SpotterLists::parse($identityList) === [$identityHash => 'Niet de posternaam'],
    'Spotlist koppelt op modulus, niet op onbevestigde displaynaam');
check(SpotterLists::parse(str_replace('</Keys>', '<Key Name="Kapotte bronvermelding">invalid!</Key></Keys>', $identityList), $keyWarnings)
    === [$identityHash => 'Niet de posternaam'] && $keyWarnings === ['Kapotte bronvermelding'],
    'Gemengde echte feed bewaart geldige sleutels en rapporteert ongeldige vermeldingen expliciet');
check(SpotterLists::parse(str_replace('</Keys>', '<Key Name="Tweede naam">'
    . base64_encode("\0" . $identityDetails['rsa']['n']) . '</Key></Keys>', $identityList)) === [$identityHash => 'Tweede naam'],
    'Dubbele sleutels met voorloopnul worden canoniek samengevoegd');
foreach (['<Keys/>', '<Keys><Other/></Keys>', '<Keys><Key Name="x">bad</Key></Keys>',
    '<!DOCTYPE Keys [<!ENTITY x SYSTEM "file:///no">]><Keys/>', '<Keys xmlns="urn:unexpected"/>'] as $invalidList) {
    rejects(static fn () => SpotterLists::parse($invalidList), 'Ongeldige Spotlist wordt expliciet afgewezen');
}
$whiteIdentitySpot = $identitySpot + ['spotter_status' => 'whitelist'];
check(str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($whiteIdentitySpot, true), '>Whitelist</span>')
    && str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($whiteIdentitySpot, false), 'Geen inhoudscontrole.')
    && str_contains(\NnSpotty\UI\SpotsPageRenderer::rows([$whiteIdentitySpot], ['title', 'poster']), '>Whitelist</span>'),
    'Whitelistbadge staat bij spotter in overzicht, preview en volledige detail');
check(!str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($identitySpot, false), '>Whitelist</span>')
    && str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($identitySpot, false), 'Sleutelfingerprint (SHA256)'),
    'Geldige identiteit zonder whitelist geeft technische sleutelinfo maar geen whitelistclaim');
