<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/Fixtures.php';

use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\UI\SpotsPageRenderer;
use NnSpotty\Usenet\SpotnetOverviewCommand;
use NnSpotty\Usenet\SpotnetParser;
use Rvdv\Nntp\Response\MultiLineResponse;
use Rvdv\Nntp\Response\Response;

$passed = 0;
function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function rejects(callable $call, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException | UnexpectedValueException $exception) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    check(SpotnetParser::groups('') === ['free.pt' => 'spots', 'free.usenet' => 'comments'], 'Correcte Spotnet standaardgroepen');
    check(array_values(SpotnetParser::groups('comments:free.usenet,spots:free.pt')) === ['spots', 'comments'], 'Spots worden voor reacties verwerkt');
    rejects(static fn () => SpotnetParser::groups('movies:alt.binaries.movies'), 'Geen generieke header-discovery');
    rejects(static fn () => SpotnetParser::groups('0'), 'Niet-lege ongeldige groepsconfiguratie krijgt geen stille defaults');
    rejects(static fn () => SpotnetParser::groups('spots:free.pt,comments:free.ptd'), 'Verkeerde historische reactiegroep afgewezen');
    rejects(static fn () => SpotnetParser::groups('spots:free.pt,comments:free.pt'), 'Dubbele groepsrollen afgewezen');
    rejects(static fn () => SpotnetParser::messageId("spot@example.invalid\r\nQUIT"), 'Geen NNTP commando-injectie via message-ID');
    rejects(static fn () => SpotnetParser::messageId('spot@dom@invalid'), 'Dubbel @ afgewezen');
    rejects(static fn () => SpotnetParser::messageId('spöt@example.invalid'), 'Message-ID moet ASCII zijn');

    $spot = SpotnetParser::spot('spot-one@example.invalid', Fixtures::headers());
    check($spot !== null && $spot['title'] === 'Een individuele testspot', 'Herhaalde X-XML headers worden samengevoegd');
    check($spot['description'] === 'Een omschrijving met [b]opmaak[/b] en <script>onveilig</script>.', 'Volledige omschrijving uit XML');
    check($spot['category'] === 'Beeld' && $spot['size'] === 4294967296, 'Categorie en grootte uit XML, niet uit overview');
    check($spot['image_segments'] === ['image@example.invalid'] && $spot['nzb_segments'] === ['nzb@example.invalid'], 'Afbeelding- en NZB-verwijzingen bewaard');
    $other = SpotnetParser::spot('spot-two@example.invalid', Fixtures::headers('spot-two@example.invalid'));
    check($other['id'] !== $spot['id'], 'Gelijke titels blijven afzonderlijke spots');
    check(SpotnetParser::spot('other@example.invalid', "Subject: Een gewone header\r\nDate: " . gmdate('r')) === null, 'Gewone headers worden geen spots');
    foreach (['1' => 'Beeld', '2' => 'Geluid', '3' => 'Spellen', '4' => 'Applicaties'] as $number => $category) {
        $parsed = SpotnetParser::spot('category@example.invalid', Fixtures::headers('category@example.invalid', Fixtures::xml(category: (string) $number)));
        check($parsed['category'] === $category, 'Spotnet categorie ' . $number);
    }
    $legacy = str_replace(['<Key>7</Key>', '<Category>1<'], ['<Key>1</Key>', '<Category>0<'], Fixtures::xml());
    check(SpotnetParser::spot('legacy@example.invalid', Fixtures::headers('legacy@example.invalid', $legacy))['category'] === 'Beeld', 'Legacy categorie met Key=1');
    $remote = str_replace('<Image Width="1" Height="1"><Segment>image@example.invalid</Segment></Image>', '<Image>https://example.invalid/cover.jpg</Image>', Fixtures::xml());
    check(SpotnetParser::spot('remote@example.invalid', Fixtures::headers('remote@example.invalid', $remote))['image_url'] === 'https://example.invalid/cover.jpg', 'Oude afbeelding-URL ondersteund');
    $unsafe = str_replace('https://example.invalid/cover.jpg', 'javascript:alert(1)', $remote);
    check(SpotnetParser::spot('remote@example.invalid', Fixtures::headers('remote@example.invalid', $unsafe))['image_url'] === '', 'Geen uitvoerbare afbeelding-URL');
    rejects(static fn () => SpotnetParser::spot('bad@example.invalid', Fixtures::headers('bad@example.invalid', '<Spotnet><Posting>')), 'Corrupte XML wordt gemeld');
    rejects(static fn () => SpotnetParser::spot('bad@example.invalid', Fixtures::headers('bad@example.invalid', '<!DOCTYPE Spotnet [<!ENTITY secret SYSTEM "file:///private">]>' . Fixtures::xml())), 'Externe XML entiteiten geweigerd');
    rejects(static fn () => SpotnetParser::spot('bad@example.invalid', Fixtures::headers('bad@example.invalid', str_repeat('a', 51201))), 'XML groottelimiet');
    rejects(static fn () => SpotnetParser::spot('bad@example.invalid', Fixtures::headers('bad@example.invalid', Fixtures::xml(title: ''))), 'Spots zonder titel worden afgewezen');
    rejects(static fn () => SpotnetParser::spot('wrong@example.invalid', Fixtures::headers()), 'Verkeerd artikel message-ID wordt afgewezen');
    check(SpotnetParser::spot('entities@example.invalid', Fixtures::headers('entities@example.invalid', Fixtures::xml(title: 'Film &amp;amp; Serie')))['title'] === 'Film & Serie', 'HTML entiteiten binnen Spotnet XML worden leesbare tekst');

    $comment = SpotnetParser::comment('comment@example.invalid', Fixtures::comment());
    check($comment['spot_id'] === $spot['id'] && $comment['poster'] === 'Reageerder', 'Reacties via References aan spot gekoppeld');
    $fallback = SpotnetParser::comment('spot-one.1.2.3@example.invalid', Fixtures::comment(''));
    check($fallback['spot_id'] === $spot['id'], 'Spotnet comment-ID fallback zonder References');
    $encoded = "Date: " . gmdate('r') . "\r\nFrom: =?UTF-8?B?UmVhZ2VlcmRlcg==?= <a@example.invalid>\r\nReferences: <spot-one@example.invalid>\r\n"
        . "Content-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode("caf\xe9");
    check(SpotnetParser::comment('encoded@example.invalid', $encoded)['body'] === 'café', 'MIME encoding en charset van reacties');
    rejects(static fn () => SpotnetParser::comment('bad@example.invalid', "Date: " . gmdate('r')), 'Reactie zonder body afgewezen');
    $bytes = "\0\r\n=AC=DC==";
    $encodedBytes = strtr($bytes, ["\0" => '=A', "\r" => '=B', "\n" => '=C', '=' => '=D']);
    check(SpotnetParser::binary(chunk_split($encodedBytes, 4, "\r\n")) === $bytes, 'Spotnet binaire escaping en regelafbrekingen');

    $format = ['Subject' => false, 'From' => false, 'Date' => false, 'Message-ID' => false, 'References' => false, ':bytes' => false, ':lines' => false];
    $command = new SpotnetOverviewCommand(700, 900, $format);
    $response = new MultiLineResponse(Response::createFromString("224 Overview follows\r\n"), ["812\tTitel\tPoster\t" . gmdate('r') . "\t<spot@example.invalid>\t\t123\t5"]);
    $rows = $command->onOverviewInformationFollows($response);
    check($rows[0]['number'] === '812' && $rows[0]['message-id'] === '<spot@example.invalid>', 'Echte artikelnummers, geen array-indexen');
    $libraryFormat = (new \Rvdv\Nntp\Command\OverviewFormatCommand())->onInformationFollows(
        new MultiLineResponse(Response::createFromString("215 Overview format\r\n"), ['Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines'])
    );
    check((new SpotnetOverviewCommand(700, 900, $libraryFormat))->onOverviewInformationFollows($response)[0]['message-id'] === '<spot@example.invalid>', 'php-nntp overview-veldnamen worden genormaliseerd');
    check($command->onNoArticlesSelected(Response::createFromString("420 No articles selected\r\n")) === [], 'Verlopen lege ranges zijn toegestaan');
    $shortRows = $command->onOverviewInformationFollows(new MultiLineResponse(
        Response::createFromString("224 Overview follows\r\n"),
        ["812\tTitel\tPoster\t" . gmdate('r') . "\t<spot@example.invalid>", "813\tAfgebroken", "814\tTitel\tPoster\t" . gmdate('r') . "\t<next@example.invalid>\t\t1\t2"]
    ));
    check(count($shortRows) === 3 && $shortRows[0]['references'] === '' && !isset($shortRows[0]['_overview_error']), 'Ontbrekende optionele overview-velden blokkeren een spot niet');
    check(str_contains($shortRows[1]['_overview_error'], 'regel 2 (2/8 velden)')
        && str_contains($shortRows[1]['_overview_error'], 'date ontbreekt'), 'Afgebroken regel heeft bruikbare diagnostiek zonder batch-afbreking');
    check($shortRows[2]['message-id'] === '<next@example.invalid>' && !isset($shortRows[2]['_overview_error']), 'Geldige regels na een corrupte regel blijven behouden');
    $extended = new SpotnetOverviewCommand(700, 900, $format + ['Xref' => true]);
    check(!isset($extended->onOverviewInformationFollows($response)[0]['_overview_error']), 'Ontbrekende aangekondigde Xref:full is optioneel');
    $badOptional = $extended->onOverviewInformationFollows(new MultiLineResponse(
        Response::createFromString("224 Overview follows\r\n"),
        ["812\tTitel\tPoster\t" . gmdate('r') . "\t<id@example.invalid>\t\t1\t2\tBroken"]
    ));
    check(isset($badOptional[0]['_overview_warning']) && !isset($badOptional[0]['_overview_error']),
        'Corrupt optioneel veld wordt gemeld zonder een bruikbare spot te verliezen');
    $badNumber = $command->onOverviewInformationFollows(new MultiLineResponse(
        Response::createFromString("224 Overview follows\r\n"), ["wrong\tTitel\tPoster\t" . gmdate('r') . "\t<id@example.invalid>"]
    ));
    check(str_contains($badNumber[0]['_overview_error'], 'Ongeldig artikelnummer'), 'Ongeldig artikelnummer wordt expliciet gemeld per regel');
    $full = new SpotnetOverviewCommand(700, 900, ['Subject' => false, 'From' => false, 'Date' => true, 'Message-ID' => true]);
    $fullRows = $full->onOverviewInformationFollows(new MultiLineResponse(
        Response::createFromString("224 Overview follows\r\n"), ["812\tTitel\tPoster\tDate: " . gmdate('r') . "\tMessage-ID: <id@example.invalid>"]
    ));
    check($fullRows[0]['message-id'] === '<id@example.invalid>' && !isset($fullRows[0]['_overview_error']), 'Volledige verplichte overview-velden worden correct gelezen');
    $badFull = $full->onOverviewInformationFollows(new MultiLineResponse(
        Response::createFromString("224 Overview follows\r\n"), ["812\tTitel\tPoster\tBroken: " . gmdate('r') . "\tMessage-ID: <id@example.invalid>"]
    ));
    check(str_contains($badFull[0]['_overview_error'], 'Ongeldig volledig overview-veld date'), 'Foutief gelabeld verplicht overview-veld wordt gemeld');
    $spot['title'] = '<script>titel</script>';
    $comment['body'] = '<img src=x onerror=alert(1)>';
    $comment['source'] = 'Usenet';
    $html = SpotDetailPageRenderer::render('tester', false, $spot, [$comment], '<input name="_csrf" value="test">');
    check(!str_contains($html, '<script>') && !str_contains($html, '<img src=x') && str_contains($html, '&lt;script&gt;'), 'Onbetrouwbare inhoud wordt geescaped');
    check(str_contains($html, 'Omschrijving') && str_contains($html, 'Reacties (1)') && !str_contains($html, 'Onderdelen (headers)'), 'Spotdetail toont inhoud en reacties, geen headerdelen');
    $overview = SpotsPageRenderer::render('tester', false, [], [$spot, $other], null, 1, 1, 2, '');
    check(substr_count($overview, '>Details</a>') === 2 && !str_contains($overview, 'Onderdelen'), 'Overzicht behoudt individuele spots');

    if (in_array('--integration', $argv, true)) {
        require __DIR__ . '/integration.php';
    }
    echo $passed . ' controles geslaagd.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception . PHP_EOL);
    exit(1);
}
