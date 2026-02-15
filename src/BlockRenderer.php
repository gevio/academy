<?php
/**
 * BlockRenderer – Konvertiert Notion Block-Array zu HTML.
 *
 * Unterstützte Blocktypen:
 *   paragraph, heading_1/2/3, bulleted_list_item, numbered_list_item,
 *   image, quote, callout, divider, toggle, code, bookmark, video
 *
 * Rich-Text-Annotationen: bold, italic, strikethrough, underline, code, color, links
 */
class BlockRenderer
{
    /**
     * Rendert ein Array von Notion-Blöcken zu HTML.
     */
    public function render(array $blocks): string
    {
        $html = '';
        $i = 0;
        $count = count($blocks);

        while ($i < $count) {
            $block = $blocks[$i];
            $type = $block['type'] ?? '';

            // Listen-Items gruppieren
            if ($type === 'bulleted_list_item') {
                $html .= '<ul>';
                while ($i < $count && ($blocks[$i]['type'] ?? '') === 'bulleted_list_item') {
                    $html .= '<li>' . $this->renderRichText($blocks[$i]['bulleted_list_item']['rich_text'] ?? []) . '</li>';
                    $i++;
                }
                $html .= '</ul>';
                continue;
            }

            if ($type === 'numbered_list_item') {
                $html .= '<ol>';
                while ($i < $count && ($blocks[$i]['type'] ?? '') === 'numbered_list_item') {
                    $html .= '<li>' . $this->renderRichText($blocks[$i]['numbered_list_item']['rich_text'] ?? []) . '</li>';
                    $i++;
                }
                $html .= '</ol>';
                continue;
            }

            $html .= $this->renderBlock($block);
            $i++;
        }

        return $html;
    }

    /**
     * Rendert einen einzelnen Block zu HTML.
     */
    private function renderBlock(array $block): string
    {
        $type = $block['type'] ?? '';

        switch ($type) {
            case 'paragraph':
                $text = $this->renderRichText($block['paragraph']['rich_text'] ?? []);
                return $text ? "<p>{$text}</p>" : '';

            case 'heading_1':
                $text = $this->renderRichText($block['heading_1']['rich_text'] ?? []);
                return "<h2>{$text}</h2>"; // h1 ist Seitentitel, daher h2

            case 'heading_2':
                $text = $this->renderRichText($block['heading_2']['rich_text'] ?? []);
                return "<h3>{$text}</h3>";

            case 'heading_3':
                $text = $this->renderRichText($block['heading_3']['rich_text'] ?? []);
                return "<h4>{$text}</h4>";

            case 'quote':
                $text = $this->renderRichText($block['quote']['rich_text'] ?? []);
                return "<blockquote>{$text}</blockquote>";

            case 'callout':
                $icon = $block['callout']['icon']['emoji'] ?? '';
                $text = $this->renderRichText($block['callout']['rich_text'] ?? []);
                return "<div class=\"callout\">{$icon} {$text}</div>";

            case 'divider':
                return '<hr>';

            case 'image':
                $url = $block['image']['file']['url']
                    ?? $block['image']['external']['url']
                    ?? '';
                $caption = $this->renderRichText($block['image']['caption'] ?? []);
                if (!$url) return '';
                $html = "<figure><img src=\"{$url}\" alt=\"" . strip_tags($caption) . "\" loading=\"lazy\">";
                if ($caption) $html .= "<figcaption>{$caption}</figcaption>";
                $html .= '</figure>';
                return $html;

            case 'code':
                $text = $this->renderRichText($block['code']['rich_text'] ?? []);
                $lang = htmlspecialchars($block['code']['language'] ?? '');
                return "<pre><code class=\"language-{$lang}\">{$text}</code></pre>";

            case 'toggle':
                $summary = $this->renderRichText($block['toggle']['rich_text'] ?? []);
                return "<details><summary>{$summary}</summary></details>";

            case 'bookmark':
                $url = htmlspecialchars($block['bookmark']['url'] ?? '');
                $caption = $this->renderRichText($block['bookmark']['caption'] ?? []);
                $label = $caption ?: $url;
                return "<p><a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$label}</a></p>";

            case 'video':
                $url = $block['video']['external']['url']
                    ?? $block['video']['file']['url']
                    ?? '';
                if (!$url) return '';
                // YouTube embed
                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
                    return "<div class=\"video-embed\"><iframe src=\"https://www.youtube.com/embed/{$m[1]}\" allowfullscreen loading=\"lazy\"></iframe></div>";
                }
                return "<p><a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">Video ansehen</a></p>";

            case 'table_of_contents':
            case 'child_page':
            case 'child_database':
            case 'breadcrumb':
                return ''; // Ignorieren

            default:
                return ''; // Unbekannte Blocktypen überspringen
        }
    }

    /**
     * Rendert ein Rich-Text-Array (Annotationen + Links) zu HTML.
     */
    private function renderRichText(array $richText): string
    {
        $html = '';
        foreach ($richText as $segment) {
            $text = htmlspecialchars($segment['plain_text'] ?? '', ENT_QUOTES, 'UTF-8');
            $annotations = $segment['annotations'] ?? [];
            $href = $segment['href'] ?? ($segment['text']['link']['url'] ?? null);

            // Annotationen anwenden
            if (!empty($annotations['bold']))          $text = "<strong>{$text}</strong>";
            if (!empty($annotations['italic']))        $text = "<em>{$text}</em>";
            if (!empty($annotations['strikethrough'])) $text = "<s>{$text}</s>";
            if (!empty($annotations['underline']))     $text = "<u>{$text}</u>";
            if (!empty($annotations['code']))          $text = "<code>{$text}</code>";

            // Farbe (nur wenn nicht default)
            $color = $annotations['color'] ?? 'default';
            if ($color !== 'default') {
                $cssClass = 'notion-color-' . htmlspecialchars($color);
                $text = "<span class=\"{$cssClass}\">{$text}</span>";
            }

            // Link
            if ($href) {
                $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                $text = "<a href=\"{$href}\" target=\"_blank\" rel=\"noopener\">{$text}</a>";
            }

            $html .= $text;
        }
        return $html;
    }
}
