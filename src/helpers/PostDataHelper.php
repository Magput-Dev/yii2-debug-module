<?php

declare(strict_types=1);

namespace Magput\Debug\helpers;

use yii\helpers\Html;

class PostDataHelper
{
    private const PREVIEW_LENGTH = 300;

    /**
     * @param mixed $postData
     * @return string
     */
    public static function renderPreviewBlock($postData)
    {
        if (is_array($postData)) {
            $postData = JsonHelper::encode($postData, JSON_UNESCAPED_UNICODE);
        }

        if (!$postData || $postData === 'null') {
            return '<span class="not-set">(не задано)</span>';
        }

        $postData = (string)$postData;

        if (mb_strlen($postData) <= self::PREVIEW_LENGTH) {
            return Html::tag('div', Html::encode($postData), [
                'class' => 'json-block',
                'data-state' => 'expanded',
            ]);
        }

        return Html::tag('div', Html::encode(mb_substr($postData, 0, self::PREVIEW_LENGTH)) . '…', [
            'class' => 'json-block json-block--truncated',
            'data-state' => 'truncated',
            'data-full' => $postData,
            'title' => 'Нажмите, чтобы развернуть',
        ]);
    }
}
