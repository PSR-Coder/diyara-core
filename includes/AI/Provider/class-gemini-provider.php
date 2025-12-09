<?php
/**
 * Gemini provider wrapper for Diyara.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI\Provider;

use DiyaraCore\AI\AI_Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Gemini_Provider
 */
class Gemini_Provider {

    /**
     * API key.
     *
     * @var string
     */
    protected $api_key;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = AI_Settings::instance()->get_api_key( 'gemini' );
    }

    /**
     * Ensure we have config.
     *
     * @param string $model Model name.
     * @return bool|\WP_Error
     */
    protected function ensure_config( $model ) {
        if ( empty( $this->api_key ) ) {
            return new \WP_Error( 'diyara_ai_no_key', __( 'Gemini API key is missing in Diyara → Settings.', 'diyara-core' ) );
        }
        if ( empty( $model ) ) {
            return new \WP_Error( 'diyara_ai_no_model', __( 'AI model is not set for this campaign.', 'diyara-core' ) );
        }
        return true;
    }

    /**
     * Base endpoint for Gemini generateContent.
     */
    protected function get_endpoint( $model ) {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode( $model ),
            rawurlencode( $this->api_key )
        );
    }

    /**
     * Low-level HTTP call to Gemini.
     */
    protected function send_request( $model, array $body ) {
        $endpoint = $this->get_endpoint( $model );

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 60,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error(
                'diyara_ai_http_error',
                sprintf( 'Gemini HTTP error %d: %s', $code, mb_substr( $body, 0, 200 ) )
            );
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return new \WP_Error( 'diyara_ai_bad_json', __( 'Unexpected JSON response from Gemini.', 'diyara-core' ) );
        }

        return $data;
    }

    /**
     * Extract full text from Gemini response.
     */
    protected function extract_candidate_text( array $data ) {
        if ( empty( $data['candidates'][0]['content']['parts'] ) || ! is_array( $data['candidates'][0]['content']['parts'] ) ) {
            return '';
        }
        $texts = array();
        foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
            if ( is_array( $part ) && isset( $part['text'] ) ) {
                $texts[] = (string) $part['text'];
            }
        }
        return implode( '', $texts );
    }

    /**
     * Utility: robust JSON extraction.
     */
    protected function extract_json_from_text( $text ) {
        $text = trim( (string) $text );
        if ( '' === $text ) {
            return new \WP_Error( 'diyara_ai_empty', __( 'AI provider returned empty text.', 'diyara-core' ) );
        }
        $clean = str_replace( array( '```json', '```' ), '', $text );
        $clean = trim( $clean );
        $data  = json_decode( $clean, true );
        if ( is_array( $data ) ) {
            return $data;
        }
        // Fallback extraction
        $start = strpos( $clean, '{' );
        $end   = strrpos( $clean, '}' );
        if ( false === $start || false === $end || $end <= $start ) {
            return new \WP_Error( 'diyara_ai_no_json', __( 'AI did not return a valid JSON object.', 'diyara-core' ) );
        }
        $json_str = mb_substr( $clean, $start, $end - $start + 1 );
        $data2    = json_decode( $json_str, true );
        if ( is_array( $data2 ) ) {
            return $data2;
        }
        return new \WP_Error( 'diyara_ai_json_parse', __( 'Failed to parse JSON from AI response.', 'diyara-core' ) );
    }

    /**
     * Normalize AI Response.
     */
    protected function normalize_ai_response( array $ai_data ) {
        $seo = isset( $ai_data['seo'] ) && is_array( $ai_data['seo'] ) ? $ai_data['seo'] : array();
        return array(
            'title'            => isset( $seo['seoTitle'] ) ? (string) $seo['seoTitle'] : '',
            'content'          => isset( $ai_data['htmlContent'] ) ? (string) $ai_data['htmlContent'] : '',
            'meta_description' => isset( $seo['metaDescription'] ) ? (string) $seo['metaDescription'] : '',
            'focus_keyphrase'  => isset( $seo['focusKeyphrase'] ) ? (string) $seo['focusKeyphrase'] : '',
            'long_tail_keyword'=> isset( $seo['longTailKeyword'] ) ? (string) $seo['longTailKeyword'] : '',
            'slug'             => isset( $seo['slug'] ) ? (string) $seo['slug'] : '',
            'image_alt'        => isset( $seo['imageAlt'] ) ? (string) $seo['imageAlt'] : '',
            'synonyms'         => isset( $seo['synonyms'] ) ? (string) $seo['synonyms'] : '',
            '_raw'             => $ai_data,
        );
    }

    /**
     * Returns the "Forbidden List" of AI words to ensure human-sounding text.
     *
     * @return string
     */
    private function get_negative_constraints() {
        return <<<TEXT
NEGATIVE CONSTRAINTS (CRITICAL):
1.  **FORBIDDEN VOCABULARY**: Strictly do NOT use the following words or phrases. They make the text sound robotic and AI-generated:
    - "Delve", "Dive in", "In this article", "In the realm of", "Landscape", "Tapestry", "Testament", "Underscore", "Showcase"
    - "Pivotal", "Nuanced", "Resonate", "It is important to note", "Furthermore", "Moreover", "In conclusion"
    - "Breathtaking", "Stunning", "Seamless", "Immersive" (unless describing VR).
2.  **NO FLUFF**: Do not use generic openers like "In the fast-paced world of..." or "Let's explore...".
3.  **NO HEDGING**: Be confident. Don't say "It remains to be seen." Say "We are waiting to see."
4.  **HUMAN VARIANCE**: Do not start every sentence with "The [Noun]..." or "With [Noun]...". Vary your sentence structure.
TEXT;
    }

    /* -------------------------------------------------------------------------
     * Public high-level methods
     * ---------------------------------------------------------------------- */

    /**
     * REWRITE MODE
     */
    public function rewrite_content( $original_content, $source_title, $existing_context = '', $opts = array() ) {
        $defaults = array(
            'min_words'             => 600,
            'max_words'             => 1000,
            'custom_prompt'         => '',
            'model'                 => 'gemini-1.5-flash', // Updated default
            'temperature'           => 0.7,
            'tone'                  => 'enthusiastic and conversational',
            'audience'              => 'general online readers',
            'brand_voice'           => '',
            'language'              => 'English',
            'post_category'         => 'general',
            'max_headings'          => 0,
            'match_length'          => true,
            'source_word_count'     => 0,
            'match_headings'        => true,
            'match_tone'            => true,
            'match_brand_voice'     => true,
            'rewrite_mode'          => 'strict',
            'source_paragraph_count'=> 0,
            'source_paragraphs_text'=> '',
        );
        $opts = wp_parse_args( $opts, $defaults );

        // Extract settings
        $model           = $opts['model'];
        $base_temp       = (float) $opts['temperature'];
        $mode            = strtolower( $opts['rewrite_mode'] );
        if ( ! in_array( $mode, array( 'loose', 'normal', 'strict' ), true ) ) $mode = 'strict';

        // Dynamic Temperature Adjustment
        if ( 'strict' === $mode ) {
            $temperature = 0.1; // Strict needs low creativity
        } elseif ( 'loose' === $mode ) {
            $temperature = max( 0.7, $base_temp ); // Loose needs high creativity
        } else {
            $temperature = 0.5; // Normal
        }

        $ok = $this->ensure_config( $model );
        if ( is_wp_error( $ok ) ) return $ok;

        // Build constraint strings
        $negative_constraints = $this->get_negative_constraints();
        
        $tone_instruction = $opts['match_tone'] 
            ? "Analyze the source tone and replicate it exactly." 
            : "Tone: {$opts['tone']}.";

        $voice_instruction = ( $opts['brand_voice'] )
            ? "Adopt this Brand Voice: " . $opts['brand_voice']
            : "";

        $length_instruction = ( $opts['match_length'] && $opts['source_word_count'] > 0 )
            ? "Keep the length similar to the source (approx {$opts['source_word_count']} words)."
            : "Target word count: Between {$opts['min_words']} and {$opts['max_words']} words.";

        $headings_instruction = ( $opts['match_headings'] )
            ? "Maintain the same heading structure as the source."
            : "Use approximately {$opts['max_headings']} subheadings (<h2> or <h3>).";

        $summary_instruction = ( $opts['max_words'] >= 600 )
            ? "Include a <h3>Quick Summary</h3> at the very top with 3 bullet points."
            : "Do NOT include a summary section.";

        // --- PROMPT CONSTRUCTION ---

        if ( 'strict' === $mode && $opts['source_paragraph_count'] > 0 ) {
            // *** STRICT MODE ***
            $system_prompt = <<<PROMPT
You are an expert human editor for a "{$opts['post_category']}" blog.
Your task is to REWRITE the source text PARAGRAPH BY PARAGRAPH.

LANGUAGE: Write entirely in {$opts['language']}.

STRICT REWRITE RULES:
1. **1-to-1 Mapping**: The source has {$opts['source_paragraph_count']} paragraphs. You must output exactly {$opts['source_paragraph_count']} paragraphs in the 'htmlContent'.
   - Source Para 1 -> Your Para 1
   - Source Para 2 -> Your Para 2
   - ...and so on.
2. **Fact Preservation**: Do not add new opinions, outside facts, or future predictions. Only rewrite what is there.
3. **Structure**: Keep the flow identical. If the source discusses Topic A then Topic B, you must do the same.

{$negative_constraints}

TONE & STYLE:
- {$tone_instruction}
- {$voice_instruction}

SEO INSTRUCTIONS:
- Derive a Focus Keyphrase from the main entity of the text.
- SEO Title: Must be click-worthy, under 60 chars, and include the keyphrase.
- Meta Description: Under 160 chars, acting as a hook.

SOURCE TITLE: "{$source_title}"
SOURCE PARAGRAPHS (Plain Text):
{$opts['source_paragraphs_text']}

EXISTING CONTEXT (for internal linking only if relevant):
{$existing_context}

FORMATTING:
- Output valid HTML for the 'htmlContent' (use <p>, <h2>, <h3>, <ul>, <li>).
- DO NOT use <h1>, <html>, or <body> tags.
- Remove external links, "Read More" prompts, or calls to action from the source.

OUTPUT FORMAT:
Return ONLY a JSON object (no markdown fences).
PROMPT;

        } elseif ( 'loose' === $mode ) {
            // *** LOOSE MODE ***
            $system_prompt = <<<PROMPT
You are a creative senior columnist for a "{$opts['post_category']}" blog.
Your task is to REIMAGINE the source story to make it more engaging and viral.

LANGUAGE: Write entirely in {$opts['language']}.

CREATIVE RULES:
1. **The Hook**: Don't just repeat the news. Find the most exciting angle and start with that.
2. **Engagement**: Ask a rhetorical question or address the reader directly ("You won't believe this...").
3. **Freedom**: You can merge paragraphs, reorder points for dramatic effect, and add context if it helps the reader understand.
4. **Accuracy**: You can change the flow, but do NOT make up quotes or fake numbers.

{$negative_constraints}

TONE & STYLE:
- {$tone_instruction}
- {$voice_instruction}
- Make it punchy. Use short sentences mixed with long ones for rhythm.

{$length_instruction}
{$headings_instruction}
{$summary_instruction}

SOURCE TITLE: "{$source_title}"
SOURCE CONTENT:
{$original_content}

EXISTING CONTEXT:
{$existing_context}

OUTPUT FORMAT:
Return ONLY a JSON object (no markdown fences).
PROMPT;

        } else {
            // *** NORMAL MODE ***
            $system_prompt = <<<PROMPT
You are a professional journalist for a "{$opts['post_category']}" website.
Your task is to REWRITE the source article to be unique and plagiarism-free, while keeping the original meaning.

LANGUAGE: Write entirely in {$opts['language']}.

REWRITE GUIDELINES:
1. **Flow**: Read the source, understand the core message, and write it in your own words.
2. **No Robot Speak**: Avoid the forbidden words list below strictly.
3. **Balancing**: Keep all the key facts (names, dates, numbers) exactly as they are. You can rephrase the analysis or descriptions.
4. **Formatting**: Break up large walls of text into smaller, readable paragraphs (2-3 sentences max).

{$negative_constraints}

TONE & STYLE:
- {$tone_instruction}
- {$voice_instruction}

{$length_instruction}
{$headings_instruction}
{$summary_instruction}

SOURCE TITLE: "{$source_title}"
SOURCE CONTENT:
{$original_content}

EXISTING CONTEXT:
{$existing_context}

OUTPUT FORMAT:
Return ONLY a JSON object (no markdown fences).
PROMPT;
        }

        // Add the JSON Schema to ensure the AI knows how to format the response
        $system_prompt .= "\n\nREQUIRED JSON STRUCTURE:\n" . <<<JSON
{
  "htmlContent": "<p>...</p>",
  "seo": {
    "focusKeyphrase": "Main keyword",
    "longTailKeyword": "Specific search phrase",
    "seoTitle": "Optimized Title",
    "metaDescription": "Summary",
    "slug": "url-slug",
    "imageAlt": "Alt text",
    "synonyms": "keyword1, keyword2"
  }
}
JSON;

        // Custom Prompt Override
        if ( ! empty( $opts['custom_prompt'] ) && strlen( trim( $opts['custom_prompt'] ) ) > 10 ) {
             // If user provides a custom prompt, we trust them but append the JSON requirement and Negative constraints
             $system_prompt = $opts['custom_prompt'] . "\n\n" . $negative_constraints . "\n\nOutput as JSON: " . $json_instruction;
        }

        // Send Request
        $body = array(
            'contents' => array(
                array(
                    'role'  => 'user',
                    'parts' => array( array( 'text' => $system_prompt ) ),
                ),
            ),
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
                'temperature'      => $temperature,
            ),
        );

        $data = $this->send_request( $model, $body );
        if ( is_wp_error( $data ) ) return $data;

        $text = $this->extract_candidate_text( $data );
        if ( '' === $text ) return new \WP_Error( 'diyara_ai_empty', __( 'Gemini returned empty content.', 'diyara-core' ) );

        $ai_data = $this->extract_json_from_text( $text );
        if ( is_wp_error( $ai_data ) ) return $ai_data;

        return $this->normalize_ai_response( $ai_data );
    }

    /**
     * DIRECT URL MODE
     */
    public function generate_from_url( $source_url, $post_category, $opts = array() ) {
        $defaults = array(
            'min_words'    => 600,
            'max_words'    => 1000,
            'custom_prompt'=> '',
            'model'        => 'gemini-2.5-flash',
            'temperature'  => 0.7,
            'tone'         => 'enthusiastic and conversational',
            'audience'     => 'general online readers',
            'brand_voice'  => '',
            'language'     => 'English',
        );
        $opts = wp_parse_args( $opts, $defaults );

        $model = $opts['model'];
        $ok = $this->ensure_config( $model );
        if ( is_wp_error( $ok ) ) return $ok;

        $negative_constraints = $this->get_negative_constraints();
        
        $system_prompt = <<<PROMPT
You are a professional web editor for a "{$post_category}" blog.
Your task is to read the URL provided, understand the content, and write a FRESH, ORIGINAL article based on it.

LANGUAGE: Write entirely in {$opts['language']}.

INPUT URL: {$source_url}

WRITING RULES:
1. **Fresh Perspective**: Do not just summarize. Explain *why* this matters to the reader.
2. **Structure**: Use a strong Hook -> Body (with <h2> headings) -> Conclusion.
3. **Plagiarism Check**: Do not copy sentences directly from the source. Paraphrase everything.
4. **Formatting**: Use clean HTML (<p>, <h2>, <ul>, <strong>). No <h1>.

{$negative_constraints}

TONE: {$opts['tone']}
TARGET AUDIENCE: {$opts['audience']}
BRAND VOICE: {$opts['brand_voice']}

LENGTH: {$opts['min_words']} - {$opts['max_words']} words.

REQUIRED OUTPUT FORMAT (JSON):
{
  "htmlContent": "HTML body content...",
  "seo": {
    "focusKeyphrase": "...",
    "longTailKeyword": "...",
    "seoTitle": "...",
    "metaDescription": "...",
    "slug": "...",
    "imageAlt": "...",
    "synonyms": "..."
  }
}
DO NOT wrap the JSON in markdown code blocks.
PROMPT;

        $body = array(
            'contents' => array(
                array(
                    'role'  => 'user',
                    'parts' => array( array( 'text' => $system_prompt ) ),
                ),
            ),
            'tools' => array(
                array( 'googleSearch' => (object) array() ),
            ),
            'generationConfig' => array(
                'temperature' => (float) $opts['temperature'],
            ),
        );

        $data = $this->send_request( $model, $body );
        if ( is_wp_error( $data ) ) return $data;

        $text = $this->extract_candidate_text( $data );
        if ( '' === $text ) return new \WP_Error( 'diyara_ai_empty', __( 'Gemini returned empty content (direct URL).', 'diyara-core' ) );

        $ai_data = $this->extract_json_from_text( $text );
        if ( is_wp_error( $ai_data ) ) return $ai_data;

        return $this->normalize_ai_response( $ai_data );
    }
}