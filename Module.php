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
 * @copyright Copyright (c) 2019, Afterlogic Corp.
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
	 * @param string $sUrl
	 * @param array $mResult
	 */
	public function onCheckUrl($aArgs, &$mResult)
	{
		$iUserId = \Aurora\System\Api::getAuthenticatedUserId();
		
		if ($iUserId)
		{
			if (!empty($aArgs['Url']))
			{
				$oInfo = $this->getOembedFileInfo($aArgs['Url']);
				if ($oInfo)
				{
					$mResult['Size'] = isset($oInfo->fileSize) ? $oInfo->fileSize : '';
					$mResult['Name'] = isset($oInfo->title) ? $oInfo->title : '';
					$mResult['LinkType'] = 'oembeded';
					$mResult['Thumb'] = isset($oInfo->thumbnail_url) ? $oInfo->thumbnail_url : null;
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
		if ($oItem->IsLink)
		{
			$Result = $this->getOembedFileInfo($oItem->LinkUrl);
			
			if ($Result)
			{
				$oItem->LinkType = 'oembeded';
//				$oItem->Name = isset($Result->title) ? $Result->title : $oItem->Name;
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
	 * Returns Oembed information for file.
	 * 
	 * @param string $sUrl
	 * @return stdClass
	 */
	protected function getOembedFileInfo($sUrl)
	{
		$mResult = false;
		$sOembedUrl = '';
		
		foreach ($this->aProviders as $aProvider)
		{
			if (\preg_match("/".$aProvider['patterns']."/", $sUrl))
			{
				$sOembedUrl = $aProvider['url'].$sUrl;
				break;
			}
		}
		
		if (false !== \strpos($sUrl, 'instagram.com'))
		{
			$sUrl = \str_replace('instagram.com', 'instagr.am', $sUrl);
			$sOembedUrl = 'https://api.instagram.com/oembed?url='.$sUrl;
		}
		
		if (\strlen($sOembedUrl) > 0)
		{
			$oCurl = \curl_init();
			\curl_setopt_array($oCurl, array(
				CURLOPT_URL => $sOembedUrl,
				CURLOPT_HEADER => 0,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => 1,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_ENCODING => '',
				CURLOPT_AUTOREFERER => true,
				CURLOPT_SSL_VERIFYPEER => false, //required for https urls
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 5,
				CURLOPT_MAXREDIRS => 5
			));
			$sResult = \curl_exec($oCurl);
			\curl_close($oCurl);
			$oResult = \json_decode($sResult);
			
			if ($oResult)
			{
				$sSearch = $oResult->html;
				$aPatterns = array('/ width="\d+."/', '/ height="\d+."/', '/(src="[^\"]+)/');
				$aResults = array(' width="896"', ' height="504"', '$1?&autoplay=1&auto_play=true');
				$oResult->html =\preg_replace($aPatterns, $aResults, $sSearch);
				
				$aRemoteFileInfo = \Aurora\System\Utils::GetRemoteFileInfo($sUrl);
				$oResult->fileSize = $aRemoteFileInfo['size'];
				
				$oResult->thumbnailUrl = isset($oResult->thumbnail_url) ? $oResult->thumbnail_url : '';
				$mResult = $oResult;
			}
		}
		
		return $mResult;
	}
	
	/**
	 * Loads providers from file.
	 */
	protected function loadProviders()
	{
		$sFile = __DIR__.DIRECTORY_SEPARATOR.'providers.json';
		if (\file_exists($sFile))
		{
			$sJsonData = \file_get_contents($sFile);
			$aJsonData = \json_decode($sJsonData, true);
			foreach ($aJsonData as $aProvider)
			{
				$this->aProviders[$aProvider['title']] = array(
					'patterns' => $aProvider['url_re'],
					'url' => $aProvider['endpoint_url']
				);
			}
		}
	}
	/***** private functions *****/
}
