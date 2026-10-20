<?php

declare(strict_types=1);

use NnSpotty\UI\PreferencesPageRenderer;

foreach (['plex-dark', 'plex-light'] as $preferencesTheme) {
    $preferencesHtml = PreferencesPageRenderer::render(
        username: 'reader<test>',
        preferences: ['theme' => $preferencesTheme, 'items_per_page' => 50, 'show_erotica' => true],
        statusMessage: 'Instellingen opgeslagen.',
        isAdmin: false,
        csrfField: '<input type="hidden" name="_csrf" value="preferences-test">',
        apiEnabled: true,
        hasApiKey: true,
        newApiKey: 'new-key-visible-once',
        sabSettings: '<section id="downloads"><form action="/downloader" method="post"></form></section>',
        metadataSettings: '<section id="metadata"><form action="/metadata" method="post"></form></section>',
        musicSettings: '<section id="music-metadata"><form action="/music-metadata" method="post"></form></section>'
    );
    $preferencesDocument = new DOMDocument();
    $preferencesDocument->loadHTML($preferencesHtml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $preferencesXpath = new DOMXPath($preferencesDocument);
    check($preferencesXpath->query('//nav[@data-preferences-navigation]/a')->length === 7,
        'Voorkeuren heeft zeven navigatietabs inclusief profiel: ' . $preferencesTheme);
    foreach (['general' => '/preferences', 'columns' => '/preferences/columns', 'downloads' => '/downloader',
        'metadata' => '/metadata', 'music-metadata' => '/music-metadata', 'api-key' => '/preferences/api-key'] as $panel => $action) {
        check($preferencesXpath->query('//nav[@data-preferences-navigation]/a[@href="#' . $panel . '"]')->length === 1
            && $preferencesXpath->query('//*[@data-preferences-panel="' . $panel . '"]//*[@id="' . $panel . '"] | //*[@data-preferences-panel="' . $panel . '" and @id="' . $panel . '"]')->length === 1,
            'Elke voorkeurentab heeft een uniek bestaand anker: ' . $panel . ' / ' . $preferencesTheme);
        check($preferencesXpath->query('//*[@data-preferences-panel="' . $panel . '"]//form[@action="' . $action . '"]')->length >= 1,
            'Onafhankelijk opslagformulier binnen juiste voorkeurentab: ' . $panel . ' / ' . $preferencesTheme);
    }
    check($preferencesXpath->query('//*[@data-preferences-panel and @hidden]')->length === 0,
        'Zonder JavaScript blijven alle voorkeuren zichtbaar: ' . $preferencesTheme);
    check($preferencesXpath->query('//form//form')->length === 0
        && $preferencesXpath->query('//*[@data-preferences-panel="api-key"]//input[@id="new-api-key" and @value="new-key-visible-once"]')->length === 1
        && str_contains($preferencesHtml, 'Instellingen opgeslagen.')
        && str_contains($preferencesHtml, 'reader&lt;test&gt;')
        && !str_contains($preferencesHtml, 'href="/admin"')
        && str_contains($preferencesHtml, '/assets/downloader.js'),
        'Tabs behouden status, escaping, rol, gedeeld script en eenmalige API-key zonder geneste formulieren: ' . $preferencesTheme);
}
