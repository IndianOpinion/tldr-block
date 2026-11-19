<?php

declare(strict_types=1);

namespace IndianOpinion\TldrBlock;

use IndianOpinion\TldrBlock\Text\Language;
use IndianOpinion\TldrBlock\Text\SourceText;

/**
 * Front-end output: markup, automatic insertion, shortcode, block, feeds
 * and the REST field (PRD cases 56 to 65).
 */
final class Renderer
{
    public const STYLE_HANDLE = 'tldr-block';

    /** @var array<int, true> posts printed in this request */
    private static array $printed = [];

    public static function reset(): void
    {
        self::$printed = [];
    }

    public static function wasPrinted(int $postId): bool
    {
        return isset(self::$printed[$postId]);
    }

    /**
     * Escaped HTML for a post's displayable summary, or ''. Prints once per
     * post per request.
     *
     * @param array{heading?: string, show_heading?: bool, heading_tag?: string, context?: string} $args
     */
    public static function render(?int $postId = null, array $args = []): string
    {
        $postId ??= (int) get_the_ID();
        if ($postId <= 0 || isset(self::$printed[$postId])) {
            return '';
        }
        $summary = Eligibility::displayable($postId);
        if ($summary === null) {
            return '';
        }
        $html = self::markup($summary, $args);
        if ($html === '') {
            return '';
        }
        self::$printed[$postId] = true;
        if (($args['context'] ?? '') !== 'feed' && apply_filters('tldr_block_load_styles', true) === true) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }

        return $html;
    }

    /**
     * @param array{heading?: string, show_heading?: bool, heading_tag?: string, context?: string} $args
     */
    public static function markup(Summary $summary, array $args = []): string
    {
        $settings = Settings::get();
        $heading = array_key_exists('heading', $args) ? (string) $args['heading'] : $settings['heading'];
        $showHeading = ($args['show_heading'] ?? true) && $heading !== '';
        $tag = $args['heading_tag'] ?? apply_filters('tldr_block_heading_tag', 'p');
        $tag = in_array($tag, ['p', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $tag : 'p';
        $label = $heading !== '' ? $heading : __('Summary', 'tldr-block');

        $attrs = ' class="tldr-block" aria-label="' . esc_attr($label) . '"';
        $pageLang = Language::codeFromLocale((string) get_bloginfo('language'));
        $lang = $summary->language();
        if ($lang !== '' && Language::codeFromLocale($lang) !== $pageLang) {
            $attrs .= ' lang="' . esc_attr($lang) . '"';
        }
        $html = '<aside' . $attrs . '>';
        if ($showHeading) {
            $html .= '<' . $tag . ' class="tldr-block__heading">' . esc_html($heading) . '</' . $tag . '>';
        }
        if ($summary->format() === 'paragraph') {
            $html .= '<p class="tldr-block__text">' . esc_html($summary->text()) . '</p>';
        } else {
            $html .= '<ul class="tldr-block__points">';
            foreach ($summary->points() as $point) {
                $html .= '<li>' . esc_html($point) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</aside>';
        $filtered = apply_filters('tldr_block_html', $html, $summary, $args);

        return is_string($filtered) ? $filtered : $html;
    }

    /**
     * the_content at priority 20 (case 56, 57, 65).
     */
    public static function filterContent(mixed $content): mixed
    {
        if (!is_string($content)) {
            return $content;
        }
        $postId = (int) get_the_ID();
        if ($postId <= 0 || doing_filter('get_the_excerpt') || doing_filter('wp_trim_excerpt')) {
            return $content;
        }
        $settings = Settings::get();
        if (is_feed()) {
            if ($settings['feeds'] !== 'on') {
                return $content;
            }
            $html = self::render($postId, ['context' => 'feed']);

            return $html === '' ? $content : $html . $content;
        }
        if (defined('REST_REQUEST') && REST_REQUEST && $settings['rest_content'] !== 'on') {
            return $content;
        }
        $isRest = defined('REST_REQUEST') && REST_REQUEST;
        $should = $settings['placement'] !== 'none'
            && ($isRest || (is_singular() && in_the_loop() && is_main_query()))
            && !self::hasOwnMarkup($postId)
            && !isset(self::$printed[$postId]);
        if (!$isRest) {
            $queried = (int) get_queried_object_id();
            $should = $should && $queried === $postId;
        }
        $should = apply_filters('tldr_block_should_autoinsert', $should, $postId);
        if ($should !== true) {
            return $content;
        }
        $html = self::render($postId, ['context' => 'content']);
        if ($html === '') {
            return $content;
        }
        if ($settings['placement'] === 'after_first_paragraph') {
            $pos = self::firstTopLevelParagraphEnd($content);
            if ($pos !== null) {
                return substr($content, 0, $pos) . $html . substr($content, $pos);
            }
        }

        return $html . $content;
    }

    /**
     * Shortcode [tldr post_id="" heading=""] (case 59).
     *
     * @param array<string, string>|string $atts
     */
    public static function shortcode(array|string $atts): string
    {
        $atts = is_array($atts) ? $atts : [];
        $postId = isset($atts['post_id']) && $atts['post_id'] !== '' ? absint($atts['post_id']) : (int) get_the_ID();
        if ($postId <= 0 || !get_post($postId) instanceof \WP_Post) {
            return '';
        }
        $args = ['context' => 'shortcode'];
        if (array_key_exists('heading', $atts)) {
            $args['heading'] = sanitize_text_field($atts['heading']);
        }

        return self::render($postId, $args);
    }

    /**
     * Server-side render for the block (case 60). The placeholder lives in
     * the editor script only, so it never reaches the front end.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderBlock(array $attributes, mixed $content = '', mixed $block = null): string
    {
        // In a Query Loop the block context names the post; fall back to
        // the global post elsewhere.
        $ctxId = is_object($block) && isset($block->context) && is_array($block->context) ? ($block->context['postId'] ?? null) : null;
        $postId = is_numeric($ctxId) && (int) $ctxId > 0 ? (int) $ctxId : (int) get_the_ID();
        $args = ['context' => 'block', 'show_heading' => ($attributes['showHeading'] ?? true) !== false];
        if (isset($attributes['heading']) && is_string($attributes['heading']) && $attributes['heading'] !== '') {
            $args['heading'] = sanitize_text_field($attributes['heading']);
        }
        $html = self::render($postId, $args);
        if ($html === '' || !function_exists('get_block_wrapper_attributes')) {
            return $html;
        }

        return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
    }

    /**
     * REST field value (case 64): only the displayable summary.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    public static function restField(array $post): ?array
    {
        $id = (int) ($post['id'] ?? 0);
        $summary = $id > 0 ? Eligibility::displayable($id) : null;

        return $summary?->toArray();
    }

    public static function hasOwnMarkup(int $postId): bool
    {
        $post = get_post($postId);

        return $post instanceof \WP_Post && SourceText::containsOwnMarkup((string) $post->post_content);
    }

    /** Byte offset just after the first top-level closing p tag. */
    private static function firstTopLevelParagraphEnd(string $html): ?int
    {
        if (preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $void = ['br', 'img', 'hr', 'input', 'meta', 'link', 'source', 'wbr', 'col', 'area', 'embed', 'track', 'param'];
        $depth = 0;
        foreach ($m as $tag) {
            $closing = $tag[1][0] === '/';
            $name = strtolower($tag[2][0]);
            if (in_array($name, $void, true) || $tag[3][0] === '/') {
                continue;
            }
            if ($closing) {
                $depth--;
                if ($depth === 0 && $name === 'p') {
                    return $tag[0][1] + strlen($tag[0][0]);
                }
            } else {
                $depth++;
            }
        }

        return null;
    }
}
