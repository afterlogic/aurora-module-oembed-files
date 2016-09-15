<?php

class OEmbedFilesModule extends AApiModule
{
	protected $aProviders = array();
	
	public function init() 
	{
		$this->loadProviders();
		
		$this->subscribeEvent('Files::GetLinkType', array($this, 'onGetLinkType'));
		$this->subscribeEvent('Files::CheckUrl', array($this, 'onCheckUrl'));
		$this->subscribeEvent('Files::PopulateFileItem', array($this, 'onPopulateFileItem'));
	}
	
	public function onPopulateFileItem(&$oItem)
	{
		if ($oItem->IsLink)
		{
			$Result = $this->GetOembedFileInfo($oItem->LinkUrl);
			if ($Result)
			{
				$oItem->LinkType = 'oembeded';
//				$oItem->Name = isset($Result->title) ? $Result->title : $oItem->Name;
				$oItem->Size = isset($Result->fileSize) ? $Result->fileSize : $oItem->Size;
				$oItem->OembedHtml = isset($Result->html) ? $Result->html : $oItem->OembedHtml;
				$oItem->Thumb = true;
				$oItem->ThumbnailLink = $Result->thumbnailLink;
				$oItem->IsExternal = true;
			}
			return !!$Result;
		}
	}			

	protected function GetOembedFileInfo($sUrl)
	{
		$mResult = false;
		$sOembedUrl = '';
		
		foreach ($this->aProviders as $aProvider)
		{
			if (preg_match("/".$aProvider['patterns']."/", $sUrl, $aMatches))
			{
				$sOembedUrl = $aProvider['url'].$sUrl;
				break;
			}
		}
		
		if (false !== strpos($sUrl, 'instagram.com'))
		{
			$sUrl = str_replace('instagram.com', 'instagr.am', $sUrl);
			$sOembedUrl = 'https://api.instagram.com/oembed?url='.$sUrl;
		}

		if (strlen($sOembedUrl) > 0)
		{
			$oCurl = curl_init();
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
			$sResult = curl_exec($oCurl);
			curl_close($oCurl);
			$oResult = json_decode($sResult);

			if ($oResult)
			{
				$sSearch = $oResult->html;
				$aPatterns = array('/ width="\d+."/', '/ height="\d+."/', '/(src="[^\"]+)/');
				$aResults = array(' width="896"', ' height="504"', '$1?&autoplay=1&auto_play=true');
				$oResult->html = preg_replace($aPatterns, $aResults, $sSearch);

				$aRemoteFileInfo = \api_Utils::GetRemoteFileInfo($sUrl);
				$oResult->fileSize = $aRemoteFileInfo['size'];

				$oResult->thumbnailLink = $oResult->thumbnail_url;
				$mResult = $oResult;
			}
		}

		return $mResult;
	}	
	
	public function onGetLinkType($Link, &$Result)
	{
		$Result = !!($this->GetOembedFileInfo($Link));
		return $Result;
	}	
	
	public function onCheckUrl($sUrl, &$mResult)
	{
		$iUserId = \CApi::getAuthenticatedUserId();

		if ($iUserId)
		{
			if (!empty($sUrl))
			{
				$oInfo = $this->GetOembedFileInfo($sUrl);
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
	
	protected function loadProviders()
	{
		$sFile = __DIR__.DIRECTORY_SEPARATOR.'providers.json';
		if (file_exists($sFile))
		{
			$sJsonData = file_get_contents($sFile);
			$aJsonData = json_decode($sJsonData, true);
			foreach ($aJsonData as $aProvider)
			{
				$this->aProviders[$aProvider['title']] = array(
					'patterns' => $aProvider['url_re'],
					'url' => $aProvider['endpoint_url']
				);
			}
		}
	}

}
