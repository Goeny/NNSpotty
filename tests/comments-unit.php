<?php

declare(strict_types=1);

namespace NnSpotty\Comments {
    final class SigningTestResult
    {
        public static bool $fail = false;
    }

    function openssl_sign(string $data, mixed &$signature, \OpenSSLAsymmetricKey $key, int $algorithm): bool
    {
        return SigningTestResult::$fail ? false : \openssl_sign($data, $signature, $key, $algorithm);
    }
}

namespace {
    use NnSpotty\Comments\Article;
    use NnSpotty\Comments\PublicationException;
    use NnSpotty\UI\SpotDetailPageRenderer;
    use NnSpotty\UI\SpotEmoticons;
    use NnSpotty\Usenet\SpotnetParser;

    foreach (['', "bad\0text", str_repeat('a', 10001), "\xFF", 'Emoji ' . "\u{1F600}", str_repeat('x', 901)] as $invalidComment) {
        rejects(static fn () => Article::body($invalidComment), 'Reactiepublicatie weigert lege/ongeldige/niet-compatibele/te lange tekst');
    }
    check(Article::body("  caf\u{00E9}\r\n[img=smile]  ") === "caf\u{00E9}\n[img=smile]", 'Reacties normaliseren regels zonder inhoud te verliezen');
    $publicationSpot = SpotnetParser::spot('publication@example.invalid', Fixtures::headers('publication@example.invalid'));
    $publicationId = Article::messageId($publicationSpot['message_id']);
    check(str_starts_with(sha1('<' . $publicationId . '>'), '0000')
        && str_starts_with($publicationId, 'publication.0.')
        && SpotnetParser::commentReference($publicationId) === $publicationSpot['message_id'],
        'Gegenereerd reactie-ID bevat rating, spotverwijzing en geldige 16-bit proof-of-work');
    $publicationKey = Fixtures::signingKey();
    check(openssl_pkey_export($publicationKey, $publicationPem, null, ['config' => __DIR__ . '/openssl-test.cnf']),
        'Synthetische publicatiesleutel exporteerbaar');
    $publicationArticle = Article::build($publicationSpot, 'Lezer', "Dank je!\n.punt\n[img=buigen]", $publicationId, $publicationPem, $publicationPem);
    $publicationWire = "From: " . $publicationArticle['from'] . "\r\nSubject: " . $publicationArticle['subject'] . "\r\n"
        . $publicationArticle['headers'] . "\r\n\r\n" . $publicationArticle['body'];
    $parsedPublication = SpotnetParser::comment($publicationId, $publicationWire);
    check($parsedPublication['spot_id'] === $publicationSpot['id'] && $parsedPublication['poster'] === 'Lezer'
        && $parsedPublication['spotter_id'] === $publicationArticle['spotter_id']
        && $parsedPublication['signature_state'] === 'verified'
        && str_contains($parsedPublication['body'], '[img=buigen]') && str_contains($parsedPublication['body'], '.punt'),
        'Eigen publicatie roundtript met geverifieerde eigen Spotnet-ID via de reactieparser');
    $changedPublication = str_replace('[img=buigen]', '[img=buigen!]', $publicationWire);
    check(SpotnetParser::comment($publicationId, $changedPublication)['signature_state'] === 'verified',
        'Spotweb-compatibele message-ID-handtekening bewijst geen reactie-inhoud');
    $dottedSpot = SpotnetParser::spot('original.with.dots@example.invalid', Fixtures::headers('original.with.dots@example.invalid'));
    $dottedId = Article::messageId($dottedSpot['message_id']);
    $dottedArticle = Article::build($dottedSpot, 'Lezer', 'Reactie', $dottedId, $publicationPem, $publicationPem);
    check(SpotnetParser::comment($dottedId, $dottedArticle['headers'] . "\r\n\r\n" . $dottedArticle['body'])['spot_id'] === $dottedSpot['id'],
        'Consistente References bewaart volledige spot-ID met punten bij publicatie en retrieve');
    preg_match('/X-User-Signature: ([^\r]+)/', $publicationArticle['headers'], $publicationSignature);
    $publicationSignatureBytes = base64_decode(strtr($publicationSignature[1], ['-s' => '/', '-p' => '+']), true);
    $publicationDetails = openssl_pkey_get_details($publicationKey);
    $signedPublication = '<' . $publicationId . '>';
    check(openssl_verify($signedPublication, $publicationSignatureBytes, $publicationDetails['key'], OPENSSL_ALGO_SHA1) === 1
        && openssl_verify($signedPublication . 'changed', $publicationSignatureBytes, $publicationDetails['key'], OPENSSL_ALGO_SHA1) === 0,
        'Uitgaande gebruikershandtekening omvat uitsluitend het message-ID, net als Spotweb');
    foreach (['"Lezer"', $publicationArticle['from']] as $legacyFrom) {
        $legacySignature = Article::sign($signedPublication . $publicationArticle['body'] . "\r\n" . $legacyFrom, $publicationKey);
        $legacyWire = str_replace($publicationSignature[1], $legacySignature, $publicationWire);
        check(SpotnetParser::comment($publicationId, $legacyWire)['signature_state'] === 'verified'
            && SpotnetParser::comment($publicationId, str_replace('[img=buigen]', '[img=buigen!]', $legacyWire))['signature_state'] === 'invalid',
            'Bestaande bodygebonden NNSpotty-reacties blijven leesbaar met hun oorspronkelijke verificatie');
        check(!Fixtures::spotwebComment("Newsgroups: free.usenet\r\n" . $legacyWire, $publicationSpot['message_id'],
            $publicationDetails['key'], $publicationDetails['key']),
            'Onafhankelijke uitgaande protocolcontrole weigert oude bodygebonden handtekeningen');
    }
    rejects(static fn () => Article::build($publicationSpot, "bad\r\nX: injected", 'Tekst', $publicationId, $publicationPem, $publicationPem),
        'Publicatie weigert headerinjectie via gebruikersnaam');
    try {
        \NnSpotty\Comments\SigningTestResult::$fail = true;
        try {
            Article::build($publicationSpot, 'Lezer', 'Tekst', $publicationId, $publicationPem, $publicationPem);
            check(false, 'Geblokkeerde SHA1 moet publicatie expliciet blokkeren');
        } catch (PublicationException $exception) {
            check(str_contains($exception->getMessage(), 'serverconfiguratie')
                && str_contains($exception->getMessage(), 'niets naar Usenet verstuurd'), 'Geblokkeerde SHA1 geeft begrijpelijke configuratiefout zonder lokale fallback');
        }
    } finally {
        \NnSpotty\Comments\SigningTestResult::$fail = false;
    }
    $publicationPage = SpotDetailPageRenderer::render('Lezer', false, $publicationSpot, [], '', commentDraft: '</textarea><script>x</script>');
    check(str_contains($publicationPage, 'name="publish_consent"') && str_contains($publicationPage, 'name="publication_id"')
        && !str_contains($publicationPage, 'Lokale reactie plaatsen') && !str_contains($publicationPage, '</textarea><script>')
        && str_contains($publicationPage, '&lt;/textarea&gt;&lt;script&gt;') && str_contains($publicationPage, '/assets/comments.js'),
        'Formulier publiceert uitsluitend naar Usenet, vraagt toestemming en bewaart geescapete concepttekst');
    check(str_contains(SpotDetailPageRenderer::commentList([[
        'poster' => 'Lezer', 'spotter_id' => $publicationArticle['spotter_id'], 'posted_at' => '2026-01-01 00:00:00',
        'source' => 'Usenet', 'body' => 'Reactie',
    ]], false, true), 'Lezer (' . $publicationArticle['spotter_id'] . ')'),
        'Reactielijst toont de geverifieerde Spotnet-ID naast de publieke naam');
    check(substr_count(SpotEmoticons::toolbar('comment'), 'data-emoticon-code=') === 28
        && str_contains(SpotEmoticons::toolbar('comment'), 'data-emoticon-code="[img=respekt]"')
        && !str_contains(SpotEmoticons::toolbar('comment'), '<img'),
        'Klikbare emoticonbalk gebruikt eigen symbolen en compatibele codes zonder dubbele aliassen');
    $postCommand = new \Rvdv\Nntp\Command\PostArticleCommand('free.usenet', 'Re: Fixture', ".punt\r\n\r\nslot", 'Lezer <reader@spot.net>', 'Message-ID: <test@spot.net>');
    $postSocket = new \PipelineSocketFixture("240 Accepted\r\n");
    $postSocket->writeLimit = 7;
    (new \NnSpotty\Usenet\PipelineConnection('fixture.invalid', 119, false, $postSocket))->publishComment($postCommand);
    check($postSocket->written === $postCommand() . "\r\n.\r\n"
        && str_contains($postSocket->written, "\r\n..punt\r\n\r\nslot\r\n.\r\n"),
        'POST bewaart lege regels, dot-stuffing en terminator bij partiele writes');
    $postingProcess = proc_open([PHP_BINARY, __DIR__ . '/comment-server.php', 'accept'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $postingPipes);
    if (!is_resource($postingProcess)) throw new \RuntimeException('Publicatiefixture kon niet starten.');
    $postingClient = null;
    try {
        $postingPort = (int) trim(fgets($postingPipes[1]));
        check($postingPort > 0, 'Zelfstandige TCP-publicatiefixture luistert zonder database');
        $postingClient = \NnSpotty\Usenet\NntpClientFactory::connectFromSettings([
            'server' => '127.0.0.1', 'port' => $postingPort, 'encryption' => 'none', 'username' => '', 'password' => '',
        ]);
        check($postingClient->sendCommand(new \Rvdv\Nntp\Command\PostCommand())->getStatusCode() === 340,
            'TCP-provider is gereed voor POST');
        $postingClient->getConnection()->publishComment(new \Rvdv\Nntp\Command\PostArticleCommand(
            'free.usenet', $publicationArticle['subject'], $publicationArticle['body'], $publicationArticle['from'], $publicationArticle['headers']
        ));
        $capturedArticle = base64_decode(trim(fgets($postingPipes[1])), true);
        check(is_string($capturedArticle)
            && Fixtures::spotwebComment($capturedArticle, $publicationSpot['message_id'], $publicationDetails['key'], $publicationDetails['key']),
            'Werkelijk verstuurde TCP-reactie voldoet aan Spotweb-groep, spotkoppeling, hashcash en beide sleutel/message-ID-handtekeningen');
        check(str_ends_with($capturedArticle, $publicationArticle['body'] . "\r\n"),
            'TCP POST bewaart de exacte Latin-1-tekst, regeleinden en regels met een punt');
    } finally {
        if ($postingClient !== null) $postingClient->getConnection()->disconnect();
        $postingErrors = stream_get_contents($postingPipes[2]);
        foreach ($postingPipes as $postingPipe) fclose($postingPipe);
        $postingExit = proc_close($postingProcess);
    }
    check($postingExit === 0 && $postingErrors === '', 'Zelfstandige TCP-publicatiefixture correct afgesloten');
    foreach (["441 Rejected\r\n", "400 unavailable\r\n", '240 truncated', str_repeat('x', 513) . "\r\n"] as $postFailure) {
        try {
            (new \NnSpotty\Usenet\PipelineConnection('fixture.invalid', 119, false, new \PipelineSocketFixture($postFailure)))->publishComment($postCommand);
            check(false, 'POST vereist expliciete volledige 240-bevestiging');
        } catch (\Rvdv\Nntp\Exception\RuntimeException) {
            check(true, 'POST weigering/ongeldige/onvolledige/te grote bevestiging faalt expliciet');
        }
    }
}
