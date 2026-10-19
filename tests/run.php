<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/Fixtures.php';

use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\UI\SpotsPageRenderer;
use NnSpotty\UI\SpotDescription;
use NnSpotty\UI\SpotEmoticons;
use NnSpotty\UI\SpotLayout;
use NnSpotty\UI\SpotPresentation;
use NnSpotty\UI\SpotColumns;
use NnSpotty\Usenet\SpotFilters;
use NnSpotty\Http\Request;
use NnSpotty\Usenet\SpotnetOverviewCommand;
use NnSpotty\Usenet\SpotnetParser;
use NnSpotty\Usenet\SpotCategories;
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
    check($spot['newsreader'] === 'Spotnet 1.9.0.8', 'Newsreader uit NNTP-header wordt bewaard');
    $xmlNewsreader = str_replace('</Spotnet>', '<Extra><Newsreader>XML-client</Newsreader></Extra></Spotnet>', Fixtures::xml());
    check(SpotnetParser::spot('xml-reader@example.invalid', str_replace("X-Newsreader: Spotnet 1.9.0.8\r\n", '', Fixtures::headers('xml-reader@example.invalid', $xmlNewsreader)))['newsreader'] === 'XML-client', 'Newsreader uit Extra/Newsreader als header ontbreekt');
    rejects(static fn () => SpotnetParser::spot('long-reader@example.invalid', str_replace('Spotnet 1.9.0.8', str_repeat('x', 513), Fixtures::headers('long-reader@example.invalid'))), 'Te lang newsreader-veld wordt expliciet geweigerd');
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
    $wrongReference = SpotnetParser::comment('spot-one.1.2.3@example.invalid', Fixtures::comment('<wrong@example.invalid>'));
    check($wrongReference['spot_id'] === $spot['id'], 'Spotnet reactie-ID heeft voorrang op onjuiste provider-References');
    check(SpotnetParser::comment('spot-one.1.2.3@example.invalid', Fixtures::comment('provider garbage'))['spot_id'] === $spot['id'], 'Defect References-veld blokkeert geldige Spotnet-reactie niet');
    check(SpotnetParser::commentReference('SpotCase.123.456@spot.net') === 'SpotCase@spot.net'
        && SpotnetParser::commentReference('plain@domain.with.dots') === null, 'Reactiekoppeling behoudt hoofdletters en splitst uitsluitend lokaal deel');
    rejects(static fn () => SpotnetParser::comment('comment@example.invalid', "Message-ID: <different@example.invalid>\r\n" . Fixtures::comment()), 'Verkeerd reactie message-ID wordt afgewezen');
    rejects(static fn () => SpotnetParser::comment('comment@example.invalid', Fixtures::comment('')), 'Reactie zonder code of References wordt afgewezen');
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
    $classified = SpotCategories::classify('Beeld', ['1A009', '1d000', '1z01', '1a9']);
    check($classified === ['type' => 'Series', 'adult' => false, 'codes' => ['a9', 'd0', 'z1']], 'Categoriecodes genormaliseerd zonder dubbele of verkeerde 1-based omzetting');
    foreach ([0 => 'Films', 1 => 'Series', 2 => 'Boeken', 3 => 'Erotica', 4 => 'Afbeeldingen'] as $code => $type) {
        check(SpotCategories::classify('Beeld', ['1z0' . $code])['type'] === $type, 'Beeld type ' . $type);
    }
    foreach ([0 => 'Muziek', 1 => 'Livesets', 2 => 'Podcasts', 3 => 'Luisterboeken'] as $code => $type) {
        $classified = SpotCategories::classify('Geluid', ['2z0' . $code]);
        check($classified['type'] === $type && !$classified['adult'], 'Geluid type ' . $type . ' is geen erotica');
    }
    foreach (array_merge(range(23, 26), range(72, 89)) as $code) {
        $classified = SpotCategories::classify('Beeld', ['1d' . $code, '1z00']);
        check($classified['adult'] && $classified['type'] === 'Erotica', 'Adult genre d' . $code . ' overrulet een onjuist expliciet filmtype');
    }
    check(SpotCategories::classify('Beeld', ['1a05', '1c02']) === ['type' => 'Boeken', 'adult' => false, 'codes' => ['a5', 'c11', 'z2']], 'Legacy boeken en Nederlandse boektaal');
    check(SpotCategories::classify('Beeld', ['1b04'])['type'] === 'Series'
        && SpotCategories::classify('Beeld', ['1a12'])['type'] === 'Afbeeldingen', 'Legacy series en afbeeldingen zonder typecode');
    check(SpotCategories::classify('Spellen', ['3c23'])['adult'] === false
        && SpotCategories::classify('Applicaties', ['4b23'])['adult'] === false, 'Eroticacodes zijn hoofdgroepgebonden');
    check(SpotCategories::classify('Beeld', ['1a999', '1d000000023', '1a99999999999999999999'])['codes'] === ['a999', 'd75', 'z3'],
        'Onbekende codes blijven leesbaar, padding blijft geldig en numerieke overflow vervuilt de index niet');
    foreach (SpotCategories::definitions() as $head => $dimensions) {
        foreach ($dimensions as $dimension => $values) {
            foreach ($values as $number => $label) {
                check(SpotCategories::label($head . ':' . $dimension . $number) === $label, 'Label ' . $head . ':' . $dimension . $number);
            }
        }
    }
    $spot['title'] = '<script>titel</script>';
    $comment['body'] = '<img src=x onerror=alert(1)>';
    $comment['source'] = 'Usenet';
    $html = SpotDetailPageRenderer::render('tester', false, $spot, [$comment], '<input name="_csrf" value="test">');
    check(str_contains($html, '<dt>Gepost met</dt><dd>Spotnet 1.9.0.8</dd>')
        && str_contains($html, '<dt>Hoofdcategorie</dt><dd>Beeld</dd>'), 'Detail toont hoofdgroep en newsreader naast bestaande technische details');
    $unsafeReader = $spot;
    $unsafeReader['newsreader'] = '<script>reader()</script>';
    check(!str_contains(SpotDetailPageRenderer::summary($unsafeReader, true), '<script>reader()'), 'Newsreader is ook in preview geescapete tekst');
    check(!str_contains($html, '<script>') && !str_contains($html, '<img src=x') && str_contains($html, '&lt;script&gt;'), 'Onbetrouwbare inhoud wordt geescaped');
    check(str_contains($html, 'Omschrijving') && str_contains($html, 'Reacties (1)') && !str_contains($html, 'Onderdelen (headers)'), 'Spotdetail toont inhoud en reacties, geen headerdelen');
    $overview = SpotsPageRenderer::render('tester', false, [$spot, $other], null, '');
    check(substr_count($overview, 'data-preview=') === 2 && !str_contains($overview, 'Onderdelen'), 'Overzicht behoudt individuele spots');
    check(str_contains($overview, 'data-bs-theme="dark"') && str_contains($overview, 'class="sidebar"')
        && str_contains($overview, 'class="browse-grid"'), 'Donker overzicht met sidebar en detailpaneel');
    check(str_contains($overview, 'src="/assets/retrieve.js" defer')
        && str_contains($overview, 'Laatste retrieve: laden...'), 'Layout laadt de retrieve-indicator en tijdweergave');
    $light = SpotsPageRenderer::render('tester', false, [$spot], 'Films', '', [['code' => '0:a9', 'total' => 1]], ['0:a9'], 'film & serie', 'plex-light', nextCursor: 'fixture');
    check(str_contains($light, 'data-bs-theme="light"') && str_contains($light, 'x264') && !str_contains($light, 'Volledige spot en reacties'), 'Licht thema met leesbare subcategorie zonder vooraf geladen preview');
    check(str_contains($light, 'subcategory=0%3Aa9') && str_contains($light, 'q=film+%26+serie')
        && str_contains($light, 'cursor=fixture') && !str_contains($light, 'page=3'), 'Vervolgbatch behoudt categorie, filters en zoektekst zonder paginanummers');
    check(str_contains($light, '<noscript><a class="btn btn-outline-primary" href="/spots?')
        && str_contains($light, 'href="/spots/view?key='), 'Zonder JavaScript blijven vervolgbatches en volledige spotdetails bereikbaar');
    preg_match('~<nav aria-label="Hoofdcategorieen">(.*?)</nav>~s', $light, $mainMenu);
    check(isset($mainMenu[1]) && substr_count($mainMenu[1], '<a ') === 5
        && str_contains($mainMenu[1], '>Beeld</a>') && str_contains($mainMenu[1], '>Geluid</a>')
        && !str_contains($mainMenu[1], 'Series') && !str_contains($mainMenu[1], 'x264'), 'Sidebar toont uitsluitend Alle en de vier hoofdcategorieen boven de subcategorieen');
    check(str_contains($light, 'aria-label="Subcategorieen"') && str_contains($light, '>WEBRip<span')
        && str_contains($light, '>Sciencefiction<span') && str_contains($light, 'aria-current="page"'), 'Sidebar bevat alle subcategorieen, ook zonder spots, en markeert de actieve keuze');
    check(!str_contains($light, '>Erotica<span') && !str_contains($light, 'subcategory=0%3Ad75'), 'Verborgen adult-subcategorieen verschijnen niet in de sidebar');
    check(!str_contains($light, 'individuele spots') && str_contains($light, '1 nieuw sinds je vorige bezoek')
        && str_contains($light, 'Tellers blijven tijdens deze browsersessie staan.')
        && str_contains($light, '<span id="loaded-count">1</span> resultaten geladen'),
        'Overzicht toont alleen geladen batches en behoudt persoonlijke nieuwe-spottellers');
    check(substr_count($light, 'type="search"') === 1 && !str_contains($light, 'Zoek in titels')
        && str_contains($light, 'Wis filters') && str_contains($light, 'Kolommen instellen'),
        'Er is een zoekveld en filterchips/reset staan bij de kolominstellingen');
    check(str_contains($light, 'Voorkeuren</a>') && !str_contains($light, 'Beheerpanel</a>')
        && !str_contains($light, 'Spotsoverzicht'), 'Gebruikersmenu bevat voorkeuren, geen overbodige overzicht- of beheerkoppeling');
    $adminLayout = SpotLayout::render('Test', 'admin', true, '<input name="_csrf">', '');
    check(str_contains($adminLayout, 'Beheerpanel</a>') && str_contains($adminLayout, 'data-retrieve-form')
        && str_contains($adminLayout, 'Laatste retrieve: laden...'), 'Admin ziet beheermenu en retrievebediening met status');
    check(str_contains($light, 'data-spot-browser data-browse-user="tester"'),
        'Browsergeschiedenis van de spotlijst is gekoppeld aan de huidige gebruiker');
    $counterDetail = SpotDetailPageRenderer::render('tester', false, $spot, [], '', facets: [['code' => '0:a9', 'total' => 100]]);
    check(str_contains($counterDetail, '100 nieuw sinds je vorige bezoek'),
        'Volledige detailpagina kan dezelfde persoonlijke facetbadge tonen als het overzicht');
    $pending = SpotsPageRenderer::render('tester', false, [$spot], 'Films', '', facets: null,
        filters: ['0:a9', '0:d0'], search: 'film & serie');
    check(str_contains($pending, '/assets/facets.js') && str_contains($pending, 'data-facets-url="/spots/facets?category=Films')
        && str_contains($pending, 'subcategory=0%3Aa9%2C0%3Ad0&amp;q=film+%26+serie')
        && str_contains($pending, 'data-facet-count="0:a9"')
        && str_contains($pending, 'Nieuwtellers vereisen JavaScript; de filters werken zonder.')
        && !str_contains($pending, '0 nieuw sinds je vorige bezoek'),
        'Uitgestelde nieuwtellers bewaren exacte categorie en filters zonder fictieve nullen');
    check(!str_contains(SpotsPageRenderer::render('tester', false, [$spot], null, '', facets: null), '/assets/facets.js')
        && str_contains(SpotDetailPageRenderer::render('tester', false, $spot, [], '', facets: null), 'data-facets-url='),
        'Alle-categorie heeft geen telrequest; detail ondersteunt dezelfde uitgestelde tellers');
    $sound = SpotLayout::render('Test', 'tester', false, '', '', selected: 'Geluid');
    check(str_contains($sound, '>FLAC<span') && str_contains($sound, '>Lossless<span')
        && !str_contains($sound, '>WEBRip<span'), 'Hoofdcategorie wisselen vervangt de subcategorieen');
    $adultMenu = SpotLayout::render('Test', 'tester', false, '', '', selected: 'Beeld', showErotica: true);
    check(str_contains($adultMenu, '>Erotica<span') && str_contains($adultMenu, 'subcategory=0%3Ad75'), 'Adult-subcategorieen alleen bij effectieve opt-in');
    check(str_contains($overview, 'class="card spot-list"') && str_contains($overview, 'aria-hidden="true" inert')
        && str_contains($overview, 'id="preview-content" tabindex="0"') && str_contains($overview, 'id="close-preview"'), 'Preview is initieel gesloten, sluitbaar en heeft een eigen toetsenbordbereikbaar scrollgebied');
    check(str_contains($overview, 'src="/assets/spots.js" defer') && !str_contains($overview, 'class="pagination"'), 'Overzicht laadt interacties en gebruikt geen paginering');
    check(SpotFilters::parse('0:d0,0:a9,0:d0') === ['0:a9', '0:d0'], 'Meerdere filters worden genormaliseerd en ontdubbeld');
    check(SpotFilters::toggle(['0:a9'], '0:d0') === ['0:a9', '0:d0']
        && SpotFilters::toggle(['0:a9', '0:d0'], '0:a9') === ['0:d0'], 'Klik op subcategorie voegt filter toe of verwijdert uitsluitend dat filter');
    foreach (['invalid', '0:a999', '0:a9,,0:d0', '0:a09', '4:a0'] as $badFilter) {
        rejects(static fn () => SpotFilters::parse($badFilter), 'Onbekende of ongeldige filter geweigerd: ' . $badFilter);
    }
    $stacked = SpotsPageRenderer::render('tester', false, [$spot], 'Beeld', '', filters: ['0:a9', '0:d0'], search: 'test');
    check(str_contains($stacked, 'subcategory=0%3Aa9%2C0%3Ad0%2C0%3Ad1')
        && str_contains($stacked, 'aria-label="Verwijder filter x264"')
        && str_contains($stacked, 'name="subcategory" value="0:a9,0:d0"')
        && str_contains($stacked, 'class="page-heading-actions"'), 'Sidebar stapelt filters, chips verwijderen apart en zoeken behoudt selectie');
    foreach (SpotColumns::defaults() as $selection) {
        check(SpotColumns::validate($selection) === $selection, 'Standaard kolommen zijn geldig en behouden vaste volgorde');
    }
    rejects(static fn () => SpotColumns::validate([]), 'Titel is een verplichte kolom');
    rejects(static fn () => SpotColumns::validate(['title', 'bogus']), 'Onbekende kolom geweigerd');
    rejects(static fn () => SpotColumns::validate(['title', 'title']), 'Dubbele kolom geweigerd');
    rejects(static fn () => SpotColumns::validate(['title', ['size']]), 'Geneste kolomwaarden worden expliciet geweigerd zonder conversiewaarschuwing');
    $minimal = SpotsPageRenderer::rows([$spot], ['title']);
    check(!str_contains($minimal, 'column-cover') && !str_contains($minimal, 'column-size') && str_contains($minimal, 'data-preview='), 'Persoonlijke kolommen bepalen daadwerkelijk welke cellen worden gerenderd');
    $request = new Request('POST', '/', [], [
        'column_mode_0' => 'custom', 'column_0_size' => '1',
        'column_mode_1' => 'inherit', 'column_mode_2' => 'inherit', 'column_mode_3' => 'inherit',
    ], '127.0.0.1');
    check(SpotColumns::fromRequest($request, true) === [0 => ['title', 'size']], 'Persoonlijk formulier slaat alleen overrides op, met verplichte titel');
    rejects(static fn () => SpotColumns::fromRequest(new Request('POST', '/', [], ['column_0_size' => ['1']], 'local'), false), 'Array-invoer wordt niet stil als ontbrekende kolom behandeld');
    rejects(static fn () => (new Request('GET', '/', ['subcategory' => ['0:a9']], [], 'local'))->queryScalar('subcategory'), 'Array in scalaire filterinvoer wordt expliciet geweigerd');
    check((new Request('POST', '/', [], [], 'local', 'application/json'))->expectsJson()
        && !(new Request('POST', '/', [], [], 'local'))->expectsJson(), 'Request herkent JSON-verzoeken voor interactieve retrieve');
    $poster = SpotPresentation::image($spot, false);
    check(str_contains($poster, 'class="spot-poster mb-0"') && str_contains($poster, 'class="poster-backdrop"')
        && str_contains($poster, 'class="spot-poster-image"') && str_contains($html, 'class="hero-backdrop"'), 'Vast posterframe en hero gebruiken eigen decoratieve blurred image-laag');
    $noImage = $spot;
    $noImage['image_segments'] = [];
    $noImage['image_url'] = '';
    check(str_contains(SpotPresentation::backdrop($noImage, 'hero-backdrop'), 'src="/assets/images/placeholder.png"')
        && str_contains(SpotPresentation::image($noImage, false), 'Geen afbeelding'), 'Zonder cover wordt de placeholder ook de hero-achtergrond');
    check(str_contains(SpotPresentation::image($noImage, false), '/assets/images/placeholder.png')
        && str_contains(SpotPresentation::image($noImage, true), '/assets/images/placeholder-small.png')
        && !str_contains(SpotPresentation::image($noImage, false), 'data-image-lightbox'),
        'Ontbrekende covers gebruiken huisstijl in poster en thumbnail zonder fictieve vergroting');
    check(!str_contains(SpotPresentation::image($noImage, false, false), '<img')
        && str_contains(SpotPresentation::image($noImage, false, false), 'Afbeeldingen uitgeschakeld'),
        'Afbeeldingen uitschakelen blijft ook de nieuwe placeholder blokkeren');
    check(str_contains($poster, 'data-image-fallback hidden')
        && str_contains($poster, '/assets/images/placeholder.png')
        && !str_contains($poster, 'Afbeelding niet beschikbaar. Controleer de serverlog.')
        && !str_contains($poster, '[role=status]'),
        'Mislukte cover krijgt uitsluitend de lokale placeholder zonder waarschuwingstekst');
    check(SpotPresentation::backdrop($noImage, 'hero-backdrop', false) === ''
        && str_contains(SpotPresentation::backdrop($spot, 'hero-backdrop'), "this.src='/assets/images/placeholder.png'"),
        'Hero-achtergrond valt bij laadfout terug op placeholder maar blijft uit bij uitgeschakelde afbeeldingen');
    foreach ([false, true] as $previewMode) {
        $summary = SpotDetailPageRenderer::summary($noImage, $previewMode);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $summary, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        check($xpath->query('//section[contains(@class,"spot-hero")]/div[@class="hero-actions"]')->length === 1
            && $xpath->query('//div[@class="hero-actions"]/*[not(contains(@class,"btn-sm"))]')->length === 0
            && $xpath->query('//div[@class="hero-copy"]//a | //div[@class="hero-copy"]//button')->length === 0,
            'Preview en detail groeperen alle compacte acties apart boven de hero-inhoud');
    }
    $brandingPages = [
        \NnSpotty\UI\LoginPageRenderer::render(null, ''),
        SpotLayout::render('Branding', 'reader', false, '', ''),
    ];
    check(str_contains($brandingPages[0], '<html lang="nl" data-bs-theme="dark">'),
        'Loginpagina gebruikt altijd het donkere Bootstrap-thema');
    foreach ($brandingPages as $brandingPage) {
        check(str_contains($brandingPage, 'rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png"')
            && str_contains($brandingPage, 'rel="apple-touch-icon" sizes="180x180"'),
            'Login- en gedeelde layout bevatten lokale favicon en touch-icon');
    }
    check(str_contains($brandingPages[0], '/assets/images/logo.png') && str_contains($brandingPages[1], '/assets/images/brand-icon.png'),
        'Login en gedeelde layout behouden eigen logo en appicoon zonder homepage');
    foreach (['favicon-32.png' => [32, 32], 'brand-icon.png' => [64, 64], 'apple-touch-icon.png' => [180, 180],
        'logo.png' => [768, 512], 'placeholder.png' => [320, 480], 'placeholder-small.png' => [84, 126]] as $asset => $dimensions) {
        $assetPath = __DIR__ . '/../public/assets/images/' . $asset;
        $imageSize = getimagesize($assetPath);
        check([$imageSize[0], $imageSize[1]] === $dimensions && $imageSize['mime'] === 'image/png'
            && filesize($assetPath) < 220000, 'Webafbeelding heeft correct formaat en begrensde bestandsgrootte: ' . $asset);
    }
    foreach (['logo.png', 'favicon.png', 'placeholder.png', 'presentation.png'] as $original) {
        check(is_file(__DIR__ . '/../docs/branding/' . $original), 'Origineel huisstijlbestand bewaard: ' . $original);
    }
    check(SpotDescription::render('Eerste regel' . "\n" . 'Tweede & laatste') === "Eerste regel<br />\nTweede &amp; laatste", 'Platte omschrijving behoudt regels en tekst');
    check(SpotDescription::render('<p>Een <strong>opgemaakte</strong> tekst.</p><ul><li>Item</li></ul>') === '<p>Een <strong>opgemaakte</strong> tekst.</p><ul><li>Item</li></ul>', 'HTML-alineas, nadruk en lijsten worden als opmaak gerenderd');
    check(SpotDescription::render('<div style="position:fixed" class="topbar" id="content" onclick="evil()">Veilig <b onmouseover="evil()">vet</b></div>') === '<div>Veilig <b>vet</b></div>', 'Remote attributen, CSS en eventhandlers worden niet overgenomen');
    foreach ([
        '<script>alert(1)</script><p>Veilig</p>',
        '<style>body{display:none}</style><p>Veilig</p>',
        '<iframe src="https://example.invalid"></iframe><p>Veilig</p>',
        '<svg><a href="javascript:evil()">verborgen</a></svg><p>Veilig</p>',
        '<math><mtext>verborgen</mtext></math><p>Veilig</p>',
        '<form><button>verborgen</button></form><p>Veilig</p>',
        '<img src="https://example.invalid/tracker" onerror="evil()"><p>Veilig</p>',
        '<!-- onzichtbaar --><p>Veilig</p>',
    ] as $unsafeDescription) {
        check(SpotDescription::render($unsafeDescription) === '<p>Veilig</p>', 'Onveilige of actieve inhoud uit omschrijving verwijderd: ' . substr($unsafeDescription, 0, 30));
    }
    foreach (['javascript:alert(1)', 'jav&#x61;script:alert(1)', 'data:text/html,test', '//example.invalid', "https://example.invalid/&#10;test"] as $url) {
        check(SpotDescription::render('<a href="' . $url . '">Leesbaar</a>') === 'Leesbaar', 'Onveilige omschrijvingslink niet klikbaar: ' . $url);
    }
    $safeLink = SpotDescription::render('<a href="https://example.invalid/?a=1&amp;b=2" target="_self" onclick="evil()">Website</a>');
    check(str_contains($safeLink, 'href="https://example.invalid/?a=1&amp;b=2"') && str_contains($safeLink, 'rel="noopener noreferrer nofollow"')
        && !str_contains($safeLink, 'onclick'), 'Veilige weblink behoudt query met vaste veilige linkattributen');
    check(SpotDescription::render('<p>Caf&eacute; &#x1F3AC; &lt;script&gt;</p>') === '<p>Caf' . "\xc3\xa9" . ' ' . "\xf0\x9f\x8e\xac" . ' &lt;script&gt;</p>', 'Unicode en reeds geescapete tags blijven tekst');
    $formatted = $spot;
    $formatted['description'] = '<p>Een <b>veilige omschrijving</b>.</p><script>evil()</script>';
    check(str_contains(SpotDetailPageRenderer::summary($formatted, true), '<p>Een <b>veilige omschrijving</b>.</p>')
        && str_contains(SpotDetailPageRenderer::summary($formatted, false), '<p>Een <b>veilige omschrijving</b>.</p>')
        && !str_contains(SpotDetailPageRenderer::summary($formatted, true), 'evil()'), 'Preview en detail gebruiken dezelfde veilige HTML-opmaak');
    $spotnetText = '10 afleveringen[br]Snowfall.2017.S03.1080p.DSNP.WEB-DL.DD5.1.H.264-playWEB.mkv[br][br]Het is 1983 in Los Angeles.[br][br]Multisubs';
    $spotnetHtml = SpotDescription::render($spotnetText);
    check(substr_count($spotnetHtml, '<br>') === 5 && !str_contains($spotnetHtml, '[br]')
        && str_contains($spotnetHtml, 'mkv<br><br>Het is 1983'), 'Werkelijke Spotnet-omschrijving behoudt alle enkele en dubbele [br]-regeleinden');
    foreach ([
        '[BR]A[br/]B[br /]C' => '<br>A<br>B<br>C',
        '[b]Vet [i]cursief[/i][/b]' => '<strong>Vet <em>cursief</em></strong>',
        '[u]Onder[/u][s]door[/s]' => '<u>Onder</u><s>door</s>',
        '[quote]Citaat[/quote]' => '<blockquote>Citaat</blockquote>',
        '[list][*]Een[*][b]Twee[/b][/list]' => '<ul><li>Een</li><li><strong>Twee</strong></li></ul>',
        '[list=1][li]Een[/li][li]Twee[/li][/list]' => '<ol><li>Een</li><li>Twee</li></ol>',
        '[list][*]Een[list][*]Twee[/list][*]Drie[/list]' => '<ul><li>Een<ul><li>Twee</li></ul></li><li>Drie</li></ul>',
        '[list][*][code][*]Letterlijk[/code][*]Twee[/list]' => '<ul><li><pre><code>[*]Letterlijk</code></pre></li><li>Twee</li></ul>',
        '[code][b]Niet opmaken[/b][br][/code]' => '<pre><code>[b]Niet opmaken[/b][br]</code></pre>',
        '[color=red][size=99][font=Arial]Tekst[/font][/size][/color]' => '<span><span><span>Tekst</span></span></span>',
        '[center]Tekst[/center]' => '<div>Tekst</div>',
        '[b]Niet gesloten' => '[b]Niet gesloten',
        '[/b]Los einde' => '[/b]Los einde',
        '<p>HTML [b]en BBCode[/b][br]samen</p>' => '<p>HTML <strong>en BBCode</strong><br>samen</p>',
        '<code>[br][b]letterlijk[/b]</code>' => '<code>[br][b]letterlijk[/b]</code>',
        '[img]https://example.invalid/tracker.jpg[/img]' => '[img]https://example.invalid/tracker.jpg[/img]',
    ] as $input => $expected) {
        check(SpotDescription::render($input) === $expected, 'Veilige Spotnet-opmaak: ' . $input);
    }
    foreach (['javascript:alert(1)', 'data:text/html,test', '//example.invalid', "https://example.invalid/&#10;test", 'https://example.invalid/&quot; onclick=&quot;evil()'] as $url) {
        $link = SpotDescription::render('[url=' . $url . '][b]Leesbaar[/b][/url]');
        check($link === '<strong>Leesbaar</strong>', 'Onveilige Spotnet-link blijft niet-klikbare tekst: ' . $url);
    }
    $emoticonNames = explode(' ', 'biggrin bloos buigen censored clown confused cool exactly frown grijns heh huh klappen knipoog kwijl lollig maf ogen oops pijl redface respekt respect schater shiny sleephappy smile uitroepteken vlag vraagteken wink');
    foreach ($emoticonNames as $name) {
        $code = '[img=' . $name . ']';
        $emoticon = SpotDescription::render($code);
        check(str_contains($emoticon, 'class="spot-emoticon') && str_contains($emoticon, 'role="img" aria-label="')
            && !str_contains($emoticon, $code) && !str_contains($emoticon, '<img') && !str_contains($emoticon, 'src='),
            'Spotweb-emoticon krijgt toegankelijke moderne weergave zonder afbeelding: ' . $name);
    }
    check(SpotEmoticons::render('[img=respekt]') === SpotEmoticons::render('[img=respect]')
        && SpotEmoticons::render('[IMG=RESPEKT]') === SpotEmoticons::render('[img=respect]'), 'Respect-aliassen en hoofdletters zijn compatibel');
    check(SpotEmoticons::render('[img=wink]') === SpotEmoticons::render('[img=knipoog]'), 'Engelse en Nederlandse knipoog delen dezelfde weergave');
    foreach (['[img=onbekend]', '[img=https://example.invalid/tracker.gif]', '[img=javascript:evil()]',
        '[img=//example.invalid/tracker]', '[img=smile onerror=evil()]', '[img=smile.gif]', '[img=../smile]'] as $unknown) {
        check(SpotDescription::render($unknown) === h($unknown), 'Onbekende code of afbeeldingsbron blijft tekst: ' . $unknown);
    }
    check(SpotDescription::render('[code][img=buigen][/code]') === '<pre><code>[img=buigen]</code></pre>'
        && SpotDescription::render('<code>[img=respekt]</code>') === '<code>[img=respekt]</code>', 'Emoticoncodes blijven letterlijk in codeblokken');
    check(SpotDescription::render('<script>[img=smile]</script><p title="[img=smile]">Veilig</p>') === '<p>Veilig</p>',
        'Emoticoncodes worden niet in attributen of geblokkeerde inhoud verwerkt');
    $repeatedEmoticons = SpotDescription::render(str_repeat('[img=respekt]', 50));
    check(substr_count($repeatedEmoticons, 'role="img"') === 50 && !str_contains($repeatedEmoticons, '[img='),
        'Aaneengesloten emoticons uit echte reacties worden allemaal verwerkt');
    $localEmoticons = SpotEmoticons::plainText("[b]Tekst[/b]<script>unsafe()</script>\n[img=buigen][img=respekt]");
    check(str_contains($localEmoticons, '[b]Tekst[/b]&lt;script&gt;unsafe()&lt;/script&gt;<br />')
        && substr_count($localEmoticons, 'role="img"') === 2 && !str_contains($localEmoticons, '<script>'),
        'Lokale reacties ondersteunen alleen emoticons, zonder HTML of overige BBCode te activeren');
    $emoticonComments = [
        ['poster' => 'Usenet-lezer', 'source' => 'Usenet', 'posted_at' => '2026-10-18 10:00:00', 'body' => 'Dank [img=buigen][img=respekt]'],
        ['poster' => 'Lokale lezer', 'source' => 'NNSpotty', 'posted_at' => '2026-10-18 11:00:00', 'body' => '[b]Letterlijk[/b] [img=respect]'],
    ];
    $emoticonPage = SpotDetailPageRenderer::render('tester', false, $spot, $emoticonComments, '');
    check(preg_match_all('/<span class="spot-emoticon(?: |")/', $emoticonPage) === 3
        && str_contains($emoticonPage, '[b]Letterlijk[/b]'), 'Detail rendert moderne emoticons in zowel lokale als Usenet-reacties');
    $emoticonDescription = $spot;
    $emoticonDescription['description'] = '[img=buigen][img=respect]';
    check(str_contains(SpotDetailPageRenderer::summary($emoticonDescription, true, false), SpotEmoticons::render('[img=respect]')),
        'Preview ondersteunt dezelfde tekstemoticons, ook wanneer externe spotafbeeldingen uitstaan');
    check(SpotDescription::render('[url=https://example.invalid/?a=1&amp;b=2]Link[/url]') ===
        '<a href="https://example.invalid/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer nofollow">Link</a>', 'BBCode-URL is veilig geescaped met vaste attributen');
    check(SpotDescription::render('[url]https://example.invalid/[/url]') ===
        '<a href="https://example.invalid/" target="_blank" rel="noopener noreferrer nofollow">https://example.invalid/</a>', 'Spotnet-URL zonder apart label werkt');
    $nestedLinks = SpotDescription::render('<a href="https://example.invalid">[url=https://example.invalid/other]Tekst[/url]</a>');
    check(substr_count($nestedLinks, '<a ') === 1, 'Gemengde HTML en BBCode maken geen geneste links');
    check(SpotDescription::render('<p title="[br]">Tekst</p><script>[b]verborgen[/b]</script>') === '<p>Tekst</p>', 'BBCode wordt niet in HTML-attributen of actieve elementen uitgevoerd');
    try {
        SpotDescription::render(str_repeat('[b]', 102) . 'Tekst' . str_repeat('[/b]', 102));
        check(false, 'Te diep geneste Spotnet-opmaak moet falen');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'te diep'), 'Te diep geneste Spotnet-opmaak faalt expliciet');
    }
    $formatted['description'] = $spotnetText;
    check(str_contains(SpotDetailPageRenderer::summary($formatted, false), $spotnetHtml)
        && str_contains(SpotDetailPageRenderer::summary($formatted, true), $spotnetHtml), 'BBCode-weergave is gelijk in preview en detail');
    $disabledSummary = SpotDetailPageRenderer::summary($formatted, false, false, false);
    check(!str_contains($disabledSummary, '/spots/image?') && !str_contains($disabledSummary, 'hero-backdrop')
        && !str_contains($disabledSummary, 'data-image-lightbox') && !str_contains($disabledSummary, 'href="#comments"')
        && str_contains($disabledSummary, 'Afbeeldingen uitgeschakeld'), 'Uitgeschakelde afbeeldingen en reacties leveren geen laad-URLs of acties in detail');
    check(str_contains(SpotPresentation::image($spot, false), 'data-image-lightbox')
        && !str_contains(SpotPresentation::image($spot, true), 'data-image-lightbox')
        && str_contains($html, '<dialog class="image-lightbox"')
        && str_contains($html, '/assets/presentation.js'), 'Cover heeft toegankelijke vergrotingslink en gedeeld lightboxvenster; thumbnails blijven lijstacties');

    require __DIR__ . '/newznab-unit.php';
    require __DIR__ . '/sab-unit.php';
    require __DIR__ . '/tvdb-unit.php';
    if (in_array('--integration', $argv, true)) {
        require __DIR__ . '/integration.php';
    }
    echo $passed . ' controles geslaagd.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception . PHP_EOL);
    exit(1);
}
