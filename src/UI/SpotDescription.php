<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;

final class SpotDescription
{
    public static function render(string $description): string
    {
        if (trim($description) === '') {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>'
                . $description . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('Spotomschrijving kon niet worden verwerkt.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            throw new RuntimeException('Spotomschrijving mist een leesbare inhoud.');
        }
        return self::children($body);
    }

    private static function children(DOMNode $node, int $depth = 0, bool $preformatted = false): string
    {
        if ($depth > 100) {
            throw new RuntimeException('Spotomschrijving bevat te diep geneste opmaak.');
        }
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $html .= $preformatted ? h($child->data) : nl2br(h($child->data));
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'input', 'button', 'textarea', 'select', 'meta', 'link', 'base'], true)) {
                continue;
            }
            $content = self::children($child, $depth + 1, $preformatted || $tag === 'pre');
            if ($tag === 'br' || $tag === 'hr') {
                $html .= '<' . $tag . '>';
            } elseif ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if (preg_match('~^https?://~i', $href) && filter_var($href, FILTER_VALIDATE_URL) !== false
                    && preg_match('/[\x00-\x20\x7f]/', $href) !== 1) {
                    $html .= '<a href="' . h($href) . '" target="_blank" rel="noopener noreferrer nofollow">' . $content . '</a>';
                } else {
                    $html .= $content;
                }
            } elseif (in_array($tag, ['p', 'div', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'dl', 'dt', 'dd', 'sub', 'sup'], true)) {
                $html .= '<' . $tag . '>' . $content . '</' . $tag . '>';
            } else {
                $html .= $content;
            }
        }
        return $html;
    }
}
