<?php
/**
 * Content sources: RSS, sitemap discovery, and HTML scraping.
 *
 * Inspired by externalServices.ts and sitemapService.ts from AutoBlog-AI,
 * but adapted to server-side PHP in WordPress.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sources
 */
class Sources {

	/**
	 * Singleton.
	 *
	 * @var Sources|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Sources
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init hooks (placeholder for future admin AJAX, etc.).
	 *
	 * @return void
	 */
	public function init() {
		// Later we can register admin-ajax or admin-post hooks for "Test source" buttons.
	}

	/* -------------------------------------------------------------------------
	 *  Public API
	 * ---------------------------------------------------------------------- */

	/**
	 * Fetch candidate URLs from a source (RSS or DIRECT site).
	 *
	 * Equivalent of fetchCandidatesFromSource(url, type) in TS.
	 *
	 * @param string        $url        Source URL (RSS feed or site URL).
	 * @param string        $type       'RSS' or 'DIRECT'.
	 * @param int           $max_items  Max items to return.
	 * @param callable|null $logger     Optional logger: function( string $msg, string $level ).
	 * @return array[]                  Each: [ 'link' => string, 'pubDate' => string ].
	 */
	public function fetch_candidates_from_source( $url, $type = 'RSS', $max_items = 50, $logger = null ) {
		$type = strtoupper( $type );
		$url  = trim( $url );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		if ( $logger ) {
			$logger( sprintf( '[Sources] Starting discovery: %s (%s)', $url, $type ), 'info' );
		}

		if ( 'RSS' === $type ) {
			$items = $this->fetch_rss_items( $url, $max_items, $logger );
			if ( empty( $items ) ) {
				return array();
			}
			return array_map(
				function ( $item ) {
					return array(
						'link'    => $item['link'],
						'pubDate' => $item['pubDate'],
					);
				},
				$items
			);
		}

		// DIRECT: use sitemap discovery.
		return $this->find_candidate_urls_from_sitemap( $url, $logger, $max_items );
	}

	/**
	 * Scrape a single article page (title, content HTML, image).
	 *
	 * PHP port (simplified) of scrapeSinglePage() in TS.
	 *
	 * @param string        $base_url   Site base URL (for resolving relative links).
	 * @param string        $article_url Article URL to scrape.
	 * @param callable|null $logger      Optional logger.
	 * @return array|null                [ 'title','link','content','pubDate','guid','image_url' ] or null on failure.
	 */
	public function scrape_single_page( $base_url, $article_url, $logger = null ) {
		if ( $logger ) {
			$logger( sprintf( '[Scraper] Fetching article: %s', $article_url ), 'info' );
		}

		$html = $this->fetch_string( $article_url, 15, $logger );
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			if ( $logger ) {
				$logger( '[Scraper] Failed to fetch HTML.', 'error' );
			}
			return null;
		}

		// Load DOM.
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		// Remove unwanted elements.
		$this->remove_nodes_by_tag( $dom, array( 'script', 'style', 'noscript', 'iframe', 'svg' ) );

		$unwanted_class_contains = array(
			'jwplayer', 'jw-player', 'video-container', 'sticky-video', 'video-wrapper',
			'post-meta', 'entry-meta', 'article-meta', 'author', 'byline', 'post-info',
			'date', 'published', 'updated', 'time', 'entry-date',
			'share-buttons', 'social-icons', 'related-posts', 'social-share',
			'ads', 'advertisement', 'ad-container',
			'sidebar', 'widget-area',
		);

		foreach ( $unwanted_class_contains as $class_fragment ) {
			$query = sprintf( "//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $class_fragment );
			$this->remove_nodes_by_xpath( $xpath, $query );
		}

		// Title.
		$title = '';
		$h1    = $xpath->query( '//h1' );
		if ( $h1 && $h1->length > 0 ) {
			$title = trim( $h1->item( 0 )->textContent );
		}
		if ( ! $title ) {
			$title_nodes = $xpath->query( '//title' );
			if ( $title_nodes && $title_nodes->length > 0 ) {
				$title = trim( $title_nodes->item( 0 )->textContent );
			}
		}

		// Image (og:image or first image in article/content).
		$image_url = '';
		$meta_img  = $xpath->query( "//meta[@property='og:image']/@content" );
		if ( $meta_img && $meta_img->length > 0 ) {
			$image_url = trim( $meta_img->item( 0 )->nodeValue );
		}
		if ( ! $image_url ) {
			$first_img = $xpath->query( '//article//img | //*[@class="entry-content"]//img | //*[@class="post-content"]//img' );
			if ( $first_img && $first_img->length > 0 ) {
				$image_url = $this->absolutize_url( $base_url, $first_img->item( 0 )->getAttribute( 'src' ) );
			}
		}

		// Main content.
		$content_html = $this->extract_main_content_html( $dom, $xpath );
		if ( ! $content_html ) {
			if ( $logger ) {
				$logger( '[Scraper] Could not find main content, falling back to <p> tags.', 'warning' );
			}
			$content_html = $this->extract_paragraphs_as_html( $dom, $xpath );
		}

		if ( ! $title || strlen( wp_strip_all_tags( $content_html ) ) < 50 ) {
			if ( $logger ) {
				$logger( '[Scraper] Content too short or missing title.', 'error' );
			}
			return null;
		}

		// Validate for blocked content.
		if ( ! self::validate_scraped_content( $title, $content_html ) ) {
			if ( $logger ) {
				$logger( '[Scraper] Content appears blocked or is a security page.', 'error' );
			}
			return null;
		}

		return array(
			'title'     => $title,
			'link'      => $article_url,
			'content'   => $content_html,
			'pubDate'   => gmdate( 'c' ),
			'guid'      => $article_url,
			'image_url' => $image_url,
		);
	}

	/**
	 * Validate scraped content (similar to validateScrapedContent in TS).
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return bool
	 */
	public static function validate_scraped_content( $title, $content ) {
		$blocked_phrases = array(
			'sorry, you have been blocked',
			'attention required! | cloudflare',
			'access denied',
			'403 forbidden',
			'please enable cookies',
			'security check to access',
			'challenge validation',
		);

		$combined = mb_strtolower( $title . ' ' . $content );
		foreach ( $blocked_phrases as $phrase ) {
			if ( false !== strpos( $combined, $phrase ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Test a source (for admin "Test source" UI).
	 *
	 * @param string $url  Source URL.
	 * @param string $type 'RSS' or 'DIRECT'.
	 * @return array       [ 'success' => bool, 'item' => array|null, 'error' => string|null ].
	 */
	public function test_source( $url, $type = 'RSS' ) {
		$results = array(
			'success' => false,
			'item'    => null,
			'error'   => null,
		);

		$logger = function ( $msg, $level = 'info' ) {
			// You can log to debug.log if needed:
			// error_log( "[Diyara Sources][$level] $msg" );
		};

		$candidates = $this->fetch_candidates_from_source( $url, $type, 10, $logger );
		if ( empty( $candidates ) ) {
			$results['error'] = __( 'No candidates found from source.', 'diyara-core' );
			return $results;
		}

		$first = $candidates[0];

		$base = $url;
		if ( 'RSS' === strtoupper( $type ) ) {
			$parts = wp_parse_url( $url );
			if ( $parts && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
				$base = $parts['scheme'] . '://' . $parts['host'] . '/';
			}
		}

		$item = $this->scrape_single_page( $base, $first['link'], $logger );
		if ( ! $item ) {
			$results['error'] = __( 'Failed to scrape test article.', 'diyara-core' );
			return $results;
		}

		if ( ! self::validate_scraped_content( $item['title'], $item['content'] ) ) {
			$results['error'] = __( 'Content appears blocked by the source site.', 'diyara-core' );
			return $results;
		}

		$results['success'] = true;
		$results['item']    = $item;

		return $results;
	}

	/* -------------------------------------------------------------------------
	 *  RSS helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Fetch and parse RSS feed items.
	 *
	 * @param string        $url       RSS URL.
	 * @param int           $max_items Max items.
	 * @param callable|null $logger    Optional logger.
	 * @return array[]                 Each: [ 'title','link','content','pubDate','guid','imageUrl' ].
	 */
	protected function fetch_rss_items( $url, $max_items = 10, $logger = null ) {
		$xml_str = $this->fetch_string( $url, 15, $logger );
		if ( ! is_string( $xml_str ) || '' === trim( $xml_str ) ) {
			return array();
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_str );
		libxml_clear_errors();

		if ( ! $xml ) {
			if ( $logger ) {
				$logger( '[RSS] Failed to parse XML.', 'error' );
			}
			return array();
		}

		$items = array();

		// Handle both <item> and Atom <entry>.
		if ( isset( $xml->channel->item ) ) {
			$list = $xml->channel->item;
		} else {
			$list = $xml->entry;
		}

		$count = 0;
		foreach ( $list as $item ) {
			if ( $count >= $max_items ) {
				break;
			}

			$title = (string) ( $item->title ?? '' );

			$link = '';
			if ( isset( $item->link ) ) {
				// RSS: <link>http...</link> or Atom: <link href="..."/>
				if ( isset( $item->link['href'] ) ) {
					$link = (string) $item->link['href'];
				} else {
					$link = (string) $item->link;
				}
			}

			$content = '';
			if ( isset( $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded ) ) {
				$content = (string) $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded;
			} elseif ( isset( $item->description ) ) {
				$content = (string) $item->description;
			}

			$pubDate = '';
			if ( isset( $item->pubDate ) ) {
				$pubDate = (string) $item->pubDate;
			} elseif ( isset( $item->updated ) ) {
				$pubDate = (string) $item->updated;
			} else {
				$pubDate = gmdate( 'c' );
			}

			$guid = isset( $item->guid ) ? (string) $item->guid : $link;

			// Image extraction:
			$image_url = '';
			if ( isset( $item->enclosure['url'] ) ) {
				$image_url = (string) $item->enclosure['url'];
			}

			$items[] = array(
				'title'    => $title,
				'link'     => $this->strip_query( (string) $link ),
				'content'  => $content,
				'pubDate'  => $pubDate,
				'guid'     => $guid ?: $this->strip_query( (string) $link ),
				'imageUrl' => $image_url ?: null,
			);
			$count++;
		}

		if ( $logger ) {
			$logger( sprintf( '[RSS] Parsed %d items.', count( $items ) ), 'info' );
		}

		return $items;
	}

	/* -------------------------------------------------------------------------
	 *  Sitemap discovery (PHP port of sitemapService.ts)
	 * ---------------------------------------------------------------------- */

	/**
	 * Discover candidate URLs via sitemap.
	 *
	 * @param string        $base_url   Site or sitemap URL.
	 * @param callable|null $logger     Optional logger.
	 * @param int           $max_items  Max items to return.
	 * @return array[]                  Each: [ 'link','pubDate' ].
	 */
	public function find_candidate_urls_from_sitemap( $base_url, $logger = null, $max_items = 200 ) {
		$base_url = trim( $base_url );
		if ( ! preg_match( '#^https?://#i', $base_url ) ) {
			$base_url = 'https://' . ltrim( $base_url, '/' );
		}

		if ( $logger ) {
			$logger( sprintf( '[Sitemap] Starting discovery for: %s', $base_url ), 'info' );
		}

		$sitemap_xml   = null;
		$found_at_url  = '';

		// CASE A: direct XML.
		if ( preg_match( '#\.xml($|\?)#i', $base_url ) ) {
			$sitemap_xml  = $this->fetch_string( $base_url, 15, $logger );
			$found_at_url = $base_url;
		} else {
			// CASE B: domain; try common variations.
			$domain = rtrim( $base_url, '/' );
			$candidates = array(
				'/sitemap_index.xml',
				'/sitemap.xml',
				'/wp-sitemap.xml',
				'/post-sitemap.xml',
				'/sitemap_posts.xml',
			);

			foreach ( $candidates as $path ) {
				$target = $domain . $path;
				$xml    = $this->fetch_string( $target, 15, $logger );
				if ( is_string( $xml ) && ( false !== strpos( $xml, '<sitemap' ) || false !== strpos( $xml, '<url' ) ) ) {
					$sitemap_xml  = $xml;
					$found_at_url = $target;
					if ( $logger ) {
						$logger( sprintf( '[Sitemap] Found XML at: %s', $target ), 'success' );
					}
					break;
				}
			}
		}

		if ( ! is_string( $sitemap_xml ) || '' === trim( $sitemap_xml ) ) {
			if ( $logger ) {
				$logger( '[Sitemap] Could not find a valid sitemap XML.', 'error' );
			}
			return array();
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $sitemap_xml );
		libxml_clear_errors();

		if ( ! $xml ) {
			if ( $logger ) {
				$logger( '[Sitemap] Failed to parse XML.', 'error' );
			}
			return array();
		}

		// If it has <sitemap> children, treat as index.
		if ( isset( $xml->sitemap ) && count( $xml->sitemap ) > 0 ) {
			if ( $logger ) {
				$logger( sprintf( '[Sitemap] Processing index with %d child sitemaps.', count( $xml->sitemap ) ), 'info' );
			}

			$maps = array();
			foreach ( $xml->sitemap as $s ) {
				$loc       = (string) ( $s->loc ?? '' );
				$lastmod   = (string) ( $s->lastmod ?? '' );
				$last_ts   = $lastmod ? strtotime( $lastmod ) : 0;
				$maps[]    = array(
					'loc'     => $loc,
					'lastmod' => $last_ts,
				);
			}

			// Filter for post/news/article sitemaps.
			$post_maps = array_filter(
				$maps,
				function ( $m ) {
					$l       = strtolower( $m['loc'] );
					$is_post = ( false !== strpos( $l, 'post' ) || false !== strpos( $l, 'news' ) || false !== strpos( $l, 'article' ) );
					$is_junk = ( false !== strpos( $l, 'image' ) || false !== strpos( $l, 'video' ) || false !== strpos( $l, 'author' ) || false !== strpos( $l, 'tag' ) || false !== strpos( $l, 'category' ) );
					return $is_post && ! $is_junk;
				}
			);

			if ( $logger ) {
				$logger( sprintf( '[Sitemap] Filtered to %d post-like sitemaps.', count( $post_maps ) ), 'info' );
			}

			$candidates = ! empty( $post_maps ) ? $post_maps : $maps;

			// Sort by numeric suffix or lastmod.
			usort(
				$candidates,
				function ( $a, $b ) {
					$get_seq = function ( $loc ) {
						if ( preg_match( '#(\d+)\.xml$#', $loc, $m ) ) {
							return (int) $m[1];
						}
						if ( false !== strpos( $loc, 'post-sitemap.xml' ) ) {
							return 1;
						}
						return -1;
					};
					$na = $get_seq( $a['loc'] );
					$nb = $get_seq( $b['loc'] );

					if ( $na > -1 && $nb > -1 && $na !== $nb ) {
						return $nb - $na;
					}
					return $b['lastmod'] - $a['lastmod'];
				}
			);

			if ( empty( $candidates ) ) {
				return array();
			}

			$target_sitemap = $candidates[0]['loc'];
			if ( $logger ) {
				$logger( sprintf( '[Sitemap] Using child sitemap: %s', $target_sitemap ), 'success' );
			}

			$leaf_xml = $this->fetch_string( $target_sitemap, 15, $logger );
			if ( ! is_string( $leaf_xml ) || '' === trim( $leaf_xml ) ) {
				if ( $logger ) {
					$logger( '[Sitemap] Failed to fetch child sitemap.', 'error' );
				}
				return array();
			}

			return $this->parse_leaf_sitemap( $leaf_xml, $logger, $max_items );
		}

		// No <sitemap> entries, assume this is a leaf sitemap (<url> entries).
		if ( $logger ) {
			$logger( '[Sitemap] Treating as leaf sitemap.', 'info' );
		}

		return $this->parse_leaf_sitemap( $sitemap_xml, $logger, $max_items );
	}

	/**
	 * Parse a leaf sitemap with <url> entries.
	 *
	 * @param string        $xml_str   Sitemap XML.
	 * @param callable|null $logger    Optional logger.
	 * @param int           $max_items Max items.
	 * @return array[]
	 */
	protected function parse_leaf_sitemap( $xml_str, $logger = null, $max_items = 200 ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_str );
		libxml_clear_errors();

		if ( ! $xml ) {
			return array();
		}

		$urls  = array();
		$count = 0;
		if ( isset( $xml->url ) ) {
			foreach ( $xml->url as $u ) {
				if ( $count >= $max_items ) {
					break;
				}
				$loc        = (string) ( $u->loc ?? '' );
				$lastmod    = (string) ( $u->lastmod ?? '' );
				$urls[]     = array(
					'link'    => $loc,
					'pubDate' => $lastmod ?: gmdate( 'c' ),
				);
				$count++;
			}
		}

		if ( $logger ) {
			$logger( sprintf( '[Sitemap] Parsed %d URLs.', count( $urls ) ), 'info' );
		}

		return $urls;
	}

	/* -------------------------------------------------------------------------
	 *  HTTP helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Fetch a URL as string with a browser-like user agent.
	 *
	 * @param string        $url     URL.
	 * @param int           $timeout Timeout seconds.
	 * @param callable|null $logger  Optional logger.
	 * @return string|false
	 */
	protected function fetch_string( $url, $timeout = 10, $logger = null ) {
		if ( $logger ) {
			$logger( sprintf( '[HTTP] GET %s', $url ), 'info' );
		}

		$args = array(
			'timeout'   => $timeout,
			'sslverify' => true,
			'headers'   => array(
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 DiyaraBot',
			),
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( $logger ) {
				$logger( '[HTTP] Error: ' . $response->get_error_message(), 'error' );
			}
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || strlen( $body ) < 50 ) {
			if ( $logger ) {
				$logger( sprintf( '[HTTP] Non-OK response (%d).', $code ), 'warning' );
			}
			return false;
		}

		// Basic check for proxy/error pages.
		$lower = strtolower( $body );
		if ( false !== strpos( $lower, '403 forbidden' ) || false !== strpos( $lower, 'proxy error' ) || false !== strpos( $lower, 'cloudflare' ) ) {
			if ( $logger ) {
				$logger( '[HTTP] Response looks like an error/protection page.', 'warning' );
			}
			// Still return body; caller may decide what to do.
		}

		return $body;
	}

	/**
	 * Remove nodes by tag name.
	 *
	 * @param \DOMDocument $dom  Document.
	 * @param string[]     $tags Tag names.
	 * @return void
	 */
	protected function remove_nodes_by_tag( \DOMDocument $dom, array $tags ) {
		foreach ( $tags as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			// We must iterate from end to start because live NodeList.
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Remove nodes by XPath query.
	 *
	 * @param \DOMXPath $xpath XPath object.
	 * @param string    $query XPath query.
	 * @return void
	 */
	protected function remove_nodes_by_xpath( \DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		if ( ! $nodes ) {
			return;
		}
		for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
			$node = $nodes->item( $i );
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Extract "main content" HTML using a set of selectors.
	 *
	 * @param \DOMDocument $dom   DOM.
	 * @param \DOMXPath    $xpath XPath.
	 * @return string
	 */
	protected function extract_main_content_html( \DOMDocument $dom, \DOMXPath $xpath ) {
		$selectors = array(
			'.entry-content',
			'.post-content',
			'article',
			'main',
			'#content',
			'.story-content',
			'.post_details',
			'.content-body',
		);

		foreach ( $selectors as $sel ) {
			$query = $this->css_to_xpath( $sel );
			if ( ! $query ) {
				continue;
			}
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length > 0 ) {
				$node = $nodes->item( 0 );
				$text = trim( $node->textContent );
				if ( strlen( $text ) > 100 ) {
					return $this->inner_html( $node );
				}
			}
		}
		return '';
	}

	/**
	 * Extract all <p> tags concatenated as HTML fallback.
	 *
	 * @param \DOMDocument $dom   DOM.
	 * @param \DOMXPath    $xpath XPath.
	 * @return string
	 */
	protected function extract_paragraphs_as_html( \DOMDocument $dom, \DOMXPath $xpath ) {
		$nodes = $xpath->query( '//p' );
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}
		$html = '';
		foreach ( $nodes as $p ) {
			$html .= $dom->saveHTML( $p );
		}
		return $html;
	}

	/**
	 * Convert a small subset of CSS selectors to XPath.
	 *
	 * Supports:
	 *  - .class
	 *  - #id
	 *  - tag
	 *
	 * @param string $selector Selector.
	 * @return string|null
	 */
	protected function css_to_xpath( $selector ) {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			return null;
		}
		if ( '.' === $selector[0] ) {
			$class = substr( $selector, 1 );
			return sprintf( "//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $class );
		}
		if ( '#' === $selector[0] ) {
			$id = substr( $selector, 1 );
			return sprintf( "//*[@id='%s']", $id );
		}
		// Assume tag name.
		return '//' . $selector;
	}

	/**
	 * Get inner HTML of a DOMElement.
	 *
	 * @param \DOMElement $element Element.
	 * @return string
	 */
	protected function inner_html( \DOMElement $element ) {
		$html = '';
		foreach ( $element->childNodes as $child ) {
			$html .= $element->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Absolutize a possibly relative URL against a base.
	 *
	 * @param string $base Base URL.
	 * @param string $link Link.
	 * @return string
	 */
	protected function absolutize_url( $base, $link ) {
		$link = trim( $link );
		if ( '' === $link ) {
			return $link;
		}
		if ( preg_match( '#^https?://#i', $link ) ) {
			return $link;
		}
		if ( 0 === strpos( $link, '//' ) ) {
			$parts = wp_parse_url( $base );
			if ( $parts && ! empty( $parts['scheme'] ) ) {
				return $parts['scheme'] . ':' . $link;
			}
			return 'https:' . $link;
		}
		$resolved = wp_parse_url( $base );
		if ( ! $resolved || empty( $resolved['scheme'] ) || empty( $resolved['host'] ) ) {
			return $link;
		}
		$scheme = $resolved['scheme'];
		$host   = $resolved['host'];
		$path   = isset( $resolved['path'] ) ? rtrim( dirname( $resolved['path'] ), '/' ) : '';
		if ( '/' === $link[0] ) {
			return sprintf( '%s://%s%s', $scheme, $host, $link );
		}
		return sprintf( '%s://%s%s/%s', $scheme, $host, $path, $link );
	}

	/**
	 * Strip querystring from URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function strip_query( $url ) {
		$parts = explode( '?', $url, 2 );
		return $parts[0];
	}
}