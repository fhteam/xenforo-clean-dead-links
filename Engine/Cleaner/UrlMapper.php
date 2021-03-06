<?php

/**
 * Class FH_LinkCleaner_Engine_Cleaner_UrlMapper
 */
class FH_LinkCleaner_Engine_Cleaner_UrlMapper extends FH_LinkCleaner_Engine_Cleaner_Abstract
{
    /**
     * RegEx to find and parse links of the following format:
     *   - [url="http://example.com"]Link text[/url]
     *   - [url=http://example.com]Link text[/url]
     *   - [url]http://www.example.com[/url]
     */
    const BB_CODE_URL_REGEX = '#\[url(?:=["\']?(.+?)?)?["\']?\](.+?)\[/url\]#ismu';

    /**
     * @var string[]
     */
    private $urlMap;

    /**
     * @var string[]
     */
    private $hostRegExes;

    /**
     * @param string[]        $urlMap
     * @param \Monolog\Logger $logger
     */
    public function __construct(array $urlMap, \Monolog\Logger $logger)
    {
        $this->urlMap = $urlMap;
        $this->hostRegExes = array_keys($urlMap);
        parent::__construct($logger);
    }

    /**
     * @param string   $content
     * @param string[] $deadLinks
     *
     * @return string Cleaned content
     */
    public function clean($content, array $deadLinks)
    {
        $content = preg_replace_callback(self::BB_CODE_URL_REGEX, array($this, 'cleanUrlTagContents'), $content);
        $this->assertIsNotRegExError($content, 'BB_CODE_URL_REGEX');

        return $content;
    }

    /**
     * Method to match and clean [url] enclosed links
     *
     * @param array $matches Array. [0] = full bbcode, [1] = url (if found), [2] = body
     *
     * @return string
     */
    private function cleanUrlTagContents(array $matches)
    {
        $url = !empty($matches[1]) ? $matches[1] : $matches[2];
        $body = $matches[2];
        $originalContents = $matches[0];
        $isSimpleLink = ($url === $body);

        try {
            $linkObj = \League\Uri\Schemes\Http::createFromString($url);
        } catch (Exception $e) {
            $exceptionClass = get_class($e);
            $exceptionMessage = $e->getMessage();
            $this->logger->addError("Unable to parse link '$url'. [{$exceptionClass}]: $exceptionMessage ");

            return $originalContents;
        }

        $host = $linkObj->getHost();
        $matchingHost = $this->findMatchingHostRegEx($host);

        if (null === $matchingHost) {
            return $originalContents; // Not our domain
        }

        $urlRegExes = $this->urlMap[$matchingHost]['links'];

        $hasPath = strlen($linkObj->getPath()) > 0;
        $hasQuery = strlen($linkObj->getQuery()) > 0;
        $hasFragment = strlen($linkObj->getFragment()) > 0;

        if (!$hasPath) {
            return $originalContents; // Link to main page
        }

        $path = $linkObj->getPath()
            .($hasQuery ? '?'.$linkObj->getQuery() : '')
            .($hasFragment ? '#'.$linkObj->getFragment() : '');

        $urlMap = $this->getUrlRegexForPath($urlRegExes, $path);

        if (null === $urlMap) {
            return $originalContents; // Not our url
        }

        list($regEx, $replaceBy) = $urlMap;

        if ($this->urlMap[$matchingHost]['force_https']) {
            $url = str_replace('http://', 'https://', $url);
        }

        $url = is_callable($replaceBy) ? $replaceBy($url, $regEx) : preg_replace($regEx, $replaceBy, $url);

        if ($isSimpleLink) {
            return $this->createSimpleLinkBbCode($url);
        } else {
            $body = preg_replace($regEx, $replaceBy, $body);

            return $this->createFullLinkBbCode($url, $body);
        }
    }

    /**
     * @param string[] $urlRegExes
     * @param string   $path
     *
     * @return null|string[]
     * @throws Exception
     */
    private function getUrlRegexForPath($urlRegExes, $path)
    {
        $result = null;
        foreach ($urlRegExes as $regEx => $replaceBy) {
            try {
                if (!preg_match($regEx, $path)) {
                    continue;
                }
            } catch (Exception $e) {
                $this->logger->addError("Regular expression '$regEx' is invalid");
                throw $e;
            }
            $result = array($regEx, $replaceBy);
            break;
        }

        return $result;
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function createSimpleLinkBbCode($url)
    {
        return "[url]{$url}[/url]";
    }

    /**
     * @param string $url
     * @param string $body
     *
     * @return string
     */
    private function createFullLinkBbCode($url, $body)
    {
        return "[url=\"$url\"]{$body}[/url]";
    }

    /**
     * @param string $host
     *
     * @return null|string
     * @throws Exception
     */
    private function findMatchingHostRegEx($host)
    {
        $matchingHostRegEx = null;
        foreach ($this->hostRegExes as $hostRegEx) {
            try {
                if (!preg_match($hostRegEx, $host)) {
                    continue;
                }
            } catch (Exception $e) {
                $this->logger->addError("Regular expression '$hostRegEx' is invalid");
                throw $e;
            }

            $matchingHostRegEx = $hostRegEx;
            break;
        }

        return $matchingHostRegEx;
    }
}
