<?php

namespace Tahadudhiya\SmartLinks\web\assets\field;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The Smart Links field's link editor: adding, reordering, duplicating, copying and removing
 * links, and showing each link type's inputs.
 */
class SmartLinkFieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['field.css'];
        $this->js = ['field.js'];

        parent::init();
    }
}
