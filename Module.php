<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\OEmbedFiles;

/**
 * This module extends functionality of Files module.
 * It provides ability to add shortcuts based on [oembed](http://oembed.com/) data format.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected $aProviders = array();

    /***** private functions *****/
    /**
     * Initializes module.
     *
     * @ignore
     */
    public function init()
    {
        $this->loadProviders();

        $this->subscribeEvent('Files::GetLinkType', array($this, 'onGetLinkType'));
        $this->subscribeEvent('Files::CheckUrl', array($this, 'onCheckUrl'));
        $this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    /**
     * Returns **true** if oembed file info for specified link was found.
     *
     * @ignore
     * @param string $Link File link.
     * @param boolean $Result Is passed by reference.
     * @return boolean
     */
    public function onGetLinkType($Link, &$Result)
    {
        $Result = !!($this->getOembedFileInfo($Link));
        return $Result; // break or not executing of event handlers
    }

    /**
     * Writes to $mResult variable information about link.
     *
     * @ignore
     * @param array $aArgs
     * @param array $mResult
     */
    public function onCheckUrl($aArgs, &$mResult)
    {
        $iUserId = \Aurora\System\Api::getAuthenticatedUserId();

        if ($iUserId) {
            if (!empty($aArgs['Url'])) {
                $oInfo = $this->getOembedFileInfo($aArgs['Url']);
                if ($oInfo) {
                    $mResult['Size'] = isset($oInfo->fileSize) ? $oInfo->fileSize : '';
                    $mResult['Name'] = isset($oInfo->title) ? $oInfo->title : '';
                    $mResult['LinkType'] = 'oembeded';
                    $mResult['Thumb'] = isset($oInfo->thumbnailUrl) ? $oInfo->thumbnailUrl : null;
                }
            }
        }
    }

    /**
     * Populates file item.
     *
     * @ignore
     * @param \Aurora\Modules\Files\Classes\FileItem $oItem
     * @return boolean
     */
    public function onAfterPopulateFileItem($aArgs, &$oItem)
    {
        $bBreak = false;
        if ($oItem->IsLink) {
            $Result = $this->getOembedFileInfo($oItem->LinkUrl);

            if ($Result) {
                $oItem->LinkType = 'oembeded';
                $oItem->Name = isset($Result->title) ? $Result->title : $oItem->Name;
                $oItem->Size = isset($Result->fileSize) ? $Result->fileSize : $oItem->Size;
                $oItem->OembedHtml = isset($Result->html) ? $Result->html : $oItem->OembedHtml;
                $oItem->Thumb = true;
                $oItem->ThumbnailUrl = $Result->thumbnailUrl;
                $oItem->IsExternal = true;
            }
            $bBreak = !!$Result;
        }
        return $bBreak; // break or not executing of event handlers
    }

    /**
     * Resolves a URL's host to a single IP and validates it's safe to fetch: http(s) scheme
     * only, and a public (non-private, non-reserved) IP address. Used to reject a provider
     * redirect that points at an internal address (SSRF).
     *
     * Returns the resolved IP so the caller can pin curl to it (see pinCurlToResolvedHost())
     * instead of letting curl resolve the host again at connect time -- resolving twice would
     * let an attacker who controls the host's DNS answer safely for this check and then point
     * at an internal address for the actual request (DNS rebinding).
     *
     * @param string $sUrl
     * @return string|null The resolved IP, or null if the URL isn't safe to fetch.
     */
    protected function resolveSafeIp($sUrl)
    {
        $aParts = \parse_url((string) $sUrl);
        if (!isset($aParts['scheme'], $aParts['host']) || !\in_array(\strtolower($aParts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $sHost = $aParts['host'];
        if (\filter_var($sHost, FILTER_VALIDATE_IP)) {
            $sIp = $sHost;
        } else {
            $sIp = \gethostbyname($sHost);
            if ($sIp === $sHost) {
                // Could not resolve the host.
                return null;
            }
        }

        return \filter_var($sIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $sIp : null;
    }

    /**
     * Checks that a URL's host resolves to a public (non-private, non-reserved) IP address.
     * See resolveSafeIp().
     *
     * @param string $sUrl
     * @return bool
     */
    protected function isRemoteHostPublic($sUrl)
    {
        return $this->resolveSafeIp($sUrl) !== null;
    }

    /**
     * Pins a curl handle to $sIp for $sUrl's host via CURLOPT_RESOLVE, so curl connects to
     * exactly the address that was validated by resolveSafeIp() instead of resolving the host
     * again itself. The Host header, TLS SNI and certificate check still use the original
     * hostname, so this doesn't affect HTTPS validation.
     *
     * @param \CurlHandle|resource $oCurl
     * @param string $sUrl
     * @param string $sIp
     * @return void
     */
    protected function pinCurlToResolvedHost($oCurl, $sUrl, $sIp)
    {
        $aParts = \parse_url((string) $sUrl);
        $sHost = $aParts['host'] ?? '';
        $iPort = $aParts['port'] ?? (\strtolower($aParts['scheme'] ?? '') === 'https' ? 443 : 80);
        $sTarget = false !== \strpos($sIp, ':') ? '[' . $sIp . ']' : $sIp; // bracket IPv6 addresses

        \curl_setopt($oCurl, CURLOPT_RESOLVE, [$sHost . ':' . $iPort . ':' . $sTarget]);
    }

    /**
     * Fetches $sUrl, following redirects manually (up to 5 hops) instead of via
     * CURLOPT_FOLLOWLOCATION, so every hop -- including ones a provider's own redirect points
     * at -- is validated and pinned to its resolved IP *before* curl connects to it. With
     * FOLLOWLOCATION, curl would already have connected to an internal address before any
     * after-the-fact check on the final URL could reject it.
     *
     * @param string $sUrl
     * @return string|false The response body, or false if the URL (or a redirect target) isn't
     *                       safe to fetch, the request failed, or there were too many redirects.
     */
    protected function fetchUrlFollowingSafeRedirects($sUrl)
    {
        for ($i = 0; $i <= 5; $i++) {
            $sIp = $this->resolveSafeIp($sUrl);
            if ($sIp === null) {
                return false;
            }

            $oCurl = \curl_init();
            \curl_setopt_array($oCurl, array(
                CURLOPT_URL => $sUrl,
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ENCODING => '',
                CURLOPT_AUTOREFERER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 5,
            ));
            $this->pinCurlToResolvedHost($oCurl, $sUrl, $sIp);
            $sResponse = \curl_exec($oCurl);
            if (!\is_string($sResponse)) {
                return false;
            }

            $iCode = (int) \curl_getinfo($oCurl, CURLINFO_HTTP_CODE);
            $iHeaderSize = (int) \curl_getinfo($oCurl, CURLINFO_HEADER_SIZE);
            $sBody = \substr($sResponse, $iHeaderSize);

            if (!\in_array($iCode, [301, 302, 303, 307, 308], true)) {
                return $sBody;
            }

            if (!\preg_match('/^Location:\s*(\S+)/mi', \substr($sResponse, 0, $iHeaderSize), $aMatches)) {
                return false;
            }

            $sUrl = \Sabre\Uri\resolve($sUrl, \trim($aMatches[1]));
        }

        return false; // too many redirects
    }

    /**
     * Returns Oembed information for file.
     *
     * @param string $sUrl
     * @return \Aurora\Modules\OEmbedFiles\Classes\FileInfo
     */
    protected function getOembedFileInfo($sUrl)
    {
        $mResult = false;
        $sOembedUrl = '';

        foreach ($this->aProviders as $aProvider) {
            if (\preg_match("/" . $aProvider['patterns'] . "/", $sUrl)) {
                $sOembedUrl = $aProvider['url'] . $sUrl;
                break;
            }
        }

        if (false !== \strpos($sUrl, 'instagram.com')) {
            $sUrl = \str_replace('instagram.com', 'instagr.am', $sUrl);
            $sOembedUrl = 'https://api.instagram.com/oembed?url=' . $sUrl;
        }

        if (\strlen($sOembedUrl) > 0) {
            // Each redirect hop (a provider's own redirect could otherwise be used to reach an
            // internal address - SSRF) is validated and pinned to its resolved IP before it's
            // connected to, not just checked after the fact on the final URL.
            $sResult = $this->fetchUrlFollowingSafeRedirects($sOembedUrl);

            $oResult = \json_decode($sResult);

            if ($oResult) {
                $sSearch = $oResult->html;
                $aPatterns = array('/ width="\d+."/', '/ height="\d+."/', '/(src="[^\"]+)/');
                $aResults = array(' width="896"', ' height="504"', '$1?&autoplay=1&auto_play=true');
                $oResult->html = \preg_replace($aPatterns, $aResults, $sSearch);

                $aRemoteFileInfo = \Aurora\System\Utils::GetRemoteFileInfo($sUrl);
                $oResult->fileSize = $aRemoteFileInfo['size'];

                $oResult->thumbnailUrl = isset($oResult->thumbnail_url) ? $oResult->thumbnail_url : '';

                $mResult = new \Aurora\Modules\OEmbedFiles\Classes\FileInfo();
                $mResult->html = $oResult->html;
                $mResult->fileSize = $oResult->fileSize;
                $mResult->thumbnailUrl = $oResult->thumbnailUrl;
            }
        }

        return $mResult;
    }

    /**
     * Loads providers from file.
     */
    protected function loadProviders()
    {
        $sFile = __DIR__ . DIRECTORY_SEPARATOR . 'providers.json';
        if (\file_exists($sFile)) {
            $sJsonData = \file_get_contents($sFile);
            $aJsonData = \json_decode($sJsonData, true);
            foreach ($aJsonData as $aProvider) {
                $this->aProviders[$aProvider['title']] = array(
                    'patterns' => $aProvider['url_re'],
                    'url' => $aProvider['endpoint_url']
                );
            }
        }
    }
    /***** private functions *****/
}
