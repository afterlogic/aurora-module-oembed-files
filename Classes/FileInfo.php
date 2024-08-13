<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\OEmbedFiles\Classes;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property string $title
 * @property string $html
 * @property string $fileSize
 * @property string $thumbnailUrl
 */
class FileInfo
{
    /**
     * @var string
     */
    public $title;
    /**
     * @var string
     */
    public $html;

    /**
     * @var string
     */
    public $fileSize;

    /**
     * @var string
     */
    public $thumbnailUrl;

    public function __construct()
    {
        $this->title = '';
        $this->html = '';
        $this->fileSize = '';
        $this->thumbnailUrl = '';
    }
}
